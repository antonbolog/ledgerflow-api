# Payments Specifications

These files define the first Payments vertical slice. Implementation tasks and tests should link to
the narrowest relevant document.

- [Initiation and idempotency](initiation-and-idempotency.md) — authentication, request contract,
  atomic write, transaction retry, event, and schema invariants.
- [Outbox delivery](outbox-delivery.md) — relay state machine, failure classification, queue
  transport, scheduler, and evolution gates.
- [Projection and query](projection-and-query.md) — idempotent projection and eventual-consistency
  query behavior.
- [Verification and rebuild](verification-and-rebuild.md) — corruption detection, targeted outbox
  repair, projection gating, replay, and recovery.

Shared architecture is explained in [Payments flow](../../architecture/payments-flow.md). Required
evidence is listed in the [resilience test plan](../../testing/payments-resilience.md).
