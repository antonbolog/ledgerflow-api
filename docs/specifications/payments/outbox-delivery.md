# Payments Outbox Delivery Specification

## Given

`PaymentInitiated` and its Payments outbox envelope are committed atomically in PostgreSQL. Queue
delivery is asynchronous and at-least-once.

## Problem

The application can crash before publication, after a broker accepts a message, or while a
consumer is processing it. A transport outage is not the same failure as a deterministic poison
message. Treating publication as completion can lose projections; retrying blindly can create
duplicates or exhaust healthy work.

## Decision

The outbox remains durable history. A short-lived scheduler-driven relay publishes an event ID,
and the consumer loads the canonical envelope from PostgreSQL. A named projector receipt is the
authoritative completion signal; `projection_completed_at` is only a denormalized active-index
marker.

## Envelope and active index

`payment_outbox` contains:

- event/message UUID as the primary key;
- event type and schema version;
- aggregate UUID and version;
- JSON payload and `occurred_at`;
- `available_at`, nullable `last_published_at`, nullable `projection_completed_at`, and nullable
  `exhausted_at`;
- separate publication, consecutive transport-failure, and confirmed projection-failure counts;
- separate last transport and projection errors;
- timestamps.

The hot-path partial index is `(available_at, event_id)` where
`projection_completed_at IS NULL AND exhausted_at IS NULL`. Eligibility also requires
`available_at <= now`.

**Created by T5**, which owns the shape only. Three additions beyond the list above are recorded
here rather than left implicit:

- **`unique (aggregate_uuid, aggregate_version)`**, named
  `payment_outbox_aggregate_version_unique`. This mirrors the key `stored_events` already enforces,
  so a duplicate envelope for one aggregate version is **impossible** rather than merely detectable
  by M5's verifier. It can never fire spuriously, because the source table cannot produce a colliding
  pair. Beyond the literal text above, and cheaper now than a migration plus a data check later.
- **No `CHECK (… >= 0)` constraints on the three counters.** PostgreSQL has no unsigned integer, so a
  check would be the only enforcement of a non-negative invariant. **T5 deliberately ships none,
  because no production writer exists yet** — a constraint added now would guard columns nothing
  writes.

  The decision splits along the same line as the columns. **T7 owns the non-negative invariant for
  `publish_count` and `consecutive_transport_failures`**; **T8 owns it for
  `projection_failure_count`**. T6 creates the initial row and should let all three take their zero
  defaults rather than supplying them.
- **The two Payments-authored keys are named explicitly** —
  `payment_outbox_aggregate_version_unique` and `payment_outbox_eligible_index` — because PostgreSQL
  truncates identifiers at 63 bytes and a truncated name cannot be dropped by the name a later
  migration computes. The primary key keeps Laravel's generated `payment_outbox_pkey`, and the
  receipts table likewise keeps its generated primary-key and foreign-key names; `stored_events` and
  `snapshots` keep the vendor stubs' generated names throughout. Every generated identifier across
  the four tables — indexes and constraints, including PostgreSQL's own NOT NULL constraint names —
  was measured against the 63-byte limit; the longest is **58** characters
  (`payment_idempotency_receipts_idempotency_key_hash_not_null`), so nothing truncates.

Writer ownership is split across three tasks, and no envelope exists until the first of them lands:

- **T6 inserts** the initial envelope, in the same transaction as the idempotency receipt and the
  stored event. It is the first writer. T5 proved the row is *satisfiable* from a really-persisted
  `PaymentInitiated` — `event_id`, `event_type`, `schema_version`, `aggregate_uuid`,
  `aggregate_version`, `payload` and `occurred_at` all derive from the event or its stored row, and
  the payload is jsonb-equal to `stored_events.event_properties`. That derivation lives in a T5 test;
  **T6 should lift it rather than reinvent it.**
