# Payments module instructions

Applies to `Modules/Payments/**` in addition to the repository root instructions.

## Scope

`Payments` is the protected event-sourced bounded context. Before changing behavior, read the
smallest relevant document under `docs/specifications/payments/` and any linked ADR.

## Boundaries

- Keep `Domain` framework-independent except for the exact Spatie event-sourcing types allowed by
  ADR 0002 and the architecture test.
- Keep `Application` use cases behind owned ports. Framework, persistence, queue, and HTTP types
  belong in `Infrastructure` or `Http`.
- Do not add module-local `config/` or `routes/`; the enforced layout forbids them.
- Do not add future payment transitions, payload fields, repair behavior, or abstractions before a
  use case and specification require them.

## Invariants

- Treat event aliases, versions, durable payloads, idempotency fingerprints, stored response bytes,
  retry SQLSTATEs, and outbox ownership as compatibility contracts.
- Preserve one-connection transaction ownership for idempotency receipt, stored event, and outbox.
- Relay transport failures and confirmed projection failures have separate counters and owners.
- The relay, consumer, transactional query adapter, and write path each check T11's shared projection
  gate as the first statement inside the transaction they already open, per
  [ADR 0005](../../docs/adr/0005-payments-projection-gate-and-rebuild-ownership.md). A closed gate never
  raises an exception from the relay or the consumer and locks no row; POST and GET raise a typed
  ProjectionRebuilding application exception, rendered as 503 with Retry-After: 1. No traffic-path
  deferral may consume projection failure budget, transport failure budget, or outbox eligibility.
- The rebuild owns one pinned connection and exclusive session lock across bounded replay batches.
  Preserve inherited `cleared_at` across takeover and failure; guarded recovery requires a previously `rebuilding` row with a null marker.
  Ownership is rechecked before clear, before each batch, and before ready, including guarded recovery.
- Verification and targeted repair hold the same shared gate through their owned transactions.
  A closed gate yields `rebuild_in_progress`: incomplete exit `2` for verification, refusal exit `1` without mutation for repair.
  The gate never replaces repair's `--delivery-quiesced` acknowledgement.
- Never log raw idempotency keys, credentials, exception text in health JSON, or sensitive payloads.

## Evidence

Use real PostgreSQL or Redis only when the invariant depends on their behavior. Make controls
non-vacuous, avoid timing sleeps when synchronization is possible, and keep test infrastructure on
the explicitly named test connections. Finish with the repository-defined `make check` contract.
