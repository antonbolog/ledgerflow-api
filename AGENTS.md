# AGENTS.md — ledgerflow-api

Canonical root instruction entry point for this repository. Repository-local knowledge here
takes precedence over generic guidance from any external source.

## Project

DDD/CQRS/Event-Sourcing showcase in the fintech domain. This repository owns the Laravel API,
application processes, and internal Filament panel. The customer-facing merchant dashboard and
AWS infrastructure live in separate repositories.

The module/layer separation approach is carried over conceptually from an existing Symfony/API
Platform project (`ddd_template`) — concept only, with no shared code or dependency.

Repo: `github.com/mythern/ledgerflow-api` (private now, public later — see **Repository Strategy**).

## Stack

- PHP 8.3/8.4, Laravel 13
- PostgreSQL, Redis, Sanctum, Reverb, Horizon, and Filament
- Amazon SQS is planned for separately owned durable workloads; Horizon manages Redis queues only
- `nwidart/laravel-modules` (v13) — module registration
- `spatie/laravel-event-sourcing` (v7.15) — **Payments module only**
- `spatie/laravel-data` — DTOs
- Pest + `pest-plugin-arch` (boundary enforcement), Larastan, Pint, Rector

Custom DDD layout inside each module: `Domain / Application / Infrastructure / Http`.

`ahmedsaed/laravel-ddd` was evaluated and rejected as a dependency because it was immature at the
time of evaluation. Its useful folder convention may be reused manually. Record the decision as
an ADR when the foundation milestone implements the module layout.

## Bounded Contexts

- **Payments** — event-sourced. Lifecycle: `Initiated → Authorized → Captured → Settled/Failed →
  Refunded`. Idempotency and compensating actions are first-class concerns.
- **Merchant** — plain CRUD, Eloquent, standard repository pattern.

## Repository Strategy

Ledgerflow is split into three repositories with explicit ownership:

- `ledgerflow-api` — this repository: API, domain modules, Filament `/admin`, workers, Docker image,
  and application runtime contracts. Private now, public later.
- `ledgerflow-spa` — Next.js/React merchant dashboard and its frontend image. Private now, public
  later.
- `ledgerflow-infra` — Terraform, AWS resources, environments, and deployment topology. Stays
  private permanently.

The current AWS target is ECS Fargate, RDS PostgreSQL, ElastiCache, SQS/DLQ, CloudWatch, ECR, and
GitHub Actions OIDC role assumption without static AWS keys. Amazon OpenSearch Service is optional
and requires a measured search/reporting use case plus an accepted cost gate.

### Messaging policy

- M1–M5 use the logical `payments-projection` Redis queue supervised by Horizon.
- Redis queues remain valid in production for internal, recoverable application work whose
  durable authority lives in PostgreSQL.
- SQS/DLQ is introduced for a distinct workload that needs AWS-native durability, redrive, and
  independently scalable workers. The exact first workload is selected before its milestone.
- Never dual-publish one logical job to Redis and SQS merely to exercise both technologies.
- Redis Pub/Sub used by Reverb for WebSocket fan-out is not a queue and is not durable job storage.

See `docs/architecture/messaging-and-queues.md` for ownership rules.

## Development Environment

- Local development is Docker-first and editor-neutral. PhpStorm, VS Code, Cursor, or another
  editor opens the same host checkout and uses the same repository commands.
- The application owns a custom production-oriented `Dockerfile`; Laravel Sail is not used.
- Local `compose.yaml` runs the application, PostgreSQL, and Redis. Later web, Horizon, scheduler,
  relay, Reverb, and SQS worker processes reuse the same application image where applicable.
- Docker and application configuration belongs here. AWS resources and Terraform belong in
  `ledgerflow-infra`.
- IDE metadata, local environment files, credentials, dependencies, and generated runtime data
  stay untracked. Shared Docker configuration, `.env.example`, and project instructions are
  tracked.

## Development Workflow

This project is at **greenfield, pre-scaffold stage**. There is architecture documentation but no
Laravel codebase or executable project command contract yet.

General delivery flow: Think → Plan → Build → Review → Test → Ship → Reflect. Once implementation
starts, discovery of current code, conventions, tests, working-tree state, and nested instructions
must precede planning.

Tool attribution: Claude Code is the primary executor for Build/Review/Ship; GPT/Codex pairs on
Think/Plan as a second opinion; Cursor is introduced later as a task executor. Repository truth
and evidence override tool-specific assumptions.

## Commands

Not yet established. Once the Laravel skeleton lands, `Makefile` becomes the public command
interface. T1 must implement and verify a small command surface for:

- project lifecycle: install, start, stop, and destroy the local Docker environment;
- validation: tests, code quality, and one full-check entry point used by CI;
- database lifecycle: migrate, seed deterministic data, and explicitly reset local/test data.

Destructive commands may remove only generated containers, volumes, or local/test data; they must
never delete source files and must require an explicit confirmation variable. Exact target names,
prerequisites, side effects, and evidence are documented only after T1 implements and runs them.
Do not bypass the Makefile with invented validation commands in the meantime.

## Boundaries

- Scope: `ledgerflow-api` application source, local Docker configuration, and API runtime
  contracts.
- Merchant dashboard implementation belongs in `ledgerflow-spa`.
- Terraform, AWS resources, environments, and deployment topology belong in `ledgerflow-infra`.
- No production AWS resources exist yet; none should be provisioned from this repository.
- Filament provides business administration workflows only. AWS, Horizon, DLQ, raw event-store,
  projection repair, and infrastructure controls remain developer/DevOps surfaces unless a
  separately authorized operator workflow is specified.
- Payments event schema, idempotency, outbox, compensation, and replay semantics are protected
  concerns and require explicit review.

## Architecture Decisions

No ADRs are recorded yet. Place them under `docs/adr/` using `docs/adr/README.md`. Initial
candidates include the manual DDD module layout, Spatie Domain exception, PostgreSQL idempotency,
Payments outbox, projection gate, three-repository boundary, Filament admin boundary,
Redis/Horizon and SQS workload ownership, application-image versus infrastructure ownership, and
the OpenSearch adoption gate.

## Documentation Map

- `ARCHITECTURE.md` — thin architecture orchestrator and pointers.
- `docs/README.md` — documentation index and ownership rules.
- `docs/roadmap.md` — milestones, tasks, dependencies, and tracker links.
- `docs/architecture/` — system boundaries and end-to-end flows.
- `docs/specifications/` — canonical implementation behavior and invariants.
- `docs/testing/` — executable acceptance and failure coverage.
- `docs/operations/` — process topology, observability, and deployment-facing runtime contracts.
- `docs/adr/` — durable architecture decisions.

Implementation tasks link to the smallest relevant specification. Do not recreate a single
catch-all implementation-plan document. Prefer pointers over copied documentation.

## Notes

Add nested `AGENTS.md` files only when a module or directory has material local rules, domain
language, or recurring agent mistakes. Keep this file synchronized with actual repository state;
if documentation and implementation disagree, surface the conflict and correct the stale source.