- **T7 mutates transport state only**: `available_at`, `last_published_at`, `publish_count`,
  `consecutive_transport_failures`, `last_transport_error`. It never writes `exhausted_at`.
- **T8 records consumer outcome, and owns exhaustion**: `projection_completed_at` on success, and on
  a confirmed handler failure `projection_failure_count`, `last_projection_error` **and the
  threshold-driven `exhausted_at`, in one transaction**.

  Exhaustion belongs with the counter that triggers it. The rules above already require it: an
  arbitrarily long transport outage never sets `exhausted_at`, only a confirmed consumer-handler
  exception increments the poison count, and exhaustion is reached when that count crosses its
  threshold. Splitting the increment from the threshold check across two owners would make the
  decision race — the count could reach five in T8 while T7 evaluated a stale value, exhausting a
  row early or never. One writer, one transaction, no race.

  `payments:outbox:retry {eventId}` **clears** `exhausted_at` after operator correction. That is a
  third surface, neither relay nor consumer, and it is the only path that unsets the column.

## Initial relay algorithm

The first slice implements lock-through-push:

1. Begin a short transaction and acquire the Payments projection shared gate.
2. Select one eligible row `FOR UPDATE SKIP LOCKED`.
3. Publish a small job containing only the outbox event ID.
4. Update the relevant delivery state.
5. Commit.

The queue connection must publish immediately inside this transaction, not through an
after-commit callback. One row per transaction, short transport timeouts, a bounded batch, and a
maximum wall-clock runtime prevent unbounded database occupancy. Overlapping relay invocations are
safe because row locks, not scheduler uniqueness, provide correctness.

## State transitions

```text
eligible
  |-- transport rejected --> transport backoff ---------------------|
  |-- transport accepted --> wait for projector receipt             |
  |                           |                                      |
  |                           +-- missing after grace --> redeliver -|
  |                           +-- handler exception --> failure count
  |                                                        |
  |                                              threshold -> exhausted
  |                           +-- receipt exists --> completed
  |
  +-- explicit operator retry <----------------------------- exhausted
```

- Accepted publication increments observability count, clears consecutive transport failure, sets
  `last_published_at`, and schedules receipt reconciliation. It does not consume poison budget.
- Rejected publication records transport failure and bounded exponential backoff while leaving
  `last_published_at` unchanged. An arbitrarily long transport outage never sets `exhausted_at`.
- Missing receipt after the configured grace period makes an accepted event eligible again.
- Only a confirmed consumer-handler exception increments the poison count.
- Five confirmed handler failures are the initial exhaustion threshold. Worker termination,
  backlog, and missing receipt are not poison evidence.
- `payments:outbox:retry {eventId}` clears exhaustion after operator correction while retaining
  historical evidence in logs.

Initial timings are operational defaults, not domain rules: start with a 30-second reconciliation
grace, capped exponential backoff, and one application-level consumer attempt per published job.
The compatibility and load-test milestone may tune them without weakening acceptance tests.

## Scheduler contract

`payments:outbox:relay` is a short-lived command scheduled every second. Local development and the
resilience demo use a continuously running Laravel scheduler process. Production deployment must
supervise a scheduler process and interrupt the previous release after new code is live.

Scheduler heartbeat and oldest eligible outbox age must expose a stopped scheduler even when HTTP
and workers are healthy. See the [runtime contract](../../operations/runtime-contract.md).

## Queue transport policy

The logical queue is `payments-projection`. It uses Redis and is supervised by Horizon. PostgreSQL
outbox state and projector receipts remain authoritative for recovery.

### Redis + Horizon

- M1–M5 use Redis so the relay, duplicates, Horizon supervision, failed jobs, and local recovery are
  easy to observe.
- The projection job and envelope must not depend on Redis types.
- Redis remains non-authoritative; PostgreSQL outbox and projector receipts support reconciliation.
- The initial AWS topology may keep this internal, recoverable queue on ElastiCache/Horizon when
  worker supervision, alarms, restart, and recovery evidence are in place.

