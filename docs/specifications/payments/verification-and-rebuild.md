# Projection Verification and Rebuild Specification

## Given

`stored_events` is immutable business history. `payment_details`, projector receipts, and outbox
completion markers are derived state that can drift because of a bug, operator action, or an
interrupted process.

## Problem

The application cannot infer corruption merely from elapsed time: a missing projection may still
be valid queue lag. Rebuilding automatically after every discrepancy can destroy evidence, expose
partial data, or repeat external side effects.

## Decision

Verification is read-only and distinguishes in-flight work from confirmed inconsistency. Repair
and rebuild are explicit operator actions. A durable Payments gate plus PostgreSQL advisory locks
prevents traffic from observing or modifying a partial rebuild.

## Verification

`payments:projections:verify`:

1. Captures a safe maximum global stored-event ID.
2. Scans Payments events through that watermark in bounded cursor batches.
3. Verifies the projector receipt and `PaymentDetails` source event, merchant, amount, currency,
   and status.
4. Reports receipts without rows, rows without source events, wrong source events, field mismatch,
   and disagreement with `projection_completed_at`.
5. Verifies every stored event has one canonical outbox envelope and reports missing, orphaned, or
   mismatched envelopes.
6. Classifies events newer than delivery grace as in-flight rather than corrupt.
7. Emits a structured summary and non-zero exit code for confirmed inconsistency.

It never mutates data or starts rebuild automatically. It may run manually or later on a schedule
after deployment monitoring and an alert destination exist.

## Targeted outbox repair

`payments:outbox:repair {eventId}` is explicit and audited. It locks the identity, loads and
validates the canonical stored event, and reconstructs only its derived envelope. An existing
projector receipt produces a completed marker; otherwise the row becomes eligible.

The command refuses an orphan envelope without a canonical event and never creates or changes an
idempotency receipt. Bulk repair is separate reviewed scope.

## Projection gate

`payment_projection_controls` stores one row per projection with `ready` or `rebuilding`, owner
token, start time, and timestamps. The row is durable operator-visible state; advisory locks own
mutual exclusion.

- Payments POST, transactional GET, relay, and consumer acquire a transaction-scoped shared lock
  and read the control row on the same explicit connection.
- Rebuild holds a session-level exclusive lock on one pinned connection.
- While rebuilding, Payments HTTP returns retryable `503`; relay/consumer work is deferred without
  consuming delivery budgets. Health and unrelated modules remain available.
- Losing or reconnecting the pinned session aborts the rebuild. Code never assumes the old lock
  survived.

### T7's reciprocal obligation: the gate does not exist yet in the relay

**T11 owns adding it.** T7 shipped `OutboxRelay::relayOne()` — one row, one short transaction,
`SKIP LOCKED`, publish before commit — without acquiring any shared lock or reading
`payment_projection_controls`, because that table, its advisory-key derivation, and this gate's
deferral semantics are this task's design, not T7's. Recorded as HS-1 in
[outbox delivery](outbox-delivery.md#hs-1-no-shared-projection-gate-is-acquired) and in
[ADR 0004](../../adr/0004-lock-through-push-outbox-relay.md).

Concretely, T11 must add gate acquisition as the **first statement** inside `relayOne()`, before
eligibility selection, on the same injected `Connection` the relay already holds — not a second
connection, and not a call wrapped around the existing method, because the shared lock and the row
lock it protects must report the same backend pid the way Q6's evidence already requires. T11 also
adds the deferral semantics on the consumer side of this same design. Until T11 lands, the relay
runs with **no** gate: a rebuild started by hand today would race an active relay pass with nothing
to stop it, which is exactly the gap this section exists to close.

### Compatibility evidence for this gate

T4 measured the two clauses above against PostgreSQL 18.4 and Laravel 13.24.0. The result table lives
in [outbox delivery](outbox-delivery.md#blocking-compatibility-evidence) (Q6 for connection affinity,
Q8 for session loss); only what T11 must design around is repeated here.

**Affinity is provable.** A transaction-scoped shared lock and its protected query report the same
backend pid in `pg_locks`, witnessed from a third connection rather than self-reported. Shared holders
coexist across distinct backends, an exclusive contender is refused immediately, and a bounded
`lock_timeout` turns a blocking wait into a real `55P03` — the SQLSTATE already on the Payments retry
allowlist.

**Session loss behaves differently either side of a transaction, and both are dangerous:**

- **Outside a transaction, Laravel reconnects silently.** `Connection::run()` catches the failure,
  `handleQueryException()` rethrows only when `transactions >= 1`, and otherwise
  `tryAgainIfCausedByLostConnection()` reconnects and re-runs
  (`Database/Connection.php:799-828,1010-1040`). Measured: the caller saw a **successful query on a
  new backend pid**, and the advisory lock was gone.
- **Inside a transaction, the loss surfaces** as a `QueryException` — but
  **`transactionLevel()` still reported `1`**. `reconnect()` swaps only the PDO and never touches the
  counter (`Database/DatabaseManager.php:333-345,374-385`), and `disconnect()` leaves it too
  (`Database/Connection.php:1064-1081`).

**Therefore, for the rebuild: neither a successful query nor a non-zero `transactionLevel()` is
evidence that the pinned session, or the advisory lock it took, survived.** Both can be true while the
lock is already held by somebody else. A rebuild that infers ownership from either is unsafe, and the
abort path needs a positive check — re-reading the durable control row on a known-live connection, a
fenced token, or a refusal to reconnect at all. T11 owns that choice; T4 only proves the naive
inference is wrong.

## Rebuild algorithm

1. Atomically mark the projection `rebuilding` with a unique owner token.
2. Acquire the exclusive advisory lock, waiting for prior shared transactions to finish.
3. Capture the maximum global stored-event ID.
4. Clear only `PaymentDetails` and its projector receipts.
5. Replay Payments events through the watermark in fixed configurable batches using the same
   deterministic application service as the consumer.
6. Recreate projector receipts and commit each batch while the gate remains closed.
7. Mark `ready` only after complete success, release the lock, and drain stale queued duplicates.

Lock timeout aborts before clearing data. Handled error or process death leaves the durable row as
`rebuilding`, even though PostgreSQL releases the session lock. An authorized next run reacquires
the exclusive lock, clears partial derived state, and restarts from zero.

Guarded recovery may set `ready` without replay only if it proves no clear or partial replay began.
Otherwise a successful rebuild is mandatory.

## Deferred evolution

Resumable checkpoints and a staging-table swap are required only when measured event volume,
rebuild duration, or restart cost violates an availability target. That future design must cover
staging lifecycle, events arriving during replay, final catch-up, atomic swap, cleanup, and crash
recovery at every transition.

## Acceptance criteria

- Consistent state passes; injected corruption fails without mutation.
- Legitimate delivery lag is not mislabeled as corruption.
- Targeted repair derives one outbox envelope and cannot synthesize business or HTTP truth.
- Rebuild waits for already-running consumers before clearing.
- Failed or killed rebuild never exposes partial state as ready.
- Replay uses bounded batches and recreates equal canonical business columns.
- Stale queued duplicates after rebuild are harmless.

## Related

- [Projection and query](projection-and-query.md)
- [Outbox delivery](outbox-delivery.md) — HS-1, the deviation this gate obligation answers.
- [ADR 0004](../../adr/0004-lock-through-push-outbox-relay.md) — the relay decision record that defers
  gate acquisition to this task.
- [Resilience test plan](../../testing/payments-resilience.md)
