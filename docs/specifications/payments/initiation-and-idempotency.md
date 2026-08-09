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

Before production migrations proceed, T3 must use the exact Composer-resolved Laravel and Spatie
versions to prove:

- receipt, event, and synchronous outbox use one PostgreSQL transaction and backend connection;
- a forced outer exception rolls back all three;
- the handler finishes before aggregate persistence returns;
- a retry creates fresh objects from stable context and commits one logical event/outbox row.

Failure of any invariant stops feature work and requires an explicit design revision.

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
