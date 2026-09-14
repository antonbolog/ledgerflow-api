# Payment Projection and Query Specification

## Given

The Payments relay delivers an outbox event ID at least once. PostgreSQL retains the canonical
envelope and stored event.

## Problem

A consumer may receive duplicates, crash after partial work, or encounter messages out of order.
The query API must distinguish a missing payment from a valid payment whose projection has not
caught up, without rebuilding the aggregate on reads.

## Decision

The consumer applies one deterministic projection transaction. A durable projector receipt is the
idempotency boundary. The query API reads only `PaymentDetails` and the merchant-owned idempotency
receipt.

**T8, T9 and T11 are implemented: the consumer, the query endpoint, traffic gating, the bounded
rebuild, and recovery.** The
consumer, its two tables, the projection failure fence, the failure recorder, and
`payments:outbox:retry` all exist, and so does the merchant-scoped query endpoint; both carry the
evidence recorded below. **The shared projection gate now guards both**: the consumer's transaction
checks it before the envelope row lock, and the query's transaction checks it before either table
lookup - see [verification and rebuild](verification-and-rebuild.md#t11-slice-2-implementation-evidence).
The bounded batch rebuild and recovery are documented in the [T11 evidence](verification-and-rebuild.md#t11-implementation-evidence). [The roadmap](../../roadmap.md)
owns task state.

## Consumer transaction

Owned by T8. For `(projector_name, event_id)`:

1. Begin one explicit transaction on the injected default PostgreSQL connection and load the durable
   outbox envelope by `event_id` `FOR UPDATE`.
2. Validate that envelope and decode exactly the `payments.payment_initiated` schema version it
   declares.
3. Insert a projector receipt with `ON CONFLICT DO NOTHING`.
4. When the insert succeeds, derive all business fields from the event and insert uniquely keyed
   `PaymentDetails`.
5. Set the outbox `projection_completed_at` marker.
6. Commit receipt, read model, and completion marker together.

**T11 Slice 2 prepends connection-affine gate acquisition and deferral semantics to this same
transaction**, before the envelope lock, on the same connection. A closed gate returns
`ProjectionOutcome::GateDeferred` and raises no exception; no envelope is locked. That is the consumer
side of the reciprocal obligation recorded as HS-1 in
[ADR 0004](../../adr/0004-lock-through-push-outbox-relay.md) and in
[verification and rebuild](verification-and-rebuild.md#t8s-reciprocal-obligation-discharged-by-t11-slice-2).

An already-exhausted locked row is an observability-only no-op: no projection, no counter, no
marker mutation. Operator retry is the sole reactivation path. A missing outbox row is a typed
non-countable visible failure that emits only safe structured context and writes no orphan failure
metadata.

If the receipt already exists, verify that it identifies the same named projector and event, and
that the corresponding read row exists. Leave `PaymentDetails` unchanged and fill only a missing
completion marker. A receipt whose read row is missing is corruption for verification/rebuild, not
an excuse to silently recreate state during a duplicate delivery.

A `payment_id` or `source_event_id` collision from another source event represents contradictory
history and must fail visibly. Do not hide it with an upsert.

The projection derives `initiated_at` from event `occurred_at`; it does not use processing time,
randomness, or live mutable data. PostgreSQL `now()` is used only for the operational receipt and
completion-marker timestamps. Aggregate version is retained so later lifecycle events can
detect out-of-order delivery. The first-event slice does not build a generic deferral framework.

Projection delivery has **one application attempt** and does not reuse initiation's
`RetryingAtomicTransaction`. A serialization retry loop belongs to the write path that owns its own
idempotency receipt; here redelivery and outbox reconciliation own recovery.

## Durable projection schema

Implemented by T8, and pinned here so T9-T11 do not reverse-engineer the
migration. Exact key and constraint names are asserted in the Payments schema test.

`payment_projector_receipts`:

- `projector_name varchar(255)`;
- `event_id uuid`;
- `created_at timestamp(6) with time zone`;
- composite primary key `(projector_name, event_id)`.

It carries **no foreign key** to `payment_outbox` or `stored_events`: the outbox is rebuildable
delivery state and `stored_events` is vendor-owned, and the receipt must outlive both.

`payment_details`:

- `payment_id uuid` primary key;
- `source_event_id uuid` unique;
- `merchant_id bigint` with a restrictive merchant foreign key;
- `amount_minor bigint`;
- `currency char(3)`;
- `status varchar(32)`, whose only first-slice value is `initiated`;
- `aggregate_version bigint`;
- `initiated_at timestamp(6) with time zone`.

There is **no mutable `updated_at`**, so a duplicate delivery has nothing legitimate to touch.

The same forward migration adds `payment_outbox.projection_failure_generation`, a non-negative
integer defaulting to `0`, with named non-negative check constraints for it and for
`projection_failure_count`. The generation is a monotonic fence that lets a late failure recorder
tell a delayed delivery apart from a new operator retry cycle.

No secondary index is added in T8. The point lookups this slice performs are already served by the
primary and unique keys above; a later index requires measured query-plan evidence.

## Query contract

**Implemented by T9, with T11 Slice 2's gate check landed inside it.** Every branch below is live,
including `503`.

`GET /api/payments/{paymentId}` is merchant-scoped and resolves the merchant only through
`MerchantContext`. The `payment_details` lookup and, only when that lookup finds nothing, the
`payment_idempotency_receipts` fallback run inside one short PostgreSQL transaction owned by a single
query adapter on one injected default connection, and **T11 Slice 2 prepends its gate check to that
same transaction**, before either read, without relocating either one:

- owned `PaymentDetails` -> `200 OK` carrying exactly `payment_id`, `amount_minor`, `currency`,
  `status` and `initiated_at`. `initiated_at` is UTC ISO-8601 with six fractional digits and a
  `+00:00` offset, matching durable event-time precision. `merchant_id`, `source_event_id` and
  `aggregate_version` are internal and are never serialized.
- owned idempotency receipt without projection -> `202 Accepted` carrying exactly `payment_id` and
  `status: projection_pending`, plus `Retry-After: 1`. That is read-model transport state, not a
  domain payment status, and nothing durable records it. The body carries no completion estimate and
  no queue or worker state.
- no owned projection or receipt -> `404 payment_not_found`. A malformed payment ID shares this exact
  contract and is refused inside the query boundary before PostgreSQL receives an invalid UUID, so a
  syntactically invalid unknown identifier is not a distinguishable error class.
- closed gate -> `503 projection_rebuilding` with `Retry-After: 1`. **T11 Slice 2 owns this branch**:
  the control row, the advisory-key derivation, and the shared gate lock. The gate check runs inside
  the query adapter's existing transaction, before the `payment_details` lookup, so the two reads and
  the gate stay on one connection and neither read moves. The adapter raises a typed
  `Modules\Payments\Application\Query\ProjectionRebuilding` and the Problem Details mapper renders it,
  keeping the controller's "decides nothing about the answer" property.
  The bounded rebuild closes the gate, and an abandoned run's durable row keeps it closed until recovery.
  Recorded in [ADR 0005](../../adr/0005-payments-projection-gate-and-rebuild-ownership.md).

A projected lookup short-circuits the receipt fallback, so an owned projection is never followed by
a receipt read. Another merchant's payment returns the same not-found shape as a missing one. The
query adapter, `DatabasePaymentQuery`, may read only `payment_details` and
`payment_idempotency_receipts` and writes nothing; the surrounding authenticated request still
performs its normal Sanctum and `MerchantContext` identity queries. The endpoint never rehydrates
`PaymentAggregate` and never treats Redis/SQS state as proof that a payment exists.

## Failure handling

- Projection failure rolls back the read row, receipt, and completion marker together.
- Confirmed handler failure metadata is recorded separately from the rolled-back projection
  transaction.
- A hard worker crash leaves no false completion and relies on transport redelivery or outbox
  reconciliation.
- Work deferred by the rebuild gate does not consume transport or poison budgets. **Implemented in
  T11 Slice 2**, and structural rather than a rule: a deferred delivery (`ProjectionOutcome::GateDeferred`)
  raises no exception and locks no envelope, so there is no statement that could write a counter, a
  generation, an exhaustion timestamp, or an availability. Raising an exception instead would retire
  the message into `failed_jobs`, because the job has one attempt and no `try`/`catch`. See
  [ADR 0005](../../adr/0005-payments-projection-gate-and-rebuild-ownership.md).
- Stale messages delivered after a successful rebuild are ignored by recreated projector receipts.

**Crash evidence splits with T12.** T8 owns deterministic rollback and redelivery evidence from an
injected failpoint, plus a live Horizon stop, backlog, and restart smoke. T12 retains the
barrier-coordinated SIGTERM/SIGKILL process suite, so a literal process kill is not T8 acceptance.
See the [resilience test plan](../../testing/payments-resilience.md).

## Failure classification

Implemented by T8. Only a **confirmed deterministic handler failure** consumes
the projection failure budget.

Countable:

- envelope or event rejection, unsupported alias or schema version, malformed canonical payload;
- contradictory payment identity;
- deterministic failure inside the projector itself.

Not countable:

- worker termination, queue wait, and backlog;
- Redis or other transport failure;
- PostgreSQL connection loss, and transaction begin, commit, or rollback uncertainty;
- lock, serialization, deadlock, and statement-timeout errors;
- failure of the failure-metadata write itself;
- gate deferral.

`last_projection_error` stores only a fixed safe category from a closed set. Logs carry the
exception class, SQLSTATE, and safe identifiers, never raw exception text, payload, credentials, or
idempotency keys. Database failures are classified by **SQLSTATE class and owned constraint
identity**, never by matching exception messages, so a driver wording change cannot silently move a
failure between budgets.

Failure metadata is written in a **separate short transaction, after the projection transaction has
been rolled back**, never inside it. Three rules bound that write:

- An unknown COMMIT outcome writes no failure metadata at all. The next delivery rereads canonical
  receipt, read-row, and marker state and decides.
- The recorder re-locks the outbox row and applies metadata only while the row is still incomplete,
  non-exhausted, and on the `projection_failure_generation` captured under the projection
  transaction's lock. Otherwise it is a safe logged no-op, so a late recorder can poison neither a
  winning duplicate nor a reset cycle.
- A failed metadata write leaves the row incomplete and non-exhausted, so delivery stays retryable
  rather than falsely counted.

The fifth confirmed failure sets `exhausted_at` in the same metadata transaction that raises the
count to five. Exhaustion belongs with the counter that triggers it; see
[outbox delivery](outbox-delivery.md) for the writer-ownership split.

## Operator retry

Implemented by T8. `payments:outbox:retry {eventId}` is the sole reactivation
path for an exhausted envelope, and it works under a lock on the outbox row.

It refuses without mutation when the event is unknown, already completed, or not exhausted. On an
exhausted row it clears `exhausted_at`, resets `projection_failure_count` to `0`, clears
`last_projection_error`, increments `projection_failure_generation`, sets `available_at = now()`,
and preserves publication and transport history. The historical failure survives in structured logs
and retained failed-job records.

Setting `available_at` is the **one narrow exception** to T7's relay ownership of that column: a
successful reset makes the corrected row immediately eligible again in the same transaction.
Ordinary transport scheduling stays the relay's, unchanged. See
[outbox delivery](outbox-delivery.md) for that ownership split rather than a restatement here.

**T10's `payments:outbox:repair {eventId} --delivery-quiesced` does not weaken this.** It repairs one derived
outbox envelope and preserves `exhausted_at`, `projection_failure_count` and
`projection_failure_generation`, so operator retry stays the sole reactivation path. It reads the
named `payment_details` projector receipt to decide the completion marker but **never creates or
modifies a projector receipt or a `payment_details` row**, so a receipt without its read row survives
repair as a reported inconsistency; read-model recovery is T11's rebuild. Contract in
[verification and rebuild](verification-and-rebuild.md#targeted-outbox-repair).

## Acceptance criteria

T8:

- First delivery creates one receipt and one read row atomically.
- Duplicate delivery changes no business fields and may repair only a missing completion marker.
- A receipt without its read row, and a contradictory payment identity, both fail visibly.
- An exhausted row is a logged no-op; a missing envelope is a typed non-countable failure.
- Rollback and worker-crash paths leave the message retryable.
- Only confirmed handler failures count, and the fifth sets `exhausted_at`.
- A late recorder cannot charge a completed row or a newer failure generation.
- Operator retry resets poison state under a row lock and preserves transport history.

T9:

- Projected, pending, missing, malformed, and other-merchant HTTP states have stable contracts.
- Malformed, missing, and foreign requests use the same `payment_not_found` problem schema, and
  missing and foreign requests for the same valid payment ID produce the same response body.
- The `payment_details` lookup and, when it is needed, the `payment_idempotency_receipts` fallback
  run inside one adapter-owned transaction on one injected connection object; the query adapter reads
  no other table and issues no write.

T11:

- The rebuilding HTTP state has a stable contract, and gate lock and protected work use the same
  PostgreSQL backend connection.
- That backend-affinity evidence is T11's alone: it cannot be produced before a gate lock exists to
  prove it against.

## Implementation evidence

**Satisfied by T8**, which shipped ten production types - eight under
`Modules/Payments/app/Infrastructure/Projection/`, plus
`Modules\Payments\Infrastructure\Console\PaymentOutboxRetryCommand` and the root
`App\Providers\HorizonServiceProvider` - one forward migration
(`2026_08_31_000000_create_payment_projection_schema`), `config/horizon.php`, the
`payments.projection` configuration block, the `laravel/horizon` dependency, and the paired
`projection` Compose profile. Every row below was observed through `make check` against
PostgreSQL 18.4 and Redis 8.10.0, except the last four, which came from the retired T8 projection rehearsal against live `scheduler` and `horizon` processes.
Those four rows are historical observations; ANT-18 removed their harness and CI execution.

**Each row was also confirmed by mutation**: the named behaviour was removed or inverted in the
production source and the cited test was re-run to prove it fails. Ten defects were found that way
during implementation, including four in tests that passed for the wrong reason.

| # | Invariant | Proving test | Observed mechanism | Status |
|---|---|---|---|---|
| P-1 | First delivery commits receipt, read row and completion marker in one transaction | `PaymentProjectionConsumerTest::it commits receipt, read row and completion marker together on a first delivery` | `xmin` read from a second backend is identical across `payment_projector_receipts`, `payment_details` and `payment_outbox` - PostgreSQL's own statement that one transaction wrote all three | **PASS** |
| P-2 | An exact duplicate changes nothing at all | `…it leaves an exact duplicate delivery entirely alone` | Whole rows on both tables compared byte for byte before and after; one receipt, one read row | **PASS** |
| P-3 | A duplicate repairs only a missing completion marker | `…it repairs only a missing completion marker on a duplicate delivery`, `…it does not move a completion marker that is already set` | The marker `UPDATE` carries `and projection_completed_at is null`; its affected-row count *is* the outcome, so `MarkerRepaired` and `AlreadyProjected` cannot be confused | **PASS** |
| P-4 | A receipt without its read row fails visibly rather than being recreated | `…it refuses a duplicate whose read row is missing rather than recreating it` | `projection_read_row_missing`; the marker stays null so the envelope keeps surfacing, and the receipt is not deleted | **PASS** |
| P-5 | A read row that describes a different payment fails visibly | `…it refuses a duplicate whose read row describes a different payment` | `projection_read_row_mismatch`; without the value check the delivery would have stamped the marker over another payment's row | **PASS** |
| P-6 | Contradictory identity is refused, never upserted | `…it refuses to overwrite a read row another writer already claimed`, `…it cannot be handed two envelopes for one payment in the first place` | `payment_details_pkey` raises 23505 and the whole transaction rolls back; the positive control proves two envelopes for one payment are structurally unreachable through the consumer | **PASS** |
| P-7 | An exhausted envelope is an observability-only no-op that releases its lock | `…it treats an exhausted envelope as an observability-only no-op` | No receipt, no read row, envelope row unchanged byte for byte; `transactionLevel()` and the PDO both clean, and a second backend can take the row lock immediately afterwards | **PASS** |
| P-8 | A missing envelope is a typed, non-countable, visible failure | `…it fails visibly when the delivered event id addresses no envelope` | `ProjectionEnvelopeMissing` escapes to the worker; all three tables stay empty, so no orphan metadata is created | **PASS** |
| P-9 | Every malformed or unsupported envelope is refused before any write | `…it refuses a malformed or unsupported envelope and writes nothing` (14 datasets) | Alias, schema version, aggregate version, payload shape, types, positivity, currency form, occurrence time and both identity agreements; no receipt is ever left behind, because the decode precedes the claim | **PASS** |
| P-10 | Business time survives to microsecond precision | `…it commits receipt, read row and completion marker together on a first delivery` | `initiated_at = '2026-08-21T10:11:12.123456+00:00'::timestamptz` compared in the database. Measured defect: binding a `CarbonImmutable` made Laravel format it `Y-m-d H:i:s`, silently truncating to whole seconds | **PASS** |
| P-11 | Only confirmed handler failures count, and the fifth exhausts | `ProjectionFailureRecorderTest::it exhausts the envelope on exactly the fifth confirmed failure`, `…it charges five confirmed deliveries and then stops delivering` | Counts 1-4 leave `exhausted_at` null; the fifth sets it in the SAME tuple as the count, so no window exists between them; the sixth delivery is a `skipped_exhausted` no-op | **PASS** |
| P-12 | Backlog, lock timeouts and duplicates consume no poison budget | `…it does not charge a lock timeout, however many times it happens`, `…it does not charge a non-countable failure raised after the envelope was locked`, `…it does not charge a delivery that lost a race to a successful duplicate` | Five real `55P03`s leave the count at 0; a timeout on the read row's FK check - past the point the generation was captured - is likewise not charged | **PASS** |
| P-13 | The countable/non-countable set is closed and pinned | `ProjectionFailureClassificationTest` (27 table-driven cases) | Eight refusal categories plus `projection_handler_failed`; `40P01`/`40001`/`55P03` referenced from `RetryingAtomicTransaction::RETRYABLE` rather than copied, `57014` and the whole `08` class non-countable, unrecognised SQLSTATEs not charged | **PASS** |
| P-14 | An unknown COMMIT outcome writes no failure metadata | `PaymentProjectionConsumerTest::it classifies a failure after a landed commit as an unknown commit outcome`, `…it classifies a COMMIT that itself throws as an unknown outcome, not as a rollback` | A deferred constraint trigger makes PostgreSQL fail the COMMIT itself; the PDO is asked *before* the rollback, because `Connection::commit()` decrements `$this->transactions` only after `getPdo()->commit()` returns. The recorder is proved never to have been called, by its absence from the log | **PASS** |
| P-15 | A late recorder cannot charge a completed, exhausted or re-generated row | `ProjectionFailureRecorderTest::it declines to poison a row a concurrent duplicate has already completed` (3 datasets), `…it cannot charge a reset cycle with a failure from the cycle before it` | Four guards in one `UPDATE … RETURNING` `WHERE`, so there is no check-then-write window; the ABA case walks exhaust → operator reset → late recorder refused → positive control on the new generation charges normally | **PASS** |
| P-16 | A failed metadata write leaves the envelope retryable | `…it leaves the envelope retryable when it cannot persist the failure` | A real row-lock conflict; the row stays incomplete and non-exhausted, the original failure is what propagates, and both Laravel and PDO transaction state are clean afterwards | **PASS** |
| P-17 | Neither consumer nor recorder writes a relay-owned column | `PaymentProjectionConsumerTest::it never writes a column the relay or the failure recorder owns`, `ProjectionFailureRecorderTest::it never writes a column the relay owns` | Transport columns compared as a whole group across first delivery, marker repair and failure recording | **PASS** |
| P-18 | The generation fence is captured under the lock, not re-read afterwards | `…it captures the failure generation the row carried under the lock` | `payment_outbox` is read exactly once, by the locking `SELECT`, proved through `DB::listen`; an implementation re-reading it inside `abort()` fails the test | **PASS** |
| P-19 | Concurrent duplicates serialise on the envelope row lock | `…it serialises a concurrent duplicate on the envelope row lock`, `…it converges to one receipt, one read row and one marker when a duplicate arrives after the winner` | A real `FOR UPDATE` from a second backend; the consumer blocks on the *SELECT* - proved by the absence of any successful write in `DB::listen` - and converges to one receipt and one read row afterwards | **PASS** |
| P-20 | The projection statement timeout is applied inside the transaction and dies with it | `…it applies its statement timeout inside the transaction and lets it die with it`, `…it cancels a statement that outlives the bound rather than waiting for the worker` | `SHOW statement_timeout` reads `2s` from inside a `TransactionCommitting` listener and `0` afterwards; a bound deliberately lowered to 150 ms produces a real `57014` against a held lock | **PASS** |
| P-21 | `statement_timeout < worker timeout < retry_after`, strictly | `ProjectionConfigurationTest` (18 cases), `HorizonSupervisionTest::it keeps the three timeouts strictly ordered in the real configuration` | 2 s < 15 s < 90 s, each read from the file that owns it; equality is refused as firmly as inversion | **PASS** |
| P-22 | Horizon supervises only `payments-projection`, one process, one attempt | `HorizonSupervisionTest::it supervises exactly one queue…`, `…it gives Horizon no queue the relay does not publish to`, `…it runs one process with one attempt and the accepted worker timeout` | Read through Horizon's own `ProvisioningPlan`, not from the config array. Measured defect: `config()` is unusable during config loading and `horizon.php` loads before `payments.php`, which silently yielded 0 processes, 0 tries and a 0-second timeout | **PASS** |
| P-23 | Horizon's metadata never shares the relay's bounded transport | `…it stores its own bookkeeping away from the Payments transport`, `PaymentRelayRuntimeContractTest::it bounds the live relay client rather than inheriting the shared connection settings` | `horizon.use = 'horizon'` on its own logical database; the relay's `max_retries => 0` and 0.25 s timeouts are unchanged | **PASS** |
| P-24 | The dashboard is closed unless an operator opens it, in local only | `…it registers the dashboard route but refuses it by default`, `…it opens only when the flag and the environment both agree` (5 datasets) | The package has no `enabled` switch, so `Horizon::auth()` is written explicitly and requires both the flag and the environment | **PASS** |
| P-25 | Operator retry resets poison state under a row lock and preserves transport history | `PaymentOutboxRetryTest::it clears the poison state, advances the fence and preserves publication history`, `…it waits for a delivery in flight rather than reading state about to change` | `exhausted_at` cleared, count zeroed, category cleared, generation incremented, `available_at = now()`; publication and transport columns compared as a group; a held row lock makes the retry block on its own `SELECT` | **PASS** |
| P-26 | Retry refuses unknown, completed and non-exhausted envelopes without mutation | `…it refuses an unknown event without mutating anything`, `…it refuses a completed or non-exhausted envelope without mutating anything` (4 datasets) | Whole-row byte comparison after each refusal; exit code 1 and a fixed reason in both the JSON output and the log | **PASS** |
| P-27 | Queued messages published before T8 still deserialise | `…it still deserialises a message published before the body changed` | A hand-built legacy payload with the fully-qualified name written as a literal; the consumer arrives as a `handle()` parameter so the serialised property list is still exactly `eventId` | **PASS** |
| P-28 | The job acknowledges nothing it did not project | `…it lets a consumer failure escape the job rather than acknowledging it` | The exception reaches the caller and the failure is still charged, so the job cannot mark an envelope delivered while nothing was projected | **PASS** |
| - | Paired startup: scheduler and Horizon both healthy from one command | Historical T8 process rehearsal (retired) | `horizon:status` exits 0 and `payments:outbox:status` reports a fresh heartbeat, against live processes in a disposable Compose project | **PASS** |
| - | A stopped consumer builds a backlog that consumes no poison budget | Historical T8 process rehearsal (retired) | The relay publishes (`publish_count = 1`) while Horizon is stopped; count 0, no `exhausted_at`, no `last_projection_error` | **PASS** |
| - | Restart convergence | Historical T8 process rehearsal (retired) | After `start horizon`, exactly one receipt and one read row, with the read row's fields matching the envelope | **PASS** |
| - | Duplicate delivery through the live pipeline converges | Historical T8 process rehearsal (retired) | The completion marker is cleared, the relay republishes (`publish_count = 2`), and the second delivery repairs only the marker: still one receipt, one read row, count 0 | **PASS** |

**What T8 did not prove.** T8 did not prove a literal SIGTERM/SIGKILL process matrix.
Its crash evidence includes a deterministic in-process commit-boundary failpoint.
The live Horizon stop/backlog/restart smoke and T12 barrier-coordinated process suite are historical
evidence retired from CI by ANT-18.
T8 did not prove a multi-process consumer race: concurrency is proved with two PostgreSQL backends
inside one PHP process, not with two workers. No load, throughput or query-plan claim, and therefore no secondary
index. No rebuild-safety claim: **the shared projection gate did not exist at T8** - it landed in
T11 Slice 2, see the reciprocal obligation in
[verification and rebuild](verification-and-rebuild.md#t8s-reciprocal-obligation-discharged-by-t11-slice-2)
and its [Slice 2 evidence](verification-and-rebuild.md#t11-slice-2-implementation-evidence).
`GET /api/payments/{paymentId}` did not exist at T8, so the read model was not yet observable
through HTTP; **T9 supplies it below**.

**Satisfied by T9**, which shipped six production types - four under
`Modules/Payments/app/Application/Query/` (`PaymentQuery`, `ProjectedPayment`, `PendingPayment`,
`PaymentNotFound`), `Modules\Payments\Infrastructure\Persistence\DatabasePaymentQuery` and
`Modules\Payments\Http\Controllers\QueryPaymentController` - plus one route, one scoped binding and
one `ProblemType` case, plus one `dontReport` entry. **No migration, no index, no Composer
dependency and no process.** Every row below was observed through `make check` against PostgreSQL 18.4.

| # | Invariant | Proving test | Observed mechanism | Status |
|---|---|---|---|---|
| Q-1 | An owned projection returns exactly the five contract fields, in wire order | `QueryPaymentTest::it returns the owned projection as exactly five fields in wire order`, `PaymentQueryTest::it returns the owned projection with exactly the projected fields` | The whole decoded document compared order-sensitively, and the whole result object compared by value; `merchant_id`, `source_event_id` and `aggregate_version` are absent from both. `amount_minor` is asserted to be an int, because PDO's pgsql driver may hand a bigint back as a string | **PASS** |
| Q-2 | `initiated_at` is UTC ISO-8601 with six fractional digits and a `+00:00` offset | `PaymentQueryTest::it preserves the stored instant as UTC with six fractional digits` (3 datasets), `…it normalises to UTC itself rather than trusting how the session renders the instant` | Microseconds survive and a whole second still renders `.000000`. The session TimeZone is UTC by default, so `timestamptz` renders `+00` whatever offset was inserted and the adapter's `->utc()` would be unfalsifiable; the second test sets the session to `Europe/Kyiv` first, so PostgreSQL hands back `+03` and only the adapter's own normalisation can produce the expected value | **PASS** |
| Q-3 | An owned receipt without a projection returns the pending contract with `Retry-After: 1` | `QueryPaymentTest::it returns the pending contract with Retry-After when only the receipt exists`, `PaymentQueryTest::it falls back to the owned receipt when the projection has not caught up` | Exactly `payment_id` and `status: projection_pending`; `202`, `application/json`, `Retry-After: 1`. The pending result carries the id and nothing else, so no completion estimate or queue state can be serialized | **PASS** |
| Q-4 | A projected lookup short-circuits the receipt fallback | `PaymentQueryTest::it short-circuits the receipt fallback when the projection exists` | Both rows exist - the normal steady state - and `DB::listen` records exactly one statement, naming `payment_details` and not `payment_idempotency_receipts`. An implementation that read the receipt anyway would answer identically and fail only here | **PASS** |
| Q-5 | A payment neither table knows returns the complete `payment_not_found` document | `OpenApiContractTest::it answers an unknown payment exactly as the documented 404 says` | The whole problem document equals the documented example, itself derived from `ProblemType`, with `instance` echoing the requested path; plus `application/problem+json` and the **absence** of `Retry-After`; nothing about an unknown payment is transient | **PASS** |
| Q-6 | Missing and foreign are indistinguishable at the wire, on both tables | `QueryPaymentTest::it cannot be told apart from a missing payment when the payment is someone else` (2 datasets) | Same id, same caller, one difference: whether another merchant's row exists. Response **bytes**, status and `Content-Type` compared, so a difference in key order or in `instance` would fail as surely as a different `code`. Run for a foreign projection **and** a foreign pending receipt, because those are two rows on two tables reached by two lookups | **PASS** |
| Q-7 | Foreign ownership is refused on both tables, and the owner is still served | `QueryPaymentTest::it answers payment_not_found for another merchant projection and serves its owner`, `…for another merchant pending receipt and serves its owner`, through the real transactional query adapter | Each refusal is followed by the **owner's** successful `200`/`202` on the same row, so the refusal cannot pass because a fixture never landed | **PASS** |
| Q-8 | The refusal is non-vacuous: deleting the row that produced it flips the answer | `PaymentQueryTest::it stops answering pending the moment the receipt it answered from is deleted` | Pending is observed first, exactly one receipt row is deleted (`$deleted === 1`), and the same merchant/payment lookup then raises `PaymentNotFound` | **PASS** |
| Q-9 | A malformed id shares the payment contract and never reaches PostgreSQL | `QueryPaymentTest::it gives a malformed payment id the payment contract, never the generic one` (2 datasets), `PaymentQueryTest::it refuses a malformed payment id without letting PostgreSQL see it` (4 datasets) | `payment_not_found`, not the generic `not_found`, with `instance` echoing the requested path; below HTTP, `DB::listen` records **no statement at all**, which is stronger than "no 22P02 surfaced" - a route constraint or a caught `QueryException` would each leave one behind | **PASS** |
| Q-10 | Both lookups run inside one adapter-owned transaction on the injected connection | `PaymentQueryTest::it runs both lookups in one transaction on the injected connection` | Exactly one `TransactionBeginning` and one `TransactionCommitted`; both statements captured at `transactionLevel() === 1` at execution time; the injected connection compared to `DB::connection()` **by identity**, which is what T11 relies on when it prepends a connection-affine gate to this same transaction. In the Integration suite, not Feature, because a `RefreshDatabase` wrapper would make the adapter's transaction a savepoint and fire neither event | **PASS** |
| Q-10b | The refusal path leaves no transaction open | `PaymentQueryTest::it refuses a payment id neither table knows and leaves no transaction open` | `transactionLevel()` is 0 and the PDO reports no open transaction after the exception - the most frequently reached error path, and the one that throws from inside the transaction callback | **PASS** |
| Q-11 | The query path reads two tables and writes nothing | `PaymentQueryTest::it reads only the two allowed tables and writes nothing` | Every statement across all three reachable states must start with `select`, and every table it names - matched on `from` **and** `join`, so a join cannot smuggle a third in - must be `payment_details` or `payment_idempotency_receipts`. A positive allowlist rather than a list of forbidden names, which would pass any table nobody predicted: `payment_projection_controls` above all, the read T11 prepends here | **PASS** |
| Q-12 | `payment_not_found` is reachable only through the query endpoint's own exception type | `QueryPaymentTest::it leaves an unknown api path on the generic not_found contract` | An unmatched `/api/*` path still answers `not_found`; the mapper matches `PaymentNotFound` by type above the 404 status fallback, so a status-driven mapping would have been caught here | **PASS** |
| Q-13 | The merchant is resolved once, through `MerchantContext` only | `QueryPaymentTest::it resolves the merchant through MerchantContext exactly once` | A counting `MerchantContext` double records exactly one call. `AuthenticatedMerchantContext` memoises nothing, so a controller asking twice would do the identity work twice and no response assertion would notice | **PASS** |
| Q-14 | The unauthenticated contract is unchanged | `OpenApiContractTest::it refuses both operations unauthenticated, exactly as the shared 401 documents it` | `401`, `application/problem+json`, and the whole document equal to the shared documented example for both routes, with `instance` echoing the requested path. Every authenticated test uses a **real Sanctum bearer token** and `Auth::forgetGuards()` between requests, never `Sanctum::actingAs`, so an isolation claim cannot pass by reusing the first token's merchant | **PASS** |
| Q-15 | A not-found payment is not reported to the log | `QueryPaymentTest::it does not report a not-found payment to the log` | `shouldReport()` is false for `PaymentNotFound` and true for an unrelated `RuntimeException`. Laravel already declines to report the framework's `NotFoundHttpException`; without the `dontReport` in `bootstrap/app.php` this endpoint would be the one 404 in the API writing an error-level line with a stack trace once per request, at whatever rate a client asks for identifiers it does not own | **PASS** |

**What T9 did not prove.** No load, throughput, latency or query-plan claim, and therefore no new
index. At T9, no rebuild-safety claim and no backend-affinity claim across a gate lock and its
protected read: **the shared projection gate did not exist yet**, so `503 projection_rebuilding` had
no producer. T11 Slice 2 closed that gap - both the gate check and its backend-affinity evidence now
live in `PaymentQueryTest`'s closed-gate section, per
[Slice 2 evidence](verification-and-rebuild.md#t11-slice-2-implementation-evidence). The bounded
batch rebuild that would close the gate for real is still T11's. The absence of Redis from the query
path is established by source inspection only - the SQL listener that pins the table allowlist cannot
observe a Redis call.
The historical T8 process rehearsal was not extended by T9 and was retired in ANT-18.

## Related

- [Outbox delivery](outbox-delivery.md)
- [Verification and rebuild](verification-and-rebuild.md)
- [Resilience test plan](../../testing/payments-resilience.md)