### SQS boundary

SQS/DLQ is planned for a distinct durable workload selected after M5. It is not a second copy of
`payments-projection`. Its own specification must define message ownership, idempotency,
visibility timeout, worker timeout, retry/backoff, DLQ semantics, IAM, CloudWatch alarms, and
redrive procedure. Horizon does not supervise SQS.

Do not publish the same logical job to Redis and SQS merely to exercise both technologies. A
legitimate fan-out requires separately named consumers, messages, idempotency contracts, and
failure ownership. See [Messaging and queues](../../architecture/messaging-and-queues.md).

## Evolution gate: claim/lease publishing

Lock-through-push is intentionally simple. Trigger a 12B architecture review when sustained
measurement attributes one of these failures to network time inside the locked transaction:

- relay-held connections cause database-pool saturation or more than 20% API p95 regression;
- oldest eligible age exceeds five seconds for five continuous minutes while the transport is
  healthy;
- safe relay concurrency cannot meet throughput after timeout, batch, and index tuning.

If confirmed, implement a short transactional claim with token/expiry, publish outside the
transaction, token-guarded finalization, expired-claim recovery, and crash tests after claim,
publication, and before finalization. A transient transport outage alone is not a trigger.

## Blocking compatibility evidence

**Satisfied by T4.** Every invariant below was proved against the Composer-resolved stack — Laravel
13.24.0, PostgreSQL 18.4, Redis 8.10.0 via phpredis 6.3.0, PHP 8.4 — by a test-only spike under
`Modules/Payments/tests/`. It adds no production behaviour; see
[development environment](../foundation/development-environment.md#what-t4-deliberately-did-not-add).

| # | Invariant | Proving test | Observed mechanism | Status |
|---|---|---|---|---|
| Q6 | A transaction-scoped shared advisory lock and the work it protects run on one connection and one backend | `SpikeProjectionGateTest::it holds the shared gate lock and its protected work on one backend` | Read from the **observer** connection, `pg_locks` holds exactly one row — `locktype advisory`, `classid 4242`, `objid 1`, `objsubid 2`, `mode ShareLock`, `granted true` — whose `pid` equals the protecting backend's own `pg_backend_pid()`; the observer sees **zero** rows in the outbox surrogate while the closure is open, and zero advisory locks after commit | **PASS** |
| Q6 | The transaction-scoped lock releases on rollback as well as commit | `SpikeProjectionGateTest::it releases the transaction-scoped lock on rollback as well as on commit` | Injected failure propagates; observer sees zero rows and zero advisory locks; spike connection back at `transactionLevel() === 0`, with no explicit release call | **PASS** |
| Q6 | The affinity check has teeth | `SpikeProjectionGateTest::it reports an affinity mismatch when the lock is taken on another connection` | The same predicate used by the passing case returns **false** when the lock is held by `pgsql_lock` and the work runs on `pgsql_spike`; holder pid and query pid differ | **PASS** |
| Q7 | An exclusive contender is refused while the gate is held, and admitted once released | `SpikeProjectionGateTest::it refuses an exclusive contender while the gate is held and admits it after release` | `pg_try_advisory_xact_lock` from a second backend returns **false** immediately, then **true** after the holder rolls back. No sleep | **PASS** |
| Q7 | The gate is genuinely shared | `SpikeProjectionGateTest::it admits a second shared holder while the gate is held` | Two granted `ShareLock` rows with **distinct backend PIDs**, both `objsubid 2` | **PASS** |
| Q7 | A bounded wait raises a real, allowlisted `55P03` | `SpikeProjectionGateTest::it raises a real 55P03 when a bounded exclusive wait times out` | `SET LOCAL lock_timeout = '250ms'` plus `statement_timeout = '2s'`, then blocking `pg_advisory_xact_lock`: `QueryException`, SQLSTATE `55P03` in both `errorInfo[0]` and `getCode()` (string), message `canceling statement due to lock timeout`. Extracted with T3's own `SpikeTransactionRetry::sqlStateOf()` | **PASS** |
| Q8 | Outside a transaction, a lost connection reconnects **silently** onto a new backend | `SpikeProjectionGateTest::it reconnects silently onto a new backend when the lost connection has no transaction` | After `pg_terminate_backend`, the next query **does not throw**; the backend PID changed; `pg_locks` reports zero advisory rows; a third connection reacquires the key | **PASS** |
| Q8 | Inside a transaction, loss **surfaces**, and the PHP transaction level goes stale | `SpikeProjectionGateTest::it surfaces the lost connection and leaves a stale transaction level inside a transaction` | The next query throws `QueryException` wrapping `PDOException`; the container's real `LostConnectionDetector` classifies it; `transactionLevel()` still reports **1** over a backend that no longer exists; the advisory key is nonetheless reacquirable by another connection | **PASS** |
| Q8 | Teardown recovers from a terminated backend without leaking committed rows | `SpikeProjectionGateTest::it recovers from a terminated backend during teardown without leaking rows` | Self-contained: commits a row, opens a transaction, terminates its own backend, then runs teardown — which does not throw, recovers on a new pid at `transactionLevel() === 0`, leaves no advisory lock, and leaves **no committed row** | **PASS** |
| Q9 | Publication happens immediately, while the PostgreSQL transaction is still open | `SpikeRelayDispatchTest::it publishes to Redis while the PostgreSQL transaction is still open` | Inside the closure, Redis holds the message while the **observer** still reads `last_published_at IS NULL` and `publish_count = 0`. `JobQueued` fires on connection `redis_spike` at spike `transactionLevel() === 1`. `size()`, `pendingSize()` and `pendingJobs()->count()` all report 1, so the Lua-`eval` and ordinary-command key derivations agree | **PASS** |
| Q9 | The message carries an identifier, not an envelope | same test | Decoded payload: `displayName` and `data.commandName` are the spike job class, `data.command` is `O:42:"…SpikeRelayJob":1:{s:7:"eventId";s:36:"…";}`; no `amount`, `currency`, `merchant`, `occurred_at` or `aggregate` appears anywhere in the payload | **PASS** |
| Q9 | The control is not vacuous: `after_commit` really can defer here | `SpikeRelayDispatchTest::it defers the after_commit twin until the transaction commits` | An identical push through an `after_commit => true` twin connection leaves the queue at **0** inside the closure and **1** after commit, with `JobQueued` firing at spike `transactionLevel() === 0`. Callback locality is asserted, not assumed: the shared transaction manager's pending records read `["pgsql@1", "pgsql_spike@1"]` and `last()` is `pgsql_spike` | **PASS** |
| Q10 | A crash after accepted publication leaves the canonical row in its pre-attempt **eligible** state | `SpikeRelayDispatchTest::it leaves the row eligible after a crash and republishes on a later pass` | From a committed eligible row: pass 1 locks it, publishes, updates, then throws. The exception propagates, Redis retains **1** message, and the observer reads the row still present with `last_published_at IS NULL` and `publish_count = 0` | **PASS** |
| Q10 | A later pass republishes, producing the documented safe duplicate | same test | The *same* `SpikeRelayAttempt` instance runs again with only the failure switch disarmed: Redis holds **2** messages carrying the same event id, and the row commits with `publish_count = 1` and non-null `last_published_at` | **PASS** |

Pass 2 is also the control for pass 1: it proves the committing path does update the row, so pass 1's
untouched row is a rollback result rather than a write that never happened. The failure switch is
sticky by design, so a dedicated test
(`SpikeRelayDispatchTest::it disarms the failure switch so the same boundary stops throwing`) proves
`disarm()` is what makes pass 2 succeed, rather than a silently replaced switch or a skipped boundary.

### Constraints T7 inherits

- **Publish with `push()`, never `pushRaw()`.** `pushRaw()` skips `Queue::enqueueUsing()` entirely and
  is unconditionally immediate (`Queue/RedisQueue.php:323-335`), so it cannot express — or be tested
  against — the after-commit question at all. Only `push()` consults the connection's `after_commit`
  (`Queue/RedisQueue.php:302-313`, `Queue/Queue.php:366-387`).
- **The relay job must not opt into after-commit.** `shouldDispatchAfterCommit()` checks
  `ShouldQueueAfterCommit` and a truthy `$afterCommit` property **before** the connection config
  (`Queue/Queue.php:396-405`), so a connection set `after_commit => false` cannot force immediacy on
  such a job. The job must implement neither and must never be dispatched with `->afterCommit()`.
  **Read from source, not measured** — see the non-claims below.
- **A resolved queue connection caches its configuration.** `QueueManager::connection()` stores the
  resolved queue per name (`Queue/QueueManager.php:158-198`), so changing `queue.connections.<name>`
  afterwards has no effect. Two behaviours need two configured names. The Redis connector itself
  resolves the manager lazily per `resolve()` (`Queue/QueueServiceProvider.php:202-207`), so a
  connection registered at runtime is picked up only if the `redis` instance is forgotten first
  (`Redis/RedisServiceProvider.php:18-22`).
- **Measured queue keys.** A real `push()` populated exactly two keys: `queues:<name>` and
  `queues:<name>:notify` (`Queue/LuaScripts.php:32-38`). `:delayed` and `:reserved` exist in the key
  space but were never written by publication alone. Anyone pointing Horizon at these queues should
  start from those literal names rather than from a prefixed variant.

**Not proved, and not claimed.** T4 measured transport and locking primitives, nothing above them.
No worker ever consumed a message: `pop()`, reservation, `retry_after`, and the notify-key semantics
are untouched. **No Horizon supervision, no scheduler, and no `payments:outbox:relay` command exist.**
None of the state transitions above were exercised — no backoff, no `exhausted_at`, no poison
counting, no receipt reconciliation. The spike's outbox surrogate is a **three-column test-only
table**, not a proposal for `payment_outbox`, and its eligibility predicate is literally
`last_published_at is null`. `FOR UPDATE` was used in a single process; **`SKIP LOCKED` and relay
concurrency were not exercised**, and neither was any multi-process behaviour, which remains T12's.
The advisory key is a spike constant: **the production derivation from
`payment_projection_controls` is undecided and belongs to T11.** The `ShouldQueueAfterCommit`
constraint above is read from vendor source rather than measured, deliberately, because no database
or transport version could change it. Every observation here is from this container on this stack and
is not a portability claim.

**The harness is not parallel-safe.** It commits real rows into shared `ledgerflow_test` tables and
writes shared namespaced keys in Redis logical database 15, so two workers would see each other's
state. `make test` deliberately does not enable parallel execution. Everything proved above is
therefore single-process; multi-process behaviour remains T12's scope.

## Acceptance criteria

- Transport outage leaves the payment and outbox committed, queryable as pending, and recoverable.
- Rejected publication records retryable transport state without false completion or exhaustion.
- Crash after accepted publication and before commit produces a safe duplicate.
- Missing accepted work is reconciled from durable state.
- Confirmed poison failure is separated from crash, backlog, and transport failure.
- Completion removes the row from the partial active index only in the consumer transaction.
- Redis/Horizon queue supervision and recovery remain explicit in local and AWS runtime contracts.

## Related

- [Projection and query](projection-and-query.md)
- [Runtime contract](../../operations/runtime-contract.md)
- [Messaging and queues](../../architecture/messaging-and-queues.md)
- [Resilience test plan](../../testing/payments-resilience.md)
- [Laravel Horizon documentation](https://laravel.com/docs/13.x/horizon)
