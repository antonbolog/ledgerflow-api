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
- Keep the T7 relay free of the projection advisory gate until T11 introduces the real control row,
  key derivation, and deferral semantics.
- Never log raw idempotency keys, credentials, exception text in health JSON, or sensitive payloads.

## Evidence

Use real PostgreSQL or Redis only when the invariant depends on their behavior. Make controls
non-vacuous, avoid timing sleeps when synchronization is possible, and keep test infrastructure on
the explicitly named test connections. Finish with the repository-defined `make check` contract.
