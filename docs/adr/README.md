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

## Candidates

- Use PostgreSQL durable idempotency rather than rate limiting or Redis as authority.
- Use a Payments-specific transactional outbox for the learning slice.
- Use a connection-affine PostgreSQL advisory-lock gate for projection rebuild.
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
