# 0004 — Publish the payment outbox with a lock-through-push relay, without a shared projection gate

- **Status** — Accepted.
- **Date** — 2026-08-25.
- **Decision owner** — AB.
- **Implemented by** — T7.

## Context

T6 commits `payment_outbox` rows atomically with the idempotency receipt and the stored event, but
nothing publishes them: `payment_outbox` is durable intent, not delivery. T4's spike proved the
underlying primitives — immediate `push()` inside an open PostgreSQL transaction, `SKIP LOCKED`
progress, and a safe duplicate on crash — against a three-column surrogate table and a spike
job/queue, with no production command, schema, or Redis connection. T7's task is to turn that proof
into the first production writer of transport delivery state.

The initial relay algorithm recorded in [outbox delivery](../specifications/payments/outbox-delivery.md)
opened with "acquire the Payments projection shared gate" as its first step. That gate —
`payment_projection_controls`, a durable ready/rebuilding row, and the connection-affine advisory
lock in front of it — is T11's design, and T11 has not run. Building a provisional gate now would
mean inventing a control table and an advisory-key derivation that T11 would then have to either
keep or discard, coupling two milestones that a CEO scope-reduction review found no reason to couple.

## Decision

**One row, one short PostgreSQL transaction, lock through push, no shared gate.**
`OutboxRelay::relayOne()`:

1. `BEGIN`, then `SET LOCAL statement_timeout` bounding how long this transaction may hold a row
   lock.
2. `SELECT … FOR UPDATE SKIP LOCKED LIMIT 1`, ordered `(available_at, event_id)` — the same order
   `payment_outbox_eligible_index` serves — against `projection_completed_at IS NULL AND
   exhausted_at IS NULL AND available_at <= now()`.
3. `push(ProjectPaymentEvent($eventId))` on the named `payments-projection` queue connection,
   **inside** the open transaction, with `after_commit => false` and no after-commit opt-in on the
   job — so a crash between push and commit produces a safe duplicate rather than a lost message.
