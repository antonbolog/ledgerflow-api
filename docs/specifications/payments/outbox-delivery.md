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

Writer ownership is split across three tasks. **All three have landed**: T6 inserts the envelope, T7
publishes it and owns transport state, and T8 records consumer outcome and owns exhaustion. *(This
paragraph read "the first has landed; the other two have not" while it was written at T6; the
per-writer notes below already carried the T7 and T8 completions, and the
[roadmap](../../roadmap.md) owns task state.)*

- **T6 inserts** the initial envelope, in the same transaction as the idempotency receipt and the
  stored event. It is the first writer, and it is
  `Modules\Payments\Infrastructure\Projectors\PaymentOutboxWriter` — a synchronous, non-queued
  projector registered explicitly by `PaymentsServiceProvider`, because Spatie's
  `auto_discover_projectors_and_reactors` only ever looks in `app/`. T5 proved the row is
  *satisfiable* from a really-persisted `PaymentInitiated` — `event_id`, `event_type`,
  `schema_version`, `aggregate_uuid`, `aggregate_version`, `payload` and `occurred_at` all derive
  from the event or its stored row, and the payload is jsonb-equal to
  `stored_events.event_properties`. T6 **lifted that derivation rather than reinventing it**.

  **The payload is read back out of `stored_events`, not re-serialised.** The writer selects
  `event_properties` by the primary key the repository has just stamped on the event instance, on the
  same connection inside the same transaction, which makes jsonb-equality with the stored event true
  by construction instead of by test. It supplies none of the three counters, so all of them take
  their zero defaults, and `available_at` is set to the insert time — immediately eligible. Every
  later mutation of that column belongs to T7, apart from the one narrow operator-retry exception
  recorded below.

  It refuses **loudly** when `Projectionist::isReplaying()` is true, writing nothing: a replayed event
  is fully derivable, so a standard rebuild would otherwise re-create envelopes or die on the
  aggregate-version unique reporting the wrong problem.

  **T11 has made that decision: a rebuild never creates or deletes an envelope.** It clears and
  recreates only `payment_details` and the named projector's receipts, and reconciles
  `projection_completed_at` for receipts it recreated; envelope reconstruction stays
  `payments:outbox:repair`, one envelope at a time. The rebuild also never invokes Spatie replay or
  this writer at all, deriving read rows directly from canonical `stored_events`, so the replay guard
  above is a second line of defence rather than the mechanism relied on. Recorded in
  [ADR 0005](../../adr/0005-payments-projection-gate-and-rebuild-ownership.md) and
  [verification and rebuild](verification-and-rebuild.md#what-a-rebuild-clears-recreates-and-never-touches).
  Not implemented.
- **T7 mutates transport state only**: `available_at`, `last_published_at`, `publish_count`,
  `consecutive_transport_failures`, `last_transport_error`. It never writes `exhausted_at`.
- **T8 records consumer outcome, and owns exhaustion**: `projection_completed_at` on success, and on
  a confirmed handler failure `projection_failure_count`, `last_projection_error` **and the
  threshold-driven `exhausted_at`, in one transaction**.

  One further column joins that T8-owned set: `projection_failure_generation`, a monotonic
  non-negative fence defaulting to `0` that only a successful operator retry increments, so a delayed
  failure recorder cannot charge its increment to a new retry cycle. **Implemented by T8**, whose
  forward migration adds the column with a named non-negative CHECK alongside one for
  `projection_failure_count`.

  Exhaustion belongs with the counter that triggers it. The rules above already require it: an
  arbitrarily long transport outage never sets `exhausted_at`, only a confirmed consumer-handler
  exception increments the poison count, and exhaustion is reached when that count crosses its
  threshold. Splitting the increment from the threshold check across two owners would make the
  decision race — the count could reach five in T8 while T7 evaluated a stale value, exhausting a
  row early or never. One writer, one transaction, no race.

  `payments:outbox:retry {eventId}` **clears** `exhausted_at` after operator correction. That is a
  third surface, neither relay nor consumer, and it is the only path that unsets the column.
  **Implemented by T8.**

  **Implemented by T8**, one narrow exception to T7's ownership of every post-insert
  `available_at` mutation: a successful retry atomically sets `available_at = now()` in the same
  locked statement, so a corrected exhausted row becomes immediately relay-eligible instead of waiting
  for a transport schedule it never earns. Ordinary transport scheduling - backoff, grace, and
  acceptance - stays T7's alone. The full reset list, including the `projection_failure_generation`
  increment, belongs to [projection and query](projection-and-query.md) and is not repeated here.

  **A fourth surface is implemented by T10**: `payments:outbox:repair {eventId} --delivery-quiesced`
  restores the immutable canonical fields of exactly one envelope from its stored event, and derives
  the completion marker from the named projector receipt. It **preserves** every column in the two
  bullets above - transport history, `projection_failure_count`, `last_projection_error`,
  `projection_failure_generation` and `exhausted_at` - so it neither reactivates an exhausted row nor
  moves the generation fence, and `payments:outbox:retry` stays the only reactivation path. Its one
  `available_at` mutation is narrow: a non-exhausted, non-completed row whose canonical fields or
  marker actually changed becomes eligible in the same transaction. A missing envelope is reinserted
  with zero-default counters, which is reported as reinitialized delivery history rather than as a
  reconstruction of it. See
  [verification and rebuild](verification-and-rebuild.md#targeted-outbox-repair) for the full
  mutation matrix and the delivery-quiescence prerequisite.

## Initial relay algorithm

**Implemented by T7** as lock-through-push, in `Modules\Payments\Infrastructure\Relay\OutboxRelay`:

1. Begin a short transaction and set a local `statement_timeout`.
2. Select one eligible row `FOR UPDATE SKIP LOCKED`.
3. Publish a small job containing only the outbox event ID.
4. Update the relevant delivery state.
5. Commit.

The queue connection must publish immediately inside this transaction, not through an
after-commit callback. One row per transaction, short transport timeouts, a bounded batch, and a
maximum wall-clock runtime prevent unbounded database occupancy. Overlapping relay invocations are
safe because row locks, not scheduler uniqueness, provide correctness — `SKIP LOCKED` is what makes
that true, and no `withoutOverlapping()`/`onOneServer()` is registered on the schedule entry.

### HS-1 — discharged by T11 Slice 2

An earlier draft of this algorithm opened with "acquire the Payments projection shared gate." **T7 did
not do this.** `payment_projection_controls`, its ready/rebuilding row, and the connection-affine
advisory lock in front of it belonged to T11, which had not run at T7; a provisional gate built there
would either have become permanent scope creep or been discarded, coupling two milestones for no
present benefit. See [ADR 0004](../../adr/0004-lock-through-push-outbox-relay.md) for the full decision
record and [ADR 0005](../../adr/0005-payments-projection-gate-and-rebuild-ownership.md) for the
control row, the key derivation, and the deferral outcome it records.

**T11 Slice 2 discharges HS-1.** Gate acquisition is the first statement inside
`OutboxRelay::relayOne()`, before eligibility selection, on the same injected connection. A relay pass
under a closed gate selects no row and therefore writes no `available_at`, no
`consecutive_transport_failures`, and no `last_transport_error`: the transport budget is untouched by
construction. That deferral is `RelayOutcome::GateClosed`, reported distinctly from `NothingEligible`
(a drained backlog), so a closed gate is never read as an empty queue. See
[verification and rebuild](verification-and-rebuild.md#t7s-reciprocal-obligation-discharged-by-t11-slice-2)
for the reciprocal side and its
[Slice 2 evidence](verification-and-rebuild.md#t11-slice-2-implementation-evidence). The bounded batch
rebuild that would ever close this gate for real remains unimplemented.

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
- `payments:outbox:retry {eventId}` clears exhaustion after operator correction and sets
  `available_at` to `now()`, while retaining historical evidence in logs. T8 owns it, and it is
  implemented.

Initial timings are operational defaults, not domain rules: start with a 30-second reconciliation
grace, capped exponential backoff, and one application-level consumer attempt per published job.
The compatibility and load-test milestone may tune them without weakening acceptance tests.

## Scheduler contract

**Implemented by T7.** `payments:outbox:relay` is registered
`Schedule::command('payments:outbox:relay')->everySecond()` in root `routes/console.php` — root,
because `ModuleBoundaryTest` forbids a module carrying a `routes` directory, the same reason
`config/payments.php` lives at the root rather than inside `Modules/Payments`. It is a short-lived,
bounded command: loop until drained, a 50-row batch cap, or a 750 ms start-budget checked **before**
each next row — a start budget, not a kill deadline, so a row already begun always finishes
committing or rolling back.

`payments:outbox:status` is the read-only JSON health surface. It reports `heartbeat` (one of five
states — `fresh`, `stale`, `missing`, `unavailable`, `invalid` — never echoing a malformed or
future-dated value) and `database` (one snapshot-consistent PostgreSQL aggregate: `eligible_count`,
`active_count`, `oldest_eligible_age_seconds`, all `null` on database failure). It exits `0` only when
the heartbeat is fresh **and** the PostgreSQL query succeeds. The heartbeat is written in application
UTC — not PostgreSQL `now()` — only after a fully handled pass, because it records that a *process*
made progress, which a database outage must not be able to fake as a scheduler outage.

Local development runs the scheduler as an **opt-in** Compose service, `scheduler`, behind profile
`projection`: the same `dev`-target image as `app`, `command: php artisan schedule:work`, healthchecked
by `payments:outbox:status` itself — not mere process existence, so a wedged loop or a lost database
both fail the check. A clean `make install`/`make start` never brings this profile up;
`make projection-start` and `make projection-stop` toggle it **together with the `horizon` consumer
that shares its profile**, and `make stop`/`make destroy` are profile-aware so they reach both.
**The relay stays dormant until an operator starts the scheduler and its Horizon-supervised consumer
together** - a long-lived producer without its consumer would otherwise republish every
accepted row on an unbounded 30-second cycle. Production deployment must supervise a scheduler process
the same way and interrupt the previous release after new code is live.

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

## Implementation evidence

**Satisfied by T7**, which shipped the eight production types listed in
[ADR 0004](../../adr/0004-lock-through-push-outbox-relay.md), the one forward migration adding
`payment_outbox`'s two non-negative CHECK constraints, `config/payments.php`, the schedule
registration, and the opt-in `scheduler` Compose service. Every row below was observed through
`make check` against PostgreSQL 18.4 and Redis 8.10.0 (phpredis 6.3.0), re-proving the T4 primitives
against production code and the real `payment_outbox` rather than the T4 surrogate table — T4's
evidence above remains frozen and is not restated as re-measured here.

| # | Invariant | Proving test | Observed mechanism | Status |
|---|---|---|---|---|
| M-1 | The physical Redis key a real `push()` writes matches what both read paths derive | `PaymentRelayRuntimeContractTest::it addresses one physical key from both read paths, and records the prefix phpredis applies` | `size()` (Lua `eval`) and `pendingSize()` (`llen`) agree at 1 after a real push on the named `payments-projection` connection; the key exists only under the client's own prefix | **PASS** |
| M-2 | `SKIP LOCKED`, compiled and exercised, not merely intended | `OutboxRelayTest::it skips a row another session holds and publishes the next one instead`, `…it compiles the eligibility query with SKIP LOCKED rather than a bare row lock` | A second connection holds `FOR UPDATE` on the oldest eligible row; the relay selects and publishes the *other* row with no sleep and no `55P03`; compiled SQL pinned to end `for update skip locked` | **PASS** |
| M-3 | The forward migration's two CHECK constraints are real and named correctly | `PaymentsSchemaTest::it refuses a negative publish_count and a negative consecutive_transport_failures`, `…it reports 23514 naming the violated transport constraint` | Both constraints exist, fit the 63-byte identifier limit, and a direct negative `UPDATE` now raises `23514` naming the violated constraint (measured accepting the same write silently before the migration) | **PASS** |
| — | One eligible row, ordered by the index, three ways a row is correctly invisible | `OutboxRelayTest::it takes the oldest eligible row first and cannot see an ineligible one` | Oldest-`available_at` row published first; a future-dated, a completed, and an exhausted row all remain untouched through repeated passes | **PASS** |
| — | Accepted publication updates transport state from PostgreSQL `now()`, with exact grace | `OutboxRelayTest::it makes an accepted row eligible again only after the grace has passed` | Row invisible inside the 30 s grace window, reachable again after it elapses | **PASS** |
| — | Transport rejection backs off and later clears on acceptance | `RelayPaymentOutboxCommandTest::it continues after a transport rejection but never after any other failure` | Two rejected rows both show `consecutive_transport_failures = 1`; pass continues to a second row after a rejection | **PASS** |
| — | Any other failure rolls back and aborts the whole pass, with no same-pass retry | `RelayPaymentOutboxCommandTest::it aborts the pass with a non-zero exit when a row fails for any other reason`, `OutboxRelayTest::it classifies a failure before BEGIN as pre-commit rather than inferring it from an idle connection`, `…it leaves Laravel and the PDO clean after a commit-boundary failure, and relays again on the same connection` | Exactly one attempted row before abort; `DB::transactionLevel() === 0` and `getPdo()->inTransaction() === false` after every failure phase; the same connection relays successfully immediately afterward | **PASS** |
| — | A crash after accepted push and before commit is a safe duplicate, not a lost message | `OutboxRelayTest::it restores the exact pre-attempt row after a crash between the accepted push and the commit` | Redis retains the message; PostgreSQL rolls back to the exact pre-attempt row, column by column; a later pass republishes the same event id, producing two Redis messages carrying one identifier | **PASS** |
| — | An ambiguous commit outcome is reported as such, never guessed | `OutboxRelayTest::it reports an ambiguous commit outcome instead of claiming the work rolled back` | A `TransactionCommitted` injection (fires *after* `getPdo()->commit()` succeeds) is classified `commit_outcome_unknown`, aborts non-zero, and does not republish in the same pass | **PASS** |
| — | Rollback-cleanup failure never replaces the primary exception | `OutboxRelayTest` fault-injection case where the primary failure and its rollback cleanup both throw | The primary exception escapes unchanged; the cleanup failure is logged separately and never retried in the same pass | **PASS** |
| — | Structured logs carry no payload, credential, or raw exception message | `RelayPaymentOutboxCommandTest::it keeps payloads and raw transport detail out of the pass log` | A sentinel planted inside a driver exception message, plus `amountMinor`/`merchantId`, are asserted absent from every rendered log record across the whole pass | **PASS** |
| — | Heartbeat is application UTC, written only after a handled pass, and cache failure never blocks delivery | `RelayPaymentOutboxCommandTest::it records the heartbeat once, in application UTC, only after a handled pass`, `…it writes no heartbeat when the pass aborts`, `…it does not undo completed delivery when the heartbeat cache is unavailable` | Heartbeat absent until a pass completes; absent entirely on an aborted pass; a broken `CacheFactory` still lets the pass publish and commit, logging only a warning | **PASS** |
| — | `payments:outbox:status` distinguishes every documented state and exits accordingly | `PaymentOutboxStatusCommandTest` (fresh/stale/missing/unavailable/invalid heartbeat states; database ok/unavailable; eligible/active/oldest-age metrics) | Exit `0` only for fresh heartbeat + database `ok`; every other combination exits `1`; a forced pre-transaction failure yields `database.state:unavailable` with all three metrics `null`, and the JSON carries no exception message | **PASS** |
| — | The schedule entry runs every second with no scheduler-uniqueness dependency | `PaymentRelayRuntimeContractTest::it schedules the relay command every second, with no scheduler-uniqueness dependency` | `getExpression() === '* * * * *'`, `isRepeatable() === true`, `repeatSeconds === 1`, `withoutOverlapping === false` | **PASS** |
| — | The disposable scheduler reaches healthy with a fresh heartbeat; `stop`/`destroy` reach it too | Manual run under `COMPOSE_PROJECT_NAME=ledgerflow-t7-relay-contract` — **not** a Pest test; see the non-claim below | `relay-start` → `docker compose … --wait` exits 0; live `payments:outbox:status` inside the `scheduler` container reports `healthy:true`; `relay-stop`, plain `stop`, and confirmed `destroy` each reach and stop/remove the `scheduler` container; `destroy` leaves 0 containers/volumes/networks for the disposable project | **PASS** |

**Environment limitation, recorded rather than hidden.** The disposable-project measurement's fresh
image build was blocked by this session's registry access (`docker/dockerfile:1` frontend resolution
timed out on every attempt); the run reused the already-built `dev`-target image by retagging it,
which was removed again afterward. This is not a fresh-build proof of the current `Dockerfile` — the
image content itself was already proved current by the same `make check` this evidence table draws
its Pest rows from. See
[development environment](../foundation/development-environment.md#what-t7-added-and-what-it-deliberately-did-not).

**Not proved, and not claimed.** *(Frozen as written at T7, and describing the state at that time.
T8 has since delivered the consumer, `payments:outbox:retry` and Horizon supervision; what T8 itself
did not prove is recorded in
[projection and query](projection-and-query.md#implementation-evidence).)*

- No consumer, no `payments:outbox:retry`, and no Horizon exist. Nothing reads `payment_outbox` for
  projection purposes; T8 owns that.
- Pest never ran a live scheduler process. Every `everySecond()`/heartbeat/status assertion above uses
  an injected monotonic clock or direct command invocation; the only evidence that `schedule:work`
  itself invokes `payments:outbox:relay` on a real per-second cadence is the disposable Compose
  measurement, which is explicitly not a Pest-run claim.
- No multi-process relay race is proved. `SKIP LOCKED` progress above is two connections in one
  process, matching T4's own scope note; genuine multi-process racing remains T12's.
- No load, throughput, or index-plan claim. The eligibility index is proven to exist, partial, on the
  documented columns; the planner remains free to choose whatever plan it likes on a handful of rows.
- No rebuild-safety claim at T7. Whether a rebuild re-creates envelopes was T11's question; how the
  relay defers under a closed gate is answered by HS-1 above, discharged in T11 Slice 2.
- No end-to-end projector-receipt reconciliation. The 30-second grace and its redelivery are proved
  from the relay's own transport state only; nothing here writes or reads a projector receipt, because
  none exists until T8.
- `40P01` and `40001` remain allowlisted and unit-classified for the relay's own failure path,
  unchanged since T3 — this relay retries nothing itself; its "retry" is the next scheduled pass.

### Transport exception classification — verified against the resolved stack

`OutboxRelay::relayOne()` catches `RedisException` and nothing else. That boundary was re-derived
from installed source and measured against the running stack (phpredis **6.3.0**, `redis:8.10.0-alpine`,
non-cluster, `client => phpredis`, queue connection `payments-projection` over Redis connection
`payments`). **No code change followed: the catch is already exactly right.**

The resolved call path is `RedisQueue::push()` → `Queue::createPayload()` → `enqueueUsing()` →
`RedisQueue::pushRaw()` → `getQueueRedisKey()` → `RedisManager::connection('payments')` →
`PhpRedisConnector::connect()` → `PhpRedisConnection::command('eval', …)`. Every type it can raise:

| Thrown by | Type | Class | Relay handling |
|---|---|---|---|
| `Queue::createPayload()`, `json_encode` failure (`Queue.php:135-143`) | payload encoding | `InvalidPayloadException` (extends `InvalidArgumentException`) | escapes — abort pass |
| `Queue::createObjectPayload()`, `serialize(clone $job)` failure (`Queue.php:192-201`) | serialization | `RuntimeException` (Laravel-wrapped, original as `previous`) | escapes — abort pass |
| `RedisManager::resolve()`, unknown connection name (`RedisManager.php:122`) | configuration | `InvalidArgumentException` | escapes — abort pass |
| `PhpRedisConnector::createClient()`, extension missing or facade-alias collision | configuration | `LogicException` | escapes — abort pass |
| `PhpRedisConnector::establishConnection()`, `Redis::connect()` | transport | `RedisException` | **caught** — backoff committed |
| `PhpRedisConnection::command('eval')`, connection lost / protocol desync / non-`ERR` server reply | transport | `RedisException` | **caught** — backoff committed |

Neither wrapper layer converts anything: `Redis\Connections\Connection::command()` catches
`Throwable` only to dispatch a `CommandFailed` event and rethrows the same instance
(`Connection.php:123-131`), and `PhpRedisConnection::command()` catches `RedisException` only to
optionally rebuild the client before rethrowing it unchanged (`PhpRedisConnection.php:528-539`). So
the class the relay sees is the class phpredis raised.

**The four types that cannot occur here**, each ruled out rather than assumed:

- **`RedisClusterException`** — thrown only by `RedisCluster` methods. `RedisManager::resolve()`
  reaches `connectToCluster()` only via `isset($this->config['clusters'][$name])`, which is tested
  *after* the top-level `isset($this->config[$name])` that `payments` already satisfies; and
  `config/database.php` declares no `clusters` key at all. `RedisQueue::isClusterConnection()` calls
  `Connection::isCluster()`, a plain `return false` (`Connection.php:193-196`) — not a Redis command,
  so it cannot fail. Worth recording precisely because **`RedisClusterException` extends
  `RuntimeException`, not `RedisException`** (measured): were it ever reachable it would abort the
  pass rather than back off, so introducing a cluster connection here is a breaking change to this
  classification, not a configuration detail.
- **Predis exceptions** — `predis/predis` is not installed. It appears in `composer.lock` only under
  the `require-dev` and `suggest` blocks of other packages, never in the installed package set, and
  `Predis\Client` does not exist at runtime (measured). `REDIS_CLIENT` is `phpredis`.
- **Queue contract exceptions** — `Illuminate\Contracts\Queue\*` declares no exception the push
  path throws; the failures above are the whole surface.
- **Converted PHP warnings** — this one was measured rather than reasoned, because
  `Redis::connect()` *does* emit an `E_WARNING` beside its exception on a name-resolution failure.
  With the application bootstrapped and `HandleExceptions` live (`error_reporting() === -1`, handler
  registered), an undefined-array-key warning converts to `ErrorException` as expected, yet
  `Redis::connect('no-such-host')` still surfaces `RedisException` — not `ErrorException`. The
  control and the subject were run in the same bootstrapped process, so the negative result is a
  measurement, not a dead probe.

**Redis can accept the push and then throw — and that is safe.** Measured directly with the relay's
own `read_timeout` of 0.25 s: a script mirroring `LuaScripts::push()` that applies both `rpush` calls
and then busy-loops past the read timeout makes the client throw
`RedisException: protocol error, got '…' as reply-type byte`, while a *separate* client confirms the
payload and its `:notify` entry are durably in the list.

The relay classifies that throw as a `RedisException` and takes the ordinary transport-rejection
path, which **commits — there is no rollback on this path.** `recordRejection()` increments
`consecutive_transport_failures`, writes the fixed `last_transport_error` category, and moves
`available_at` forward by the transport backoff for the incremented count; `publish_count` and
`last_published_at` are absent from the statement and stay exactly as they were, which is correct,
because from the relay's side the publication was not acknowledged. That `UPDATE` is then committed
with the row. When the backoff expires the row re-enters the partial active index, the next pass
selects it as eligible, and it is republished — so the message Redis had already accepted is
delivered a second time. The outcome is one **safe duplicate**, never a lost message.

This reaches the same ceiling as the crash-before-commit case by a different mechanism, and the two
should not be conflated: a crash before `COMMIT` is a genuine PostgreSQL rollback that leaves the row
untouched and immediately eligible, whereas an accepted-then-thrown push commits durable rejection
state and defers the row by the backoff. Both can only over-deliver — which the consumer's
idempotency absorbs — and neither can under-deliver.

**Residual risk, deliberately not fixed here.** phpredis does not throw for every server error: it
returns `false` and sets `getLastError()` for replies prefixed `ERR`, `NOSCRIPT` and `WRONGTYPE`, and
throws `RedisException` for all others. Both halves were measured — a Lua compile error and a
`WRONGTYPE` push both returned `false`, while an `OOM command not allowed` push threw. Because
`RedisQueue::pushRaw()` discards `eval()`'s return value entirely (`RedisQueue.php:327-332`), a
`false` reply is invisible to `push()` and therefore to the relay.

**The consequence is a false-positive publication acknowledgement, not message loss.** The relay
takes its acceptance path for a message Redis never enqueued: `recordAcceptance()` increments
`publish_count`, sets `last_published_at = now()`, **clears** `consecutive_transport_failures` to `0`
and `last_transport_error` to `null`, and moves `available_at` to `now() + reconciliation_grace_seconds`
rather than to a transport backoff. Four things follow, and they are bounded by T7's own
reconciliation contract rather than by anything T8 adds:

- **Retry is delayed to the reconciliation grace instead of the transport backoff.** The row is not
  lost and needs no external detection: when the grace expires with no `projection_completed_at`
  written, it re-enters the partial active index and T7 republishes it on the next pass. That is the
  same redelivery loop that covers a consumer which never ran, and it requires no knowledge of
  projector receipts — which is precisely why T7 owns it.
- **Transport-failure state is temporarily cleared and `publish_count` advances.** A count that
  climbs while nothing is delivered is the misleading signal here, not a stuck row.
- **A persistent condition repeats once per reconciliation cycle** until the underlying Redis key is
  repaired, rather than backing off exponentially the way a thrown rejection would. It is a slow,
  self-repeating loop, not a permanent stall.
- **The health surface may not distinguish it during the grace window.** Within the grace the row is
  simply not yet eligible and carries clean transport state, so it reads as healthy in-flight work;
  it only becomes visible as backlog once the grace lapses and it keeps returning.

The condition is unlikely by construction: the realistic error classes for this script — `OOM`,
`READONLY`, `NOAUTH`, `MISCONF`, `LOADING` — all fall on the throwing side, and the silent side needs
the queue key itself to hold a non-list, which requires a foreign write into a logical Redis database
dedicated to the relay with explicit key ownership. It is **not** repairable inside `OutboxRelay`
without abandoning `push()` for `pushRaw()`, and that trade is refused here: `pushRaw()` bypasses
`enqueueUsing()` and so cannot be held to the `after_commit => false` contract that the whole
lock-through-push ordering depends on. Recorded as a known limitation of the vendor push path that
**stays with T7's reconciliation loop** — it creates no permanent message loss under that loop, and
it is not handed to T8, which owns projection completion and consumer outcomes, not the detection of
a Redis job that was never enqueued.

**One consequence of the desync worth naming.** The `RedisException` message measured above
(`protocol error, …`) does not match any substring in `PhpRedisConnection::command()`'s reconnect
list (`went away`, `socket`, `Error while reading`, `read error on connection`, `READONLY`,
`Connection lost`), so Laravel does not rebuild the client, and `RedisManager` caches the connection
per process. A pass that continues after a rejection therefore reuses a desynced connection. That
degrades to more rejections rather than to wrong state: every subsequent misread also throws
`RedisException`, so those rows are backed off, not falsely marked published — and the server executed
each `EVAL` it received regardless, so the ceiling remains duplicates.

## Acceptance criteria

- Transport outage leaves the payment and outbox committed, queryable as pending, and recoverable.
- Rejected publication records retryable transport state without false completion or exhaustion.
- Crash after accepted publication and before commit produces a safe duplicate.
- Missing accepted work is reconciled from durable state.
- Confirmed poison failure is separated from crash, backlog, and transport failure.
- Completion removes the row from the partial active index only in the consumer transaction.
- Redis/Horizon queue supervision and recovery remain explicit in local and AWS runtime contracts.

## Related

- [ADR 0004](../../adr/0004-lock-through-push-outbox-relay.md) — the lock-through-push decision record,
  HS-1, and the disposable-scheduler measurement.
- [Projection and query](projection-and-query.md)
- [Verification and rebuild](verification-and-rebuild.md) — T11's reciprocal gate obligation.
- [Runtime contract](../../operations/runtime-contract.md)
- [Messaging and queues](../../architecture/messaging-and-queues.md)
- [Resilience test plan](../../testing/payments-resilience.md)
- [Laravel Horizon documentation](https://laravel.com/docs/13.x/horizon)
