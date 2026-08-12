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

- requires Sanctum authentication and `Idempotency-Key`;
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
`instance`, and `code`; validation also includes `errors`. The slice defines at least
`unauthenticated`, `validation_failed`, `idempotency_payload_mismatch`,
`idempotency_contention`, `projection_rebuilding`, and `payment_not_found`.

## Atomic write

Before opening the transaction, build immutable command context containing canonical validated
input, candidate payment UUID, event UUID, event occurrence time, and the complete stable success
response. Serialize the response body exactly once.

Inside one outer PostgreSQL transaction:

1. Insert a complete idempotency receipt with `INSERT ... ON CONFLICT DO NOTHING` against unique
   `(merchant_id, idempotency_key_hash)`.
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

`PaymentInitiated` is immutable and includes:

- `event_id`, `payment_id`, and `merchant_id`;
- `amount_minor` and canonical `currency`;
- `occurred_at`;
- stable event alias or schema version.

Raw idempotency keys are hashed separately for lookup and never enter events, outbox payloads, or
logs. Hashing gives fixed-width lookup and reduces casual plaintext exposure; it is not encryption.

## Schema invariants

`payment_idempotency_receipts` has a UUID primary key, merchant ID, 64-character key hash and
request fingerprint, non-null payment ID, HTTP status, canonical response text, `Location`,
timestamps, unique `(merchant_id, idempotency_key_hash)`, indexed `(merchant_id, payment_id)`, and a
global unique payment ID.

Spatie `stored_events` retains its aggregate UUID/version concurrency constraint and monotonically
increasing global ID. Idempotency receipts are retained indefinitely in this slice.

## Blocking compatibility evidence

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
