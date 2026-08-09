# Development Environment Specification

## Goal

A clean checkout must behave the same in PhpStorm, VS Code, Cursor, or a terminal. Docker and a
small Makefile interface define the development contract; an editor does not.

## Current state

The repository is pre-scaffold. The items below are Milestone 1 requirements, not commands that
already work. Exact names and evidence become canonical only after T1 implements and validates
them.

## Container contract

- Own a custom production-oriented application `Dockerfile`; do not use Laravel Sail.
- Use `compose.yaml` for local application, PostgreSQL, and Redis services.
- Reuse the application image for future worker and scheduler services.
- Add health checks and persistent local volumes.
- Provide a separate PostgreSQL testing database.
- Track shared Docker configuration and `.env.example`; never track local secrets or runtime data.
- Keep AWS services, Terraform, and deployment credentials out of this repository.

The production image may later be specialized into runtime targets, but local development and AWS
workers must execute the same application artifact and dependency set.

## Intended Makefile interface

The first milestone must establish a small public surface in these directions:

| Direction | Intended targets | Contract |
|---|---|---|
| Setup | `install` | Build/configure the project from a clean checkout |
| Lifecycle | `start`, `stop` | Start and stop local services without deleting data |
| Destructive lifecycle | `destroy` | Remove generated containers/volumes only after explicit confirmation |
| Validation | `test`, `quality`, `check` | Run supported tests, quality tools, and the CI-equivalent full check |
| Database | `db-migrate`, `db-seed` | Apply schema and deterministic development fixtures |
| Destructive database | `db-reset` | Reset local/test data only after explicit confirmation |

T1 may adjust exact target names when implementation reveals a better contract. Afterward,
`AGENTS.md` must document prerequisites, effects, and expected evidence. CI must call the same
public targets instead of duplicating hidden command sequences.

## Tooling requirements

- PHP 8.3/8.4 and Laravel 13.
- PostgreSQL and Redis versions pinned by project configuration.
- Pest and `pest-plugin-arch`.
- Larastan, Pint, and Rector.
- `nwidart/laravel-modules`, Sanctum, Spatie Laravel Event Sourcing, and Spatie Laravel Data.

No dependency is added only because it is fashionable. `ahmedsaed/laravel-ddd` is rejected; its
folder convention may be reproduced manually after the decision is recorded as an ADR.

## Editor and ignore policy

`.gitignore` excludes IDE metadata such as `.idea/`, `.vscode/`, `.cursor/`, `.fleet/`, and local
history, plus local environment files, credentials, dependencies, OS metadata, logs, coverage, and
generated runtime data. Shared editor configuration may be added later only as an explicit team
decision.

## Acceptance evidence

From a clean checkout with Git, Docker, and Docker Compose:

1. The documented installation path succeeds.
2. Application, PostgreSQL, and Redis health checks become healthy.
3. Two deterministic merchants and users can be seeded.
4. Unit, integration, architecture, static-analysis, formatting, and full-check contracts run only
   through documented project targets.
5. Destructive targets refuse to run without explicit confirmation and cannot remove source files.

Until T1 establishes those commands, validation must not be invented ad hoc.

## Related

- [Roadmap](../../roadmap.md)
- [Runtime contract](../../operations/runtime-contract.md)
- [Resilience test plan](../../testing/payments-resilience.md)
