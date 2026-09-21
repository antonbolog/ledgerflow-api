# Payments Flow

This document is the architectural map of the first production-like Payments vertical slice. The
linked specifications are authoritative for detailed behavior.

## API surface

### Initiate a payment

`POST /api/payments` requires a Passport bearer token and an `Idempotency-Key`. The merchant comes
from the authenticated user; the client cannot choose it. The slice accepts only `USD` and an
`amount_minor` from `1` through `100_000_000` inclusive.

The first accepted request returns `202 Accepted`, a UUIDv7 payment ID, `status: initiated`, and a
`Location` header. An identical retry returns the stored status, exact response bytes, and
`Location`. Reusing the same merchant-scoped key for another canonical payload returns `409`.

### Query a payment

`GET /api/payments/{paymentId}` requires the same bearer token and resolves the merchant the same
way, through `MerchantContext`. It returns:

- `200` when the owned `PaymentDetails` projection exists;
- `202 projection_pending` with `Retry-After: 1` when the owned receipt exists but projection lags;
- `404 payment_not_found` when no owned receipt or projection exists;
- `503 projection_rebuilding` with `Retry-After: 1` while the Payments projection gate is closed.

A malformed payment ID, an unknown one, and another merchant's all produce that same `404` document.
For one requested ID, the unknown and foreign cases are byte-identical, so the endpoint never reveals
that a payment exists but belongs to someone else. A malformed ID shares the problem type and code
and differs only in the `instance` path it echoes, which is the requested ID itself; it is never
downgraded to the generic `not_found`.

Queries never rehydrate the aggregate: the read is `payment_details` and, only when that misses, the
`payment_idempotency_receipts` fallback, both inside one short adapter-owned transaction.

## Write path

The command handler owns one outer PostgreSQL transaction:

1. Validate and canonicalize `{amount_minor, currency}`.
2. Hash the raw idempotency key and calculate the request fingerprint.
3. Create immutable command context: stable payment/event IDs, occurrence time, input, and exact
   response bytes.
4. Atomically reserve `(merchant_id, idempotency_key_hash)` with `INSERT ... ON CONFLICT DO
   NOTHING`.
5. Return the winning stored response for an identical request, or reject a mismatched payload.
6. Record `PaymentInitiated` through `PaymentAggregate`.
7. Persist the event and synchronously create one outbox envelope.
8. Commit the receipt, stored event, and outbox row together.

Only PostgreSQL SQLSTATE `40P01`, `40001`, and `55P03` retry the whole closure, at most three
attempts. Each attempt uses fresh aggregate/event objects while preserving immutable command data.

## Event and delivery path

`PaymentInitiated` contains stable event, payment, merchant, amount, currency, occurrence time, and
schema-version data. Its outbox envelope adds aggregate version and delivery state. Raw
idempotency keys never appear in events, envelopes, or logs.

The scheduler runs a short Payments relay. The relay selects eligible rows, publishes only the
event ID, and records the transport result. The consumer reloads the durable envelope, inserts a
projector receipt, creates `PaymentDetails`, and marks the outbox projection complete in one
PostgreSQL transaction.

Duplicate messages do not rewrite the read model. If the authoritative projector receipt exists
but the denormalized outbox completion marker is missing, duplicate processing repairs only that
marker.

## Minimal data model

- `payment_idempotency_receipts` owns merchant-scoped request arbitration and exact response replay.
- Spatie `stored_events` owns immutable business history.
- `payment_outbox` owns durable delivery intent, backoff, publication, failure, completion, and
  exhaustion state.
- `payment_projector_receipts` owns idempotent projector completion by `(projector_name, event_id)`.
- `payment_details` owns queryable payment state.
- `payment_projection_controls` owns durable `ready`/`rebuilding` state, an owner token, an owner
  backend pid, and `cleared_at`, with advisory locks providing mutual exclusion. Decided in
  [ADR 0005](../adr/0005-payments-projection-gate-and-rebuild-ownership.md).

## Verification and targeted repair

The manual verifier compares canonical history and derived state in one read-only snapshot through four bounded passes.
Targeted outbox repair restores only one envelope under an explicit delivery-quiescence acknowledgement, preserving existing failure history and exhaustion.
It does not rebuild the read model, alter HTTP idempotency receipts, or enable any new runtime process.
See the [operator procedure](../operations/runtime-contract.md#manual-verification-and-targeted-repair) and the [canonical contract](../specifications/payments/verification-and-rebuild.md).

## Rebuild path

Implemented, under [ADR 0005](../adr/0005-payments-projection-gate-and-rebuild-ownership.md).
All four traffic paths - the write path, the transactional read, the relay, and the consumer - check
the gate.

A controlled rebuild closes only Payments traffic and workers, acquires an exclusive
connection-affine PostgreSQL advisory lock, clears derived projection state, and replays stored
Payments events in bounded global-ID batches from canonical history. It exposes `ready` only after
complete success. Traffic reaching a closed gate gets a retryable `503` on HTTP and a deferral on the
relay and consumer that consumes no delivery or poison budget.

A lost session releases the advisory lock but leaves durable state as `rebuilding`. The next
authorized run clears partial derived state and restarts from zero, unless the durable row proves no
clear was ever committed. A rebuild proves it is still the owner by holding the lock, not by a
successful query, a transaction counter, or a stored process id, none of which survive a silent
reconnect. Resumable staging-table rebuild is deferred until measured volume makes the simpler gated
replay operationally unacceptable.

## Detailed specifications

- [OpenAPI artifact](../api/openapi.json)
- [Initiation and idempotency](../specifications/payments/initiation-and-idempotency.md)
- [Outbox delivery](../specifications/payments/outbox-delivery.md)
- [Projection and query](../specifications/payments/projection-and-query.md)
- [Verification and rebuild](../specifications/payments/verification-and-rebuild.md)
- [Resilience test plan](../testing/payments-resilience.md)
