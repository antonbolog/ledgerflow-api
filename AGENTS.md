# AGENTS.md — ledgerflow-api

Canonical repository instructions. Repository truth and the current user request override external
tool defaults. Keep this file short enough to remain useful as always-on context; durable detail
belongs in the linked documentation.

## Project

Ledgerflow is a production-like fintech learning and portfolio system built around DDD, CQRS,
event sourcing, idempotency, asynchronous delivery, and explicit failure recovery.

This repository owns the Laravel API, domain modules, internal Filament panel, application workers,
Docker image, and application runtime contracts. The merchant dashboard belongs to
`ledgerflow-spa`; Terraform, AWS resources, environments, and deployment topology belong to
`ledgerflow-infra`.

Core stack: PHP 8.4, Laravel 13, PostgreSQL 18, Redis 8, Sanctum, Spatie Laravel Event Sourcing,
`nwidart/laravel-modules`, Pest, PHPStan level 10, Pint, and Rector. Exact resolved versions and
compatibility evidence live in
[development environment](docs/specifications/foundation/development-environment.md).

## Current capabilities

- `Merchant` owns merchant-user identity and resolves the authenticated merchant through Sanctum.
- `Payments` is event-sourced. `POST /api/payments` atomically commits the idempotency receipt,
  stored event, and outbox envelope; retries replay exact stored response bytes.
- The scheduler-driven outbox relay publishes eligible event IDs to the logical
  `payments-projection` Redis queue inside short PostgreSQL transactions. Its local scheduler is
  opt-in and exposes a read-only JSON status command.
- No projection consumer, Horizon supervision, read model, or `GET /api/payments/{id}` exists yet.
  The relay remains dormant in normal deployments until the consumer lands. T8 is next; the
  [roadmap](docs/roadmap.md) owns delivery order and task status.

## Architecture and boundaries

Modules use `Modules/<Context>/app/{Domain,Application,Infrastructure,Http}` plus `app/Providers`.
[ADR 0001](docs/adr/0001-custom-ddd-module-layout.md) defines the layout and
`tests/Architecture/ModuleBoundaryTest.php` enforces it.

- `Domain` is framework-independent except for the exact Payments event-sourcing allowlist in
  [ADR 0002](docs/adr/0002-payments-domain-event-sourcing-allowlist.md).
- `Application` owns use cases and ports; `Infrastructure` implements framework and persistence
  adapters; `Http` owns transport concerns.
- Modules must not carry `config/` or `routes/`; shared framework registration stays at the root.
- Do not invent lifecycle transitions, abstractions, DTO packages, transports, or infrastructure
  before a concrete use case needs them.
- Redis queues and SQS never dual-own one logical job. Redis Pub/Sub is fan-out, not durable queue
  storage. See [messaging ownership](docs/architecture/messaging-and-queues.md).

### Protected Payments concerns

Payments event schema, idempotency, transaction retry, outbox delivery, compensation, projection,
and replay/rebuild semantics require explicit review against the smallest relevant specification.

- Preserve one-connection transaction ownership for receipt, stored event, and outbox writes.
- Transport failure must not consume projection failure budget or exhaust an outbox row.
- The relay intentionally has no shared projection gate until T11; do not add a provisional key or
  control table. See [ADR 0004](docs/adr/0004-lock-through-push-outbox-relay.md).
- Logs and errors must not expose raw idempotency keys, credentials, or sensitive payment payloads.

Additional scoped rules apply under `Modules/Payments/`, `.github/`, `tests/`, and `docs/` through
their nested `AGENTS.md` files. Each has a sibling `CLAUDE.md` adapter; `make agent-check` enforces
parity.

## Development contract

Local development is Docker-first and editor-neutral. Prerequisites are Docker with Compose v2,
GNU Make, and Git; no host PHP or Composer is used. The Makefile is the public interface for
environment lifecycle, database lifecycle, validation, and CI contracts.

| Target | Contract |
|---|---|
| `make install` | Build and start a clean checkout, install dependencies, ensure test DB, migrate |
| `make start` / `make stop` | Start or stop services without deleting data |
| `make destroy CONFIRM=destroy` | Remove only project containers, networks, and volumes |
| `make db-migrate` / `make db-seed` | Mutate only the allowlisted local development database |
| `make db-reset CONFIRM=db-reset` | Rebuild only the allowlisted development and test databases |
| `make projection-start` / `make projection-stop` | Start or stop the scheduler and Horizon consumer together, never one alone |
| `make agent-check` | Verify instruction adapters, scoping, and local-config exclusions |
| `make quality` | Read-only Composer, Pint, Rector, and PHPStan checks |
| `make test` | Run Pest against `ledgerflow_test`; leaves PostgreSQL and Redis running |
| `make check` | Run the normal local validation contract: `quality` then `test` |
| `make projection-contract` | Prove the scheduler+Horizon pair in a disposable Compose project |
| `make ci` | After `make install`, run the full CI guard, isolation, validation, and image contract |

Use only these repository-defined validation commands. `make check` must not mutate source or the
development database. CI runs `make install` followed by `make ci`; it does not reproduce hidden
Composer, Artisan, test, or image-build sequences.

## Working method and tools

General flow: Think → Plan → Build → Review → Test → Ship → Reflect. Before planning a non-trivial
change, inspect the working tree, relevant instructions, exact source, conventions, and tests.

- Start structural code exploration with Codebase Memory for symbols, callers, dependencies, and
  impact analysis. Inspect exact source before edits or implementation claims; do not reindex when
  the index is already ready.
- Use Graphify for broad architecture, documentation relationships, communities, and visual graph
  exploration. When `graphify-out/graph.json` exists, query it rather than reading generated graph
  artifacts directly.
- Run `graphify update .` after a completed feature or material architecture, dependency, API-flow,
  ADR, or documentation change. Do not refresh it after routine edits. Commit source changes first,
  then refresh and commit the four curated graph artifacts separately so provenance points at the
  source commit.
- Use RTK for shell output and `rtk proxy` when exact uncompressed output is required. Use Context
  Mode for large logs, tests, JSON, web/API responses, and batch output.
- Claude Code is the primary Build/Review/Ship executor; GPT/Codex is a Think/Plan pairing partner.
  Repository evidence overrides tool-specific assumptions.

## Documentation ownership

- `ARCHITECTURE.md` — thin architecture navigator.
- `docs/roadmap.md` — milestones, task state, dependencies, and tracker links.
- `docs/architecture/` — boundaries and end-to-end flows.
- `docs/specifications/` — canonical behavior and invariants.
- `docs/testing/` — executable acceptance and failure evidence.
- `docs/operations/` — process topology, observability, and runtime readiness.
- `docs/adr/` — durable architecture decisions.

Prefer pointers over copied narrative. Implementation evidence belongs in canonical specs or ADRs,
not in this file or the root README.

## Maintenance

Keep instructions synchronized with actual repository state. Update the smallest canonical source
when behavior changes, run `make agent-check`, and surface conflicts instead of choosing silently.
Add another nested `AGENTS.md` only when a subtree has material local rules or recurring mistakes.
Do not claim a higher agent-setup level without executable evidence for its required capabilities.
