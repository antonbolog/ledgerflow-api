# Payment Initiation and Idempotency Specification

## Given

An authenticated user belongs to exactly one merchant. A client wants to initiate one USD payment
and may repeat the HTTP request because it did not receive or trust the first response.

## Problem

Rate limiting can reduce request frequency but cannot prove that retries create one business
operation. A process may crash between idempotency reservation, event persistence, and queue
publication. Concurrent requests may race on the same key.

## Decision

PostgreSQL owns durable idempotency. Reservation, `PaymentInitiated`, outbox creation, and the exact
successful response commit atomically on one connection. A Redis lock may later reduce contention,
but it is never authoritative.

## HTTP contract

`POST /api/payments`:

- requires a Passport bearer token on the `api` guard and `Idempotency-Key`;
- resolves `merchant_id` from the authenticated user and rejects client-selected ownership;
- accepts a key of 1–255 bytes and documents it as a non-secret opaque identifier;
- accepts integer `amount_minor` from `1` through `100_000_000` inclusive;
- canonicalizes currency to uppercase and accepts only `USD`;
- generates UUIDv7 payment and stable event identifiers on the first accepted request;
- returns `202 Accepted`, `payment_id`, `status: initiated`, and `Location`;
- replays the same status, exact stored body bytes, and `Location` for an identical merchant/key
  and canonical payload;
- returns `409 idempotency_payload_mismatch` when the key is reused with another payload;
- scopes the key by merchant, so two merchants may use the same value independently.

All API failures use `application/problem+json` with stable `type`, `title`, `status`, `detail`,
`instance`, and `code`; validation also includes `errors`.

**T6 defines seven codes and owns all of them**: `unauthenticated` (401), `validation_failed` (422),
`idempotency_payload_mismatch` (409), `idempotency_contention` (503, with `Retry-After: 1`), plus
`not_found` (404), `method_not_allowed` (405) and `internal_error` (500) so that no `/api/*` failure
can escape in another shape. `type` is the relative URI reference `/problems/{code}` with hyphens,
which RFC 9457 permits and which resolves against the request URI; `code` is the stable identifier
and everything else derives from it.

`payment_not_found` **arrived with T9's query endpoint**, which raises it for a malformed, unknown or
foreign payment id alike. `projection_rebuilding` belongs to **T11's projection gate**, which owns
the only code path able to produce it, and deliberately does not exist yet - an enum case with no
producer is a promise nothing keeps.

Mapping is by **exception type first**, status code only as a fallback. This is required rather than
stylistic: Laravel's `prepareJsonResponse()` gives any non-`HttpException` a 500 before the hook
runs, and the Application-layer exceptions cannot implement Symfony's `HttpExceptionInterface`
without breaking the module boundary rule — so a status-driven mapper would ship the `409` as a
`500`. `detail` is a fixed client-safe sentence per code and never an exception message outside
`app.debug`, because that string routinely carries SQL, table names and identifiers.

## Atomic write

Before opening the transaction, build immutable command context containing canonical validated
input, candidate payment UUID, event UUID, event occurrence time, and the complete stable success
response. Serialize the response body exactly once.

Inside one outer PostgreSQL transaction:

1. Insert a complete idempotency receipt with
   `INSERT ... ON CONFLICT (merchant_id, idempotency_key_hash) DO NOTHING RETURNING id`. **The
   conflict target is named, not omitted**: this table also carries a global unique on `payment_id`,
   and an untargeted clause would swallow that collision too, turning a permanent condition into a
   retryable `503`. See [ADR 0003](../../adr/0003-postgresql-idempotency-arbitration.md).
2. If no row was inserted, load the committed winner. Return its stored response when fingerprints
   match; otherwise fail with `409`.
3. For a new receipt, create a fresh `PaymentAggregate` and `PaymentInitiated` from immutable
   context.
4. Persist the aggregate through Spatie's transaction-aware path.
5. Run exactly one synchronous Payments handler that inserts the unique outbox envelope.
6. Commit receipt, stored event, and outbox together.

The outbox writer must not implement `ShouldQueue` and must not run `afterCommit`. Live Spatie
dispatch must not also update `PaymentDetails`.

## Transaction retry

Retry the complete outer transaction closure no more than three times only for:

- `40P01` — deadlock detected;
- `40001` — serialization failure;
- `55P03` — lock unavailable, including configured lock timeout.

