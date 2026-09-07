# 0005 — Gate the Payments projection with a connection-affine advisory lock, and prove rebuild ownership by lock possession

- **Status** — Accepted.
- **Date** — 2026-09-03.
- **Decision owner** — AB.
- **Implemented by** - T11 Slices 1-5: control row, traffic gate, rebuild ownership, replay, recovery, and T10 command participation.
  The [operator procedure](../operations/runtime-contract.md#manual-rebuild-and-recovery) and [evidence table](../specifications/payments/verification-and-rebuild.md#t11-implementation-evidence) record the handoff and measured limits.

## Context

`payment_details` and its projector receipts are derived state that a controlled rebuild must be able
to clear and reconstruct from canonical `stored_events`. A rebuild is destructive by definition, so
the system needs an answer to two questions that nothing in the repository can currently answer:

1. **How is traffic kept out of a projection that is being rebuilt?** T7's relay, T8's consumer, T9's
   query adapter, and T6's write path all run today with no gate at all. That absence is recorded as
   HS-1 in [ADR 0004](0004-lock-through-push-outbox-relay.md) and in
   [outbox delivery](../specifications/payments/outbox-delivery.md#hs-1-discharged-by-t11-slice-2),
   as temporary scope rather than a permanent contract. Without a gate, a rebuild started by hand
   today would race an active relay pass with nothing to stop it, and `GET` would answer `404` for
   payments that exist.
2. **How does a rebuild know it is still the owner?** T4 measured (Q8) that neither a successful query
   nor a non-zero `transactionLevel()` proves a pinned session survived: outside a transaction Laravel
   silently reconnects onto a **new backend** and the caller sees success with the advisory lock gone,
   and inside a transaction the loss surfaces while `transactionLevel()` still reports `1` because
   `reconnect()` and `disconnect()` never touch the counter. A rebuild that infers ownership from
   either is unsafe.

T4 also proved (Q6, Q7) that a transaction-scoped shared advisory lock and the work it protects report
the same backend pid to a third-party observer, that shared holders coexist across distinct backends,
that an exclusive contender is refused immediately by a try-lock, and that a bounded `lock_timeout`
turns a blocking wait into a real `55P03`, which is already on the Payments retry allowlist.

## Decision

### The gate is a durable control row plus a connection-affine advisory lock, and both are checked

`payment_projection_controls` holds one row per projection with `ready` or `rebuilding`, an owner
token, an owner backend pid, a start time, and `cleared_at`. Payments `POST`, the transactional `GET`,
the relay, and the consumer each acquire a **transaction-scoped shared** advisory lock and read the
control row, on the same explicit connection they already hold, inside the transaction they already
open. A rebuild holds a **session-level exclusive** lock on one pinned connection.

Both checks are required and the gate fails closed on either. The lock alone misses a rebuild whose
session is gone, because PostgreSQL releases a session-level lock when the backend goes away while the
read model stays half-cleared. The row alone misses the window before a rebuild has written it and
cannot serialize concurrent work. A missing row is closed, never `ready`.

The key is a pair of explicit integer constants, one object identifier per projection name, in the
two-integer `pg_advisory_*` form so `pg_locks` reports the halves in `classid`/`objid` and evidence can
assert exact values.

### Traffic never blocks on the gate

Every traffic path uses the non-blocking `pg_try_advisory_xact_lock_shared`, so a closed gate is an
immediate, definite answer rather than a wait. HTTP answers a retryable `503 projection_rebuilding`;
the relay and consumer defer.

### A deferral is neither a failure nor an exception

A closed gate produces a distinct deferral outcome, reported separately from a drained backlog. The
consumer's deferral raises **no exception**, because `ProjectPaymentEvent` has no `try`/`catch` and the
`payments-projection` connection allows one attempt, so an exception would retire the message into
`failed_jobs` rather than defer it. A deferred delivery locks no envelope and a deferred relay pass
selects no row, so no failure counter, generation, exhaustion timestamp, availability, or transport
column is touched. "Gate deferral consumes no budget" is therefore structural, not a rule someone must
remember. Deferred work returns through T7's existing reconciliation grace; no second redelivery owner
is introduced.

### The rebuild acquires the lock before it claims ownership

The rebuild acquires the session-level exclusive lock **first**, bounded by `lock_timeout`, and only
then marks the control row `rebuilding` under a fresh owner token in a short `FOR UPDATE` transaction.
This inverts the step order the specification originally recorded, and the inversion is the decision.

Marking first cannot be made atomic against a concurrent rebuild by marking alone: a second run would
overwrite the first run's owner token, and the ownership fence would then abort the winner rather than
the loser. A second run that finds `rebuilding` also cannot tell a live owner from a lost one, because
a lost owner leaves exactly the row a live one does. The lock is the only thing that adds information
the durable row cannot, so ownership is decided after it is held. Traffic is refused for the whole
window regardless, because the exclusive lock closes the gate from the first step.

### Acquiring the lock proves no session holds the gate, not that a process died

This ADR deliberately makes the weaker claim. A rebuild process can lose its database session and keep
running: an administrator terminates the backend, a partition drops the connection, a pooler restarts,
or PostgreSQL restarts, while the process is healthy and mid-replay.

The safety property is that **a predecessor is permanently write-fenced**, not that it is dead. A
takeover is safe because the predecessor cannot write, not because it cannot run.

### Ownership is fenced by lock possession, checked at three points

Before the destructive clear, at the start of every batch transaction, and inside the transaction that
sets `ready`, the rebuild asserts both that the control row still reads `rebuilding` under this run's
owner token, and that **this backend still holds the exclusive gate lock**, read from `pg_locks`
filtered by the advisory locktype, the gate key, exclusive mode, granted, the current database OID, and
the current backend pid.

The second assertion is what a token-only fence misses. If a session is lost and no takeover has
happened yet, the owner token still matches the running process's own token and the state still reads
`rebuilding`, so a token-only fence passes while that process writes on a session holding no lock and
another run is free to take the gate. Ordering does not rescue it: if a successor takes over first both
assertions fail, but if the fenced run checks first only the lock assertion catches it.

Lock possession is asserted directly rather than by comparing a stored backend pid, because a stored
pid is a proxy for the same question and is defeated by pid reuse. The stored owner pid is retained as
operator diagnostics and carries no safety weight.

### A lost `COMMIT` response is not a rollback

A batch, a clear, or a state transition whose commit outcome is unknown may be durable. The rebuild
reports an unknown outcome rather than a clean abort, as targeted repair already does through
`mutation_state`. Recovery does not depend on resolving that uncertainty, because the clear and its
`cleared_at` marker commit in one transaction: either both landed or neither did.

### `cleared_at` records the projection's condition, not a run's history

`cleared_at` means "the projection has been destructively cleared and no replay has completed since".
It is set immediately before the clear, in that transaction, only when still null, and set back to null
only by the transition to `ready` after a complete replay, in that same transaction. Every other path
leaves it untouched, including a takeover and a run that fails before its own clear. Guarded recovery
may return to `ready` without replay only when the row proves no clear was committed.

### A rebuild does not re-create outbox envelopes

It clears and recreates `payment_details` and the named projector's receipts, reconciles
`payment_outbox.projection_completed_at` for receipts it recreated, and touches nothing else. Envelope
reconstruction stays `payments:outbox:repair`, one envelope at a time. This answers the question
[outbox delivery](../specifications/payments/outbox-delivery.md#envelope-and-active-index) explicitly
left to T11.

Replay derives read rows from canonical `stored_events`, never from `payment_outbox`, because the
outbox is derived state that verification exists to detect corruption in and rebuilding from it would
launder that corruption into the read model. The aggregate, Spatie replay, and the outbox writer are
never invoked as a shortcut.

## Alternatives considered

- **A hashed advisory key derived from the projection name.** Rejected: it buys extensibility nothing
  needs yet and turns a collision with an unrelated advisory key into a silent event rather than a
  reviewable one. Adding a projection later is a one-line reviewed constant.
- **Blocking shared acquisition on traffic paths.** Rejected: it lets a rebuild turn every request,
  relay pass, and delivery into a timeout. A try-lock gives an immediate, definite answer.
- **The control row alone, with no advisory lock.** Rejected: it cannot serialize concurrent work and
  cannot distinguish a live owner from a lost one.
- **The advisory lock alone, with no durable row.** Rejected: a lost session releases the lock while the
  read model is still half-cleared, and the gate would silently reopen over partial data.
- **Marking `rebuilding` before acquiring the lock**, as the specification originally ordered.
  Rejected for the reasons under **Decision**; the deviation is recorded in
  [verification and rebuild](../specifications/payments/verification-and-rebuild.md#rebuild-algorithm)
  rather than left as a silent disagreement between document and code.
- **A token-only ownership fence.** Rejected: it passes for a session that lost its lock before any
  takeover, which is the single case most likely to produce two concurrent writers.
- **Comparing a stored backend pid instead of reading `pg_locks`.** Rejected: a proxy for the question,
  defeated by pid reuse.
- **Re-acquiring the lock with `pg_try_advisory_lock` as the fence check.** Rejected: advisory locks are
  re-entrant per session, so a re-acquisition succeeds and increments a counter that would have to be
  unwound. A side-effect-free read is the right primitive.
- **Releasing a deferred job with `release()`.** Rejected: `ProjectPaymentEvent` implements no queue
  interface, adding one changes a wire contract that must stay deserializable across deploys, and one
  attempt would make a release the last one anyway.
- **A three-value rebuild phase enum instead of `cleared_at`.** Rejected: it carries no decision the
  nullable timestamp does not and invites a fourth value.
- **Resumable checkpoints or a staging-table swap.** Deferred, not rejected. `TODOS.md` owns it behind
  the measured trigger recorded in
  [verification and rebuild](../specifications/payments/verification-and-rebuild.md#deferred-evolution).

## Consequences

- HS-1 in ADR 0004 is discharged by T11 Slice 2's traffic integration; the relay and consumer now acquire the shared gate.
- `503 projection_rebuilding` becomes reachable, adding a second retryable `503` to the write
  vocabulary beside `idempotency_contention`.
- Payments `POST` is gated even though a rebuild clears nothing `POST` writes. The alternative leaves a
  merchant holding a `201` whose `GET` immediately answers `503`, which is a worse contract than
  refusing the write.
- Jobs published before a rebuild are consumed and discarded at the queue's drain rate while it runs.
  The durable outbox is the source of truth and Redis is never authoritative.
- Marker reconciliation is the one place a rebuild writes to a table it does not own. Without it a
  successful rebuild would be immediately followed by verification reporting the recreated receipt as a
  receipt without its marker.
- Verification and repair participate in the gate: verification under a closed gate is an incomplete
  report with exit `2`, never a corruption finding; repair is a refusal. `--delivery-quiesced` stays,
  because the gate does not solve the `projection_failure_generation` fence problem it exists for.
- The consumer's deterministic derivation is refactored to be fed from canonical history as well as
  from an outbox row. Its observable behaviour must be unchanged, pinned by the existing T8 suite.
- T11 Slice 1 measured the interaction T4 left open: a shared try-lock is refused while an exclusive
  request is already queued on the same key, witnessed through an observer connection.
  The [Slice 1 evidence](../specifications/payments/verification-and-rebuild.md#t11-slice-1-implementation-evidence)
  records the owning test and validation result; it does not establish general fairness or production availability.
- No index, package, or transport is introduced. No claim of production-scale rebuild duration,
  throughput, or availability follows from this decision.

## Related

- [Verification and rebuild](../specifications/payments/verification-and-rebuild.md) — the canonical
  gate, rebuild, and recovery contracts this ADR decides the mechanism for.
- [Projection and query](../specifications/payments/projection-and-query.md) — the consumer transaction
  and the `503` branch of the query contract.
- [Outbox delivery](../specifications/payments/outbox-delivery.md) — HS-1, and the envelope question
  this ADR answers.
- [ADR 0004](0004-lock-through-push-outbox-relay.md) — the relay decision that deferred this gate.
- [ADR 0003](0003-postgresql-idempotency-arbitration.md) — the SQLSTATE retry allowlist and
  commit-boundary discipline this gate reuses rather than reinvents.
- [Resilience test plan](../testing/payments-resilience.md) — the rebuild evidence this design owes.