4. On `RedisException`: commit a stable rejection category and capped exponential backoff.
   On acceptance: commit incremented `publish_count`, `last_published_at`, cleared failure state, and
   `available_at = now() + 30s` grace. On every other `Throwable`: roll back and abort the whole pass
   — Laravel can retain stale transaction bookkeeping after a connection loss, so no second row is
   attempted on a connection nobody can describe.

   `RedisException` is the **complete** transport surface of this path, not a first approximation.
   Every type the resolved push path can raise, and the four that provably cannot occur — including
   `RedisClusterException`, which extends `RuntimeException` rather than `RedisException` and so
   would abort rather than back off if a cluster connection were ever introduced — are recorded in
   [transport exception classification](../specifications/payments/outbox-delivery.md#transport-exception-classification--verified-against-the-resolved-stack).

   **Redis can accept the message and still throw, and step 4 above is already the correct answer.**
   Measured: the server applies both `rpush` calls, the client then throws on the reply. The relay
   takes the rejection path, which **commits** — it is not a rollback: `consecutive_transport_failures`
   increments, `last_transport_error` is set, `available_at` moves forward by the transport backoff,
   and `publish_count` and `last_published_at` are deliberately left untouched. When the backoff
   expires the row is eligible again and is republished, so the already-accepted message is delivered
   twice. A safe duplicate, never a loss. This is a different mechanism from the crash-before-commit
   case in step 3 — that one is a genuine PostgreSQL rollback leaving the row immediately eligible —
   and the two reach the same ceiling by different routes.

   **One residual risk this decision does not close, and it stays with T7.** phpredis returns `false`
   rather than throwing for `ERR`/`NOSCRIPT`/`WRONGTYPE` replies, and `RedisQueue::pushRaw()` discards
   `eval()`'s return value, so such a reply is invisible to the relay. The result is a false-positive
   publication acknowledgement: the acceptance path runs for a message that was never enqueued,
   advancing `publish_count` and `last_published_at`, clearing transport-failure state, and deferring
   the row by `reconciliation_grace_seconds` instead of by a backoff. **No consumer is needed to
   detect this**, and it is not T8's: the row simply becomes eligible again when the grace lapses with
   no `projection_completed_at`, and T7 republishes it — the same reconciliation loop that already
   covers a consumer which never ran. A persistent cause repeats once per grace cycle until the Redis
   key is repaired, and is not distinguishable on the health surface during the grace window. Unlikely
   by construction (a dedicated logical database with explicit key ownership), and not permanent
   message loss. T8 owns projection completion and consumer outcomes, not the detection of a Redis job
   that was never enqueued.
5. `COMMIT`.

`RelayPaymentOutboxCommand` (`payments:outbox:relay`) owns the pass: loop until drained, a 50-row
batch cap, or a 750 ms start-budget checked **before** each next row — never a kill deadline, so a
row already begun always finishes committing or rolling back. `PaymentOutboxStatusCommand`
(`payments:outbox:status`) is a read-only JSON health surface combining `RelayHeartbeat` (five states:
`fresh`, `stale`, `missing`, `unavailable`, `invalid`) with one snapshot-consistent PostgreSQL
aggregate. `Schedule::command('payments:outbox:relay')->everySecond()` is registered in
`routes/console.php`; the opt-in `scheduler` Compose service (profile `relay`, `command: php artisan
schedule:work`) stays out of every clean `install`/`start` and is proven only by a disposable Compose
project.

**Eight production types, no more:** `RelayConfiguration`, `TransportBackoff`, `ProjectPaymentEvent`,
`RelayOutcome`, `OutboxRelay`, `RelayHeartbeat`, `RelayPaymentOutboxCommand`,
`PaymentOutboxStatusCommand`.

### HS-1 — the shared projection gate is deliberately omitted here

T7 does not acquire, simulate, or stub the Payments projection gate. No advisory key is derived, no
`payment_projection_controls` row is read, and no zero-advisory-lock claim is made — absence here is
temporary scope, not a permanent contract. **T11 adds the gate as the first statement inside
`OutboxRelay::relayOne()`, before eligibility selection, on the same injected connection**, plus the
consumer-side deferral semantics for the rebuild it will introduce. See
[verification and rebuild](../specifications/payments/verification-and-rebuild.md#projection-gate)
for the reciprocal obligation this creates.

### Why not a Redis-native queue mutex instead of row locks

Scheduler uniqueness (`withoutOverlapping()`/`onOneServer()`) was deliberately **not** added to the
schedule registration. `SKIP LOCKED` already makes overlapping relay invocations safe — a second pass
steps over a locked row instead of queueing behind it — so a scheduler-level mutex would be a second,
redundant owner of the same guarantee, and its absence is asserted by a runtime-contract test rather
than left implicit.

## Alternatives considered

- **Acquire a provisional projection gate now.** Rejected: the gate's schema, advisory-key derivation
  and deferral semantics belong to T11, and a provisional version invented here would either survive
  as permanent scope creep or be thrown away, in both cases coupling two milestones for no present
  benefit. Recorded as HS-1 above rather than silently deferred.
- **Claim/lease publishing** (reserve a row, publish outside the transaction, finalize with a
  token). Rejected for this slice: it is strictly more complex than lock-through-push and buys
  nothing until a measured failure attributes real cost to holding the connection during the Redis
  round trip. The evolution gate and its three trigger conditions are recorded in
  [outbox delivery](../specifications/payments/outbox-delivery.md#evolution-gate-claimlease-publishing).
- **`pushRaw()` instead of `push()`.** Rejected: `pushRaw()` skips `Queue::enqueueUsing()` entirely and
  is unconditionally immediate, so it cannot participate in — or be tested against — the after-commit
  question at all.
- **A generic transport abstraction over Redis.** Rejected: nothing else implements it, and Redis is
  the only transport this milestone uses; an interface with one implementation is schema by
  anticipation, the same objection ADR 0002 and T5 already applied to Payments `Domain`.
- **A custom consumer exception type for `ProjectPaymentEvent::handle()`.** Rejected: until T8 exists
  to catch anything specific, a plain `LogicException` says "not implemented yet" exactly as loudly
  and adds no vocabulary a caller could act on.

## Measured evidence

Proved against real PostgreSQL 18.4 and Redis 8.10.0 (phpredis 6.3.0) in `ledgerflow_test`, and — for
the scheduler chain only — against a disposable Compose project, on 2026-08-25.

| # | Invariant | Proving test | Observed mechanism | Status |
|---|---|---|---|---|
| M-1 | The Redis queue key phpredis actually writes matches the one both read paths derive, prefix included | `PaymentRelayRuntimeContractTest::it addresses one physical key from both read paths, and records the prefix phpredis applies` | A real `push()` on the named `payments-projection` connection; `size()` (Lua `eval`) and `pendingSize()` (`llen`) agree at 1; the physical key exists only under the client's own prefix, never bare | **PASS** |
| M-2 | `SKIP LOCKED`, not a bare row lock, is what the relay compiles and runs | `OutboxRelayTest::it skips a row another session holds and publishes the next one instead`, `…it compiles the eligibility query with SKIP LOCKED rather than a bare row lock` | A second connection holds `FOR UPDATE` on the oldest row; the relay selects and publishes the *other* row with no sleep and no `55P03`; the compiled SQL is pinned to end with `for update skip locked` | **PASS** |
| M-3 | PostgreSQL accepts a negative counter before the forward migration, and refuses it after | `PaymentsSchemaTest::it refuses a negative publish_count and a negative consecutive_transport_failures`, `…it reports 23514 naming the violated transport constraint` | Both named CHECK constraints (`payment_outbox_publish_count_non_negative`,
`payment_outbox_consecutive_transport_failures_non_negative`) exist, fit PostgreSQL's 63-byte
identifier limit, and a direct `UPDATE … = -1` now raises `23514` naming the violated constraint | **PASS** |
| — | Publication happens before `COMMIT`; a crash produces a safe duplicate | `OutboxRelayTest::it restores the exact pre-attempt row after a crash between the accepted push and the commit` | A `TransactionCommitting` failure injection leaves Redis holding the message and PostgreSQL rolled back to the exact pre-attempt row; a later pass republishes the same event id, producing two Redis messages carrying one identifier | **PASS** |
| — | An ambiguous commit outcome is reported as such, never guessed | `OutboxRelayTest::it reports an ambiguous commit outcome instead of claiming the work rolled back` | A `TransactionCommitted` failure injection (fires *after* `getPdo()->commit()` succeeds) is classified `commit_outcome_unknown`, aborts non-zero, and does **not** republish in the same pass; the next pass reads canonical row state | **PASS** |
| — | The disposable scheduler reaches healthy with a fresh heartbeat, and `stop`/`destroy` reach it too | Manual disposable-project run under `COMPOSE_PROJECT_NAME=ledgerflow-t7-relay-contract` (not a Pest test — see the non-claim below) | `relay-start` → `docker compose … --wait` returns 0; live `payments:outbox:status` inside the `scheduler` container reports `healthy:true`, `heartbeat.state:fresh`, `age_seconds:0`; `relay-stop`, plain `stop`, and confirmed `destroy` each stop/remove the `scheduler` container; `destroy` leaves 0 containers, 0 volumes, 0 networks for the disposable project | **PASS** |

**A real finding, not a tuning guess.** The first disposable-project run failed `docker compose up
--wait` at a 20 s `start_period`: Laravel's `ScheduleWorkCommand::work()` spawns a fresh
`schedule:run` subprocess only at `second === 0` of each minute — sub-second cadence is handled
*inside* that per-minute subprocess, not by `schedule:work` itself — so a freshly started container
can wait up to ~60 real seconds for its first tick, independent of anything this project controls.
`redis-data` also persists across restarts, so a restarted scheduler correctly reports its own
*previous* run's heartbeat as `stale` (not `missing`) during that wait — the five-state design working
exactly as intended, just not one the original grace window could tolerate. `start_period: 75s` was
measured, not assumed, against a worst case of ~62 real seconds; every tick after the first is ~1 s
apart, so this margin is for the one cold first tick only.

## Consequences

- The relay depends on nothing T11 has not yet built. Reverting T11 would leave T7's relay exactly as
  it runs today; landing T11 requires editing exactly one method (`OutboxRelay::relayOne()`) to add
  one statement before eligibility selection.
- No production code claims zero advisory locks are ever taken during relay — HS-1 records that as
  temporary, not permanent, so a later reader must not treat this ADR as forbidding T11's gate.
- **HS-1 now has an approved design to point at.**
  [ADR 0005](0005-payments-projection-gate-and-rebuild-ownership.md) records the control row, the
  advisory-key derivation, the non-blocking traffic acquisition, and the deferral semantics. It is
  **not implemented**, so the relay still runs with no gate; HS-1 is discharged by T11's gate slice,
  not by that ADR existing. The one-statement insertion point described above is unchanged.
- `redis-data` persistence across scheduler restarts is now a documented operational fact, not an
  edge case a future incident rediscovers: a restarted scheduler is briefly `stale`, never falsely
  `fresh`, before its own next tick.
- The relay is dormant in every normal deployment: `schedule:work` never runs outside the `relay`
  Compose profile; T8 has since replaced that with the paired `projection` profile, which activates
  the scheduler and its Horizon-supervised consumer together. A
  long-lived producer without its consumer would otherwise republish every accepted row on an
  unbounded 30-second cycle.
- `40P01` and `40001` remain allowlisted and unit-classified for the relay's own failure path, exactly
  as they have since T3; multi-process relay concurrency beyond the two-connection `SKIP LOCKED` proof
  above is T12's.

## Related

- [Outbox delivery](../specifications/payments/outbox-delivery.md) — the canonical specification this
  ADR decides the mechanism for, including the full state-transition table and queue transport policy.
- [Verification and rebuild](../specifications/payments/verification-and-rebuild.md) — T11's reciprocal
  obligation to add the shared gate this ADR omits.
- [ADR 0005](0005-payments-projection-gate-and-rebuild-ownership.md) — the gate design that discharges
  HS-1 once T11 implements it.
- [Development environment](../specifications/foundation/development-environment.md) — what T7 added,
  the disposable-project environment limitation, and the non-claims this ADR does not restate.
- [ADR 0003](0003-postgresql-idempotency-arbitration.md) — the retry-allowlist and commit-boundary
  discipline this relay reuses rather than reinvents.
