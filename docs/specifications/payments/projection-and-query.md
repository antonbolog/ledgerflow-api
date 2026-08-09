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

## Consumer transaction

For `(projector_name, event_id)`:

1. Acquire the Payments shared projection gate on the same database connection.
2. Load and validate the durable outbox envelope.
3. Insert a projector receipt with `ON CONFLICT DO NOTHING`.
4. When the insert succeeds, derive all business fields from the event and insert uniquely keyed
   `PaymentDetails`.
5. Set the outbox `projection_completed_at` marker.
6. Commit receipt, read model, and completion marker together.

If the receipt already exists, verify that it identifies the same named projector and event. Leave
`PaymentDetails` unchanged and fill only a missing completion marker. A receipt whose read row is
missing is corruption for verification/rebuild, not an excuse to silently recreate state during a
duplicate delivery.

A `payment_id` collision from another source event represents contradictory history and must fail
visibly. Do not hide it with an upsert.

The projection derives `initiated_at` from event `occurred_at`; it does not use processing time,
randomness, or live mutable data. Aggregate version is retained so later lifecycle events can
detect out-of-order delivery. The first-event slice does not build a generic deferral framework.

## Query contract

`GET /api/payments/{paymentId}` is merchant-scoped and runs its gate check plus read on one short
PostgreSQL transaction:

- owned `PaymentDetails` -> `200 OK` with projected business fields;
- owned idempotency receipt without projection -> `202 Accepted`, `projection_pending`, and
  `Retry-After: 1`;
- no owned projection or receipt -> `404 payment_not_found`;
- active rebuild -> `503 projection_rebuilding` with retry guidance.

Another merchant's payment returns the same not-found shape. The endpoint never rehydrates
`PaymentAggregate` and never treats Redis/SQS state as proof that a payment exists.

## Failure handling

- Projection failure rolls back the read row, receipt, and completion marker together.
- Confirmed handler failure metadata is recorded separately from the rolled-back projection
  transaction.
- A hard worker crash leaves no false completion and relies on transport redelivery or outbox
  reconciliation.
- Work deferred by the rebuild gate does not consume transport or poison budgets.
- Stale messages delivered after a successful rebuild are ignored by recreated projector receipts.

## Acceptance criteria

- First delivery creates one receipt and one read row atomically.
- Duplicate delivery changes no business fields and may repair only a missing completion marker.
- Rollback and worker-crash paths leave the message retryable.
- Pending, projected, missing, other-merchant, and rebuilding HTTP states have stable contracts.
- Gate lock and protected work use the same PostgreSQL backend connection.

## Related

- [Outbox delivery](outbox-delivery.md)
- [Verification and rebuild](verification-and-rebuild.md)
- [Resilience test plan](../../testing/payments-resilience.md)