Laravel's inner database transaction uses one attempt so retry budgets do not multiply. Business,
validation, integrity, and unlisted database failures do not retry. Exhaustion returns `503`,
`Retry-After: 1`, and `idempotency_contention` without partial state.

Every attempt creates fresh mutable aggregate and event objects. Stable IDs, occurrence time,
input, fingerprint, and response bytes are reused.

## Event contract

**Implemented by T5** as `Modules\Payments\Domain\Payment\Events\PaymentInitiated`, a `final` class
extending Spatie's `ShouldBeStored` with six `public readonly` promoted constructor properties. Those
property names are the durable payload keys, and the stored document contains **exactly** them:

| Payload key | Type | Notes |
|---|---|---|
| `eventId` | `string` | canonical lowercase UUIDv7 |
| `paymentId` | `string` | canonical lowercase UUIDv7; equals `stored_events.aggregate_uuid` |
| `merchantId` | `int` | positive |
| `amountMinor` | `int` | 1 … 100 000 000 inclusive |
| `currency` | `string` | canonical uppercase `USD` only |
| `occurredAt` | `string` | ISO-8601 UTC, microsecond precision |

The class validates all six in its constructor. That is the last line of defence, not the
user-facing one: T6's request validation returns `422` first, and a domain exception reaching the
HTTP path means the handler skipped canonicalization.

**Stable identity is the alias, not the class name.** `PaymentInitiated::ALIAS` is
`payments.payment_initiated`, mapped in `config/event-sourcing.php`, and it is what
`stored_events.event_class` and `payment_outbox.event_type` carry — so the namespace can move
without rewriting history. `enforce_event_class_map` stays `false`; a discovery test in
`PaymentInitiationPersistenceTest` requires the map to name exactly the production storable events
instead. It was originally kept off because the T3/T4 spikes, retired in ANT-19, persisted unmapped
test-only fixtures.

**Schema version is `1`, from an overridden `eventVersion()` method** rather than Spatie's
`#[EventVersion]` attribute. The attribute would be a third vendor type in `Domain` and
[ADR 0002](../../adr/0002-payments-domain-event-sourcing-allowlist.md) permits exactly two. Spatie's
`ObjectNormalizer` filters `eventVersion` out of the payload unless the child declares a *property*
of that name, so the override changes the stored key set not at all.

**`occurredAt` is a string, deliberately.** Symfony's `DateTimeNormalizer` sits in Spatie's default
normalizer chain and formats with RFC3339, which has **no sub-second component**, so a
`DateTimeImmutable` property would silently truncate business time to whole seconds inside the stored
payload. The canonical format is `Y-m-d\TH:i:s.uP` at offset `+00:00`, validated by a
format round-trip that also rejects overflowing dates such as `2026-02-30`.

Raw idempotency keys are hashed separately for lookup and never enter events, outbox payloads, or
logs. Hashing gives fixed-width lookup and reduces casual plaintext exposure; it is not encryption.
T5 proved only the narrow half: no schema column exists whose purpose is the raw key. **T6 discharged
the rest**, in `InitiatePaymentWriteTest::it writes the raw idempotency key to no durable column,
exception or log line`. The raw key is not a property of the command context and never becomes one; it drives
a 202, a 409 and a 422 through one key, then scans **every column of every row** of
`payment_idempotency_receipts`, `stored_events` and `payment_outbox` as encoded JSON — a whole-row
scan, so a future column carrying the key fails it — and every captured `MessageLogged` line, and
asserts the key appears in none of them nor in either failure response body. The listener is asserted
non-empty, so the log half cannot pass vacuously.

## Schema invariants

`payment_idempotency_receipts` has a UUID primary key, merchant ID, 64-character key hash and
request fingerprint, non-null payment ID, HTTP status, canonical response text, `Location`,
timestamps, unique `(merchant_id, idempotency_key_hash)`, indexed `(merchant_id, payment_id)`, and a
global unique payment ID.

Spatie `stored_events` retains its aggregate UUID/version concurrency constraint and monotonically
increasing global ID. Idempotency receipts are retained indefinitely in this slice.

## Blocking compatibility evidence

