# Architecture Decision Records

Use lightweight ADRs for durable decisions that implementation should not silently reverse.

## Format

Each ADR contains:

1. **Title and ID**.
2. **Status** — proposed, accepted, superseded, deprecated, or rejected.
3. **Date and decision owner**.
4. **Context** — the concrete problem and constraints.
5. **Decision** — the chosen direction.
6. **Alternatives considered** — meaningful rejected options.
7. **Consequences** — benefits, costs, risks, and follow-up work.
8. **Related ADRs/specifications** and **supersedes/superseded by** where relevant.

Use sequential names such as `0001-custom-ddd-module-layout.md`; do not put dates in filenames.

## Recorded

- [0001](0001-custom-ddd-module-layout.md) — Own the DDD module layout in this repository rather than
  adopting an opinionated third-party scaffolding package. Accepted, implemented by T2.
- [0002](0002-payments-domain-event-sourcing-allowlist.md) — Permit only `AggregateRoot` and
  `ShouldBeStored` in Payments `Domain`, matched exactly and scoped to that module. Accepted,
  implemented by T5.
- [0003](0003-postgresql-idempotency-arbitration.md) — Arbitrate payment idempotency in PostgreSQL
  with a complete receipt, a targeted `ON CONFLICT` target, and a repository-owned SQLSTATE retry
  allowlist. Accepted, implemented by T6.
- [0004](0004-lock-through-push-outbox-relay.md) — Publish the payment outbox with a lock-through-push
  scheduler-driven relay — one row, one short transaction, publish before commit, no shared
  projection gate. Accepted, implemented by T7. Records HS-1: the projection gate is deliberately
  omitted and becomes T11's reciprocal obligation.
- [0005](0005-payments-projection-gate-and-rebuild-ownership.md) — Gate the Payments projection with a
  durable control row plus a connection-affine advisory lock, defer rather than fail gated work, and
  prove rebuild ownership by lock possession rather than by a token, a pid, or a successful query.
  Accepted, implemented by T11.
- [0006](0006-merchant-oauth-bff-authentication.md) - Authenticate merchants with Passport OAuth 2.0
  behind a Next.js BFF: Authorization Code Grant with PKCE, a confidential first-party client,
  BFF-owned `Secure` `HttpOnly` token cookies, session-free merchant resource routes, and merchant
  identity kept in a separate provider from the planned Filament operator identity. Accepted as
  specification by ANT-28; implementation belongs to ANT-29 and ANT-31.

## Candidates

- Split the system into `ledgerflow-api`, `ledgerflow-spa`, and `ledgerflow-infra` with explicit
  ownership.
- Serve the business admin panel through Filament at `/admin` without exposing developer/DevOps
  controls or raw event mutation.
- Use Redis/Horizon and SQS/DLQ for distinct logical workloads instead of dual-publishing one job.
- Let application repositories build immutable artifacts while `ledgerflow-infra` owns AWS
  resources and deployment topology.
- Require a cost and use-case gate before adopting Amazon OpenSearch Service.

Create each ADR when its corresponding implementation milestone begins, so package behavior and
measured evidence can inform the final decision record. Move an entry from **Candidates** to
**Recorded** as its ADR lands.