> **Historical evidence, retired in ANT-19.** The T3 spike suites and fixtures named in this section
> no longer exist or run. The same contracts are exercised against production code by the C2 and C6
> rows under [Implementation evidence](#implementation-evidence) and by `TransactionRetryTest`.

**Satisfied by T3.** Every invariant below was proved against the Composer-resolved stack — Laravel
13.24.0, `spatie/laravel-event-sourcing` 7.15.1, PostgreSQL 18.4, PHP 8.4 — by a test-only spike under
`Modules/Payments/tests/`. Production migrations are unblocked.

| # | Invariant | Proving test | Observed mechanism | Status |
|---|---|---|---|---|
| Q1 | Receipt, stored event and synchronous outbox share one transaction and one backend connection | `SpikeAtomicPersistenceTest::it writes all three inside one transaction on one backend` | The writer's session sees all three rows while the observer connection sees none, then all three after commit; all three tuples report the same `xmin`; one `pg_backend_pid()` across every probe; transaction depth exactly 1 | **PASS** |
| Q3 | The synchronous handler finishes before persistence returns | `SpikeAtomicPersistenceTest::it runs the outbox handler synchronously and never enqueues it` | Marker order `receipt → objects-built → outbox-handler → after-persist`, the outbox row readable on the line after `persist()` returns, and `Queue::assertNothingPushed()` under an active `Queue::fake()` | **PASS** |
| Q2 | A forced failure after persistence rolls back all three | `SpikeRollbackTest::it rolls back receipt, stored event and outbox when the failure comes after persist returns` | All four markers recorded, then all three tables empty from the observer and the spike connection back at transaction level 0 | **PASS** |
| Q2 | A throwing synchronous projector rolls back all three | `SpikeRollbackTest::it rolls back receipt, stored event and outbox when the synchronous projector throws` | Exception escapes the live `Projectionist` rather than being swallowed; markers stop at `outbox-handler` because `persist()` never returned; all three tables empty | **PASS** |
| Q4 | An allowlisted retry rebuilds fresh objects from stable context and commits one logical result | `SpikeTransactionRetryTest::it retries a real 55P03 and commits one logical event and outbox row` | Real `55P03` from `FOR UPDATE NOWAIT` against a lock held on a second connection and released only by `onRetry`; exactly 2 attempts; aggregate and event object identities differ per attempt while `payment_id`, `event_id`, `occurred_at` and `key_hash` stay byte-identical; one receipt, one stored event, one outbox row sharing one `xmin` | **PASS** |
| Q4 | Exhaustion stops at three attempts with no partial state | `SpikeTransactionRetryTest::it attempts an allowlisted failure at most three times` | Lock held throughout; captured SQLSTATEs `55P03, 55P03, 55P03`; exception propagates; all three tables empty | **PASS** |
| Q5 | A non-retryable failure is attempted exactly once | `SpikeTransactionRetryTest::it does not retry a real unique violation` | Real `23505` from a duplicate primary key, surfacing as `UniqueConstraintViolationException`; 1 attempt; `onRetry` never fired; no partial state | **PASS** |

**Not proved, and not claimed.** `40P01` and `40001` are on the retry allowlist but **were not produced
by this spike**; only `55P03` was raised against a real database. They are classified by the same
SQLSTATE path, so the risk is low, but no measurement backs them. A genuine deadlock needs two
concurrent processes, which belongs to the
[resilience test plan](../../testing/payments-resilience.md), not here. Nothing in this spike proves
multi-process concurrency or production retry behaviour.

### Constraints T5 and T6 inherit

- **The retry allowlist must be owned, not delegated.** Laravel's
  `Illuminate\Database\ConcurrencyErrorDetector` matches `40001` by code and `40P01` only through the
  English message `deadlock detected`, and does not recognise `55P03` at all — measured returning
  `false` for a real `55P03`. T6 must classify by SQLSTATE itself.
- **Read the SQLSTATE from `errorInfo[0]`, with `getCode()` as fallback, and unwrap
  `DeadlockException` first.** It extends `PDOException` but carries no `errorInfo` of its own, so an
  unwrapped read returns null and misclassifies a retryable failure as fatal. Both carry the SQLSTATE
  as a **string**, so comparison against an integer silently never matches.
- **A duplicate aggregate version never reaches SQLSTATE classification.**
  `AggregateRoot::persistWithoutApplyingToEventHandlers()` catches the `UniqueConstraintViolationException`
  from the `(aggregate_uuid, aggregate_version)` constraint and rethrows it as
  `CouldNotPersistAggregate::invalidVersion()`. Concurrency handling that keys off SQLSTATE will not
  see it.
- **`Projectionist` leaves `isProjecting` true after a throwing handler.**
  `applyStoredEventToProjectors()` sets the flag and clears it after the loop with no `finally`, so a
  projector failure leaves it set on that instance. Harmless per-request today; it matters wherever a
  retry follows a projector failure inside one application instance.
- **`catch_exceptions` is fixed at boot.** `Projectionist` copies it into `$this->catchExceptions`
  when the singleton is built, and the package resolves that singleton during `packageBooted()`.
  Changing the config later has no effect. `EVENT_PROJECTOR_CATCH_EXCEPTIONS=true` in any environment
  would make a throwing outbox writer silently leave the stored event committed without its outbox
  row.
- **`persist()` opens no transaction of its own**, so the caller's transaction is the only one and the
  observed nesting depth is 1. It does write each stored event twice — one `INSERT` then one `UPDATE`
  setting `meta_data` — which does not disturb `xmin`, since an in-transaction update keeps the
  inserting transaction's id.

### Harness limits

The spike ran on three connections against one `ledgerflow_test` database: the default connection as
a read-only **observer** (wrapped by `RefreshDatabase`, never committing), an unwrapped
`pgsql_spike` as the unit under test, and an unwrapped `pgsql_lock` supplying real contention. The
second connection is what makes a real `COMMIT` observable at all, since work on the wrapped default
connection would be a savepoint that never commits.

Every fixture, table and migration is test-only. The harness **is not parallel-safe**: it commits real
rows to a shared database, and `make test` deliberately does not pass `--parallel`. Retry is proved
single-process.

See [development environment](../foundation/development-environment.md) for the resolved package
version, the exact vendor base types, and what T3 deliberately did not add.

## Implementation evidence

**Satisfied by T6**, which shipped `POST /api/payments`, the Problem Details surface, the first
producer of `payment_idempotency_receipts` and the first producer of `payment_outbox`. Every row was
observed through `make check` against PostgreSQL 18.4.

The commit-boundary rows come from a suite that runs **without** `RefreshDatabase`, and that is what
makes them evidence at all: the trait wraps each test in a transaction, which turns the production
`DB::transaction()` into a savepoint, so `TransactionCommitting` never fires at depth 1, no second
connection can witness a commit, and an "all three rows exist" assertion would pass against a handler
that opened no transaction whatsoever. The suite refuses to run if it ever finds itself wrapped, and
provisions its own schema so it does not depend on having run after some other suite.

| # | Invariant | Proving test | Observed mechanism | Status |
|---|---|---|---|---|
| C2 | Receipt, stored event and envelope commit on one transaction and one backend | `InitiationCommitBoundaryTest::it commits receipt, stored event and outbox in one transaction on one backend` | An observer on a second backend pid counts zero rows at `TransactionCommitting` and one of each afterwards; all three tuples report the same `xmin`; one `pg_backend_pid()` across the whole write; `transactionLevel()` back to 0 | **PASS** |
| C2 | A failure at the last moment before `COMMIT` rolls all three back | `InitiationCommitBoundaryTest::it rolls all three back when the failure lands after everything, before COMMIT` | A throwing `TransactionCommitting` listener; all three tables empty from the observer, the merchant still present, and the connection genuinely clean — `getPdo()->inTransaction()` false, not merely `transactionLevel()` 0 | **PASS** |
| C2 | A throwing synchronous projector rolls all three back | `InitiationCommitBoundaryTest::it rolls all three back when a synchronous projector throws after the outbox row` | A second projector at a later `$weight` throws once the envelope exists and the exception escapes the live `Projectionist` — what `catch_exceptions => false` buys; the thrower is asserted to have seen exactly one outbox row, so "after the envelope" is not a guess | **PASS** |
| C3 | An identical retry replays the exact bytes, status and `Location` | `InitiatePaymentWriteTest::it replays the exact stored bytes, status and location for an identical retry` | `getContent()` compared as **bytes**, not re-encoded JSON; one receipt, one stored event, one envelope after both requests | **PASS** |
| C4 | A different canonical payload under one key conflicts and writes nothing new | `InitiatePaymentWriteTest::it returns 409 for the same key with a different payload and writes nothing new` | `409 idempotency_payload_mismatch`; durable row counts unchanged | **PASS** |
| C4 | The conflict target is the merchant/key key alone | `InitiatePaymentWriteTest::it reserves against the merchant/key constraint only` | A pre-seeded receipt colliding on `payment_id` under a *different* merchant/key raises a real `23505`, surfacing as `internal_error`, **not** as the retryable `idempotency_contention` an untargeted clause would have produced | **PASS** |
| C5 | Ownership comes only from the authenticated user | `InitiatePaymentRequestTest::it resolves the merchant from the bearer token exactly once`, `…it refuses a client-supplied merchant_id in any form, present is present` | A real Sanctum bearer token rather than `actingAs`, so a misconfigured guard cannot pass; a client-supplied `merchant_id` is `422`, not silently overridden | **PASS** |
| C6 | A real `55P03` retries with fresh objects and commits one logical result | `InitiationCommitBoundaryTest::it retries a real 55P03 and commits one logical result with fresh objects` | Contention injected *after* `persist()` by a third projector at a later weight, from a bounded `lock_timeout` against a row a second connection holds — no sleeps; two **distinct retained event objects** carrying identical `eventId` and `occurredAt`; one receipt, one stored event, one envelope on one `xmin`; `aggregate_version` 1, so the rolled-back attempt left nothing behind | **PASS** |
| C6 | Exhaustion stops at three attempts with no partial state | `InitiationCommitBoundaryTest::it exhausts at three attempts and leaves no partial state` | Captured SQLSTATEs `55P03, 55P03, 55P03`; three distinct event objects by identity; `IdempotencyContention` thrown; all three tables empty from the observer | **PASS** |
| C6 | Exhaustion is answered `503` with `Retry-After: 1` and no SQL vocabulary | `InitiatePaymentWriteTest::it answers exhausted contention with 503, Retry-After: 1 and problem details` | The HTTP shape against a bound double, so it dies if the mapper changes while the row above dies if the runner changes; `detail` asserted to contain neither lock vocabulary nor a table name | **PASS** |
| C6 | Only the three allowlisted states retry, classified by this repository | `TransactionRetryTest` (17 cases), `ModuleBoundaryTest::it never delegates concurrency classification to laravel` | `errorInfo[0]` preferred over `getCode()`, `DeadlockException` unwrapped first, string comparison; a non-retryable failure attempted exactly once; Laravel's own detector mechanically forbidden anywhere under `Modules/` | **PASS** |
| C7 | Every `/api/*` failure is Problem Details, and the web surface is untouched | `ProblemDetailsContractTest`, `InitiatePaymentRequestTest`, `OpenApiContractTest` | 404/405/401/422 all `application/problem+json` with the six members; Laravel's `{"message":"Unauthenticated."}` key proven absent; a non-API 404 proven *not* reshaped; malformed JSON pinned as `422`, since `Request::json()` decodes it to `[]` and throws nothing; a generic conflict proven **not** dressed as `idempotency_payload_mismatch`, and a generic 503 not as `idempotency_contention` | **PASS** |
| C1 | The envelope derives from the row just written, and leaves T7/T8 columns alone | `InitiatePaymentWriteTest::it derives every outbox column from the row the repository just wrote`, `…it leaves every T7 and T8 delivery column at its default` | `payload = stored_events.event_properties` compared as **jsonb**; `occurred_at` re-parsed and re-formatted rather than substring-matched; all three counters 0, both error columns and both completion timestamps null, `available_at` not null | **PASS** |
| C1 | Nothing is enqueued while writing the envelope | `InitiatePaymentWriteTest::it enqueues nothing while writing the envelope` | `Queue::assertNothingPushed()` under an active `Queue::fake()` | **PASS** |

**Not proved, and not claimed.** `40P01` and `40001` remain allowlisted and unit-classified but have
**never been produced against a real database** by any task — unchanged since T3, and this section
must not be read as changing it. The contention above is two connections in one process; genuine
multi-process racing stays with [T12](../../testing/payments-resilience.md). H-1's blocking probe used
two OS processes but one machine and one container, and says nothing about isolation levels other
than the `read committed` the application runs. Nothing here is a load, throughput or index-plan
claim.

## Acceptance criteria

- Sequential and concurrent identical requests converge on one payment, receipt, event, and outbox
  message.
- A conflicting payload creates no new durable state.
- Two merchants remain isolated.
- Boundary values for amount and idempotency-key length are covered.
- Every write-boundary failure rolls back all three records.
- Allowlisted retry and non-retryable error behavior is proven with PostgreSQL.

## Related

- [Payments flow](../../architecture/payments-flow.md)
- [Outbox delivery](outbox-delivery.md)
- [Resilience test plan](../../testing/payments-resilience.md)
