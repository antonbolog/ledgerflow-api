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

- PHP **8.4** (pinned `php:8.4.24-fpm-alpine`; `composer.json` requires `^8.4.1`, the minimum the
  locked dependency graph resolves against), Laravel 13
- Pinned images: `nginx:1.30.4-alpine` (stable branch), `postgres:18.4-alpine`,
  `redis:8.10.0-alpine`, `composer:2.8.12`. phpredis pinned to 6.3.0
- `laravel/sanctum` (v4.3.3) — installed in T2 for merchant-user identity. Its migration does **not**
  load from the package, so T2 published
  `database/migrations/2026_08_10_175810_create_personal_access_tokens_table.php`. `install:api` was
  not run: no `routes/api.php`, no `api:` routing entry, no example route until a task serves a real
  endpoint. Token abilities, expiry, and the SPA cookie-versus-token decision are deferred with it
- Reverb, Horizon, and Filament arrive with their own milestones
- Amazon SQS is planned for separately owned durable workloads; Horizon manages Redis queues only
- `nwidart/laravel-modules` (v13.0.0) — module registration, with
  `wikimedia/composer-merge-plugin` merging module manifests
- `spatie/laravel-event-sourcing` (**v7.15.1**, installed by T3) — **Payments module only**. T3 used
  it from a test-only spike and published none of its configuration or migrations: no application
  migration exists under `database/migrations/`, and `stored_events` is **absent from the development
  database `ledgerflow`**. The test-only harness does create and retain it in `ledgerflow_test`, from
  migrations copied verbatim from the vendor stubs. Its resolved API surface, the three Spatie base
  types that bear on the future Payments `Domain` allowlist, and the findings T5/T6 inherit are
  recorded in [development environment](docs/specifications/foundation/development-environment.md)
- **T4 added no dependency.** It proved the advisory-lock and Redis-dispatch invariants against the
  already-resolved Laravel queue and phpredis, registering its Redis and queue connections at runtime
  in a test-only harness. The resolved queue/Redis/connection-loss behavior and the constraints T7
  inherits are recorded in
  [development environment](docs/specifications/foundation/development-environment.md)
- `spatie/laravel-data` — DTOs. **Deferred**, not installed: a DTO package with no DTO is a
  dependency with no consumer. It arrives with the first Payments DTO
- Pest + `pest-plugin-arch` (boundary enforcement), Larastan, Pint, Rector

Custom DDD layout inside each module: `Modules/<Context>/app/{Domain,Application,Infrastructure,Http}`,
plus `app/Providers` for framework wiring. Recorded in
[ADR 0001](docs/adr/0001-custom-ddd-module-layout.md) and enforced by
`tests/Architecture/ModuleBoundaryTest.php`. Modules carry only what discovery, providers,
migrations, tests, and Composer autoloading require.

## Bounded Contexts

- **Payments** — event-sourced. Lifecycle: `Initiated → Authorized → Captured → Settled/Failed →
  Refunded`. Idempotency and compensating actions are first-class concerns.
- **Merchant** — plain CRUD on Eloquent. Its models (`Merchant`, `User`) live in
  `app/Infrastructure/Persistence/Eloquent`, and its `Domain` layer is deliberately empty: a domain
  entity, identifier value object, or repository interface arrives with the first Merchant use case
  that needs one, not before. The repository pattern is the expected direction for that use case, not
  a requirement to build an unused abstraction now. The one published contract is
  `Application\Identity\MerchantContext`, which answers "which merchant owns this request" from the
  authenticated Sanctum user.

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
  editor opens the same host checkout and uses the same repository commands. `.editorconfig` is the
  one shared editor file, adopted as an explicit project decision.
- The application owns a custom production-oriented `Dockerfile`; Laravel Sail is not used.
- **Image topology.** One multi-stage `Dockerfile`: `base` → `vendor` → `dev` / `production` →
  `web`. The host UID/GID remap lives **only** in `dev`; `production` keeps a fixed `1000:1000` so
  the same commit builds the same image from any machine. A foreign UID fails the build rather than
  silently leaving `app` at 1000. `web` is an nginx stage carrying `public/` for deployment; local
  development uses the stock nginx image with `./public` mounted read-only.
- **Runtime topology.** `web` (nginx) → FastCGI → `app` (php-fpm) → `postgres` + `redis`. The
  published port binds to `127.0.0.1` only. nginx forwards **only** the front controller; any other
  `.php` path is refused. The entrypoint ends in `exec "$@"` with `CMD ["php-fpm"]`, so Horizon,
  the scheduler/relay, Reverb, and future SQS workers reuse this image with a `command:` override
  and no image change.
- The CLI keeps `memory_limit=-1` for static analysis; the php-fpm pool is capped at 256M so a web
  worker can never inherit an unbounded limit.
- Docker and application configuration belongs here. AWS resources and Terraform belong in
  `ledgerflow-infra`.
- IDE metadata, local environment files, credentials, dependencies, and generated runtime data
  stay untracked. Shared Docker configuration, `.env.example`, and project instructions are
  tracked.

## Development Workflow

T1, T2, T3 and T4 are complete, closing Milestones 1 and 2. T1 established the Laravel 13 skeleton, the Docker runtime, the Makefile
command contract, and the validation surface. T2 established the `Merchant` and `Payments` modules
with enforced layer boundaries, moved merchant-user identity into `Modules/Merchant` behind a
Sanctum-authenticated application contract, added the required `users.merchant_id` foreign key, and
seeded two deterministic merchants and users.

T3 proved the Spatie atomic-persistence and transaction-retry invariants Q1–Q5 against the resolved
stack, entirely from a test-only spike: one transaction and backend connection for receipt, stored
event and outbox; complete rollback at two failure boundaries; a synchronous handler that finishes
before persistence returns and enqueues nothing; a real `55P03` retried with fresh objects from stable
context; exhaustion at three attempts; and a real `23505` attempted once. `40P01` and `40001` are
allowlisted but were not produced, and multi-process concurrency was out of scope. Evidence lives in
[initiation/idempotency](docs/specifications/payments/initiation-and-idempotency.md#blocking-compatibility-evidence).

T4 proved the advisory-lock and Redis-dispatch invariants Q6–Q10, also entirely from a test-only spike
and **without adding a dependency or a line of production behavior**: a shared advisory lock and its
protected query on one backend, witnessed from a third connection through `pg_locks`; shared and
exclusive contention observed with no sleeps, including a real `55P03` from a bounded `lock_timeout`;
a silent reconnect onto a new backend outside a transaction versus a surfaced loss and a **stale**
`transactionLevel()` inside one; a Redis publication readable while the PostgreSQL transaction was
still open; a non-vacuous `after_commit` control proving the immediate path is a real choice; and a
crash after publication that returned the row to eligible so an identical second attempt produced the
documented safe duplicate. No worker, Horizon, scheduler, relay command, `SKIP LOCKED`, or
multi-process behavior was exercised. Evidence lives in
[outbox delivery](docs/specifications/payments/outbox-delivery.md#blocking-compatibility-evidence).

T4 also found and repaired a test-isolation defect: `phpunit.xml`'s queue, cache and session
declarations were losing to `$_SERVER`, so the suite's default queue was a real Redis queue on
logical database 0. See **PostgreSQL and transport test isolation** below.

**T5 is next and has not been started.** Still true after T4: no production Payments behavior exists,
both `Domain` layers are deliberately empty, no Payments `Domain` allowlist or ADR has been granted,
and `tests/Architecture/` is unchanged.

General delivery flow: Think → Plan → Build → Review → Test → Ship → Reflect. Once implementation
starts, discovery of current code, conventions, tests, working-tree state, and nested instructions
must precede planning.

Tool attribution: Claude Code is the primary executor for Build/Review/Ship; GPT/Codex pairs on
Think/Plan as a second opinion; Cursor is introduced later as a task executor. Repository truth
and evidence override tool-specific assumptions.

## Commands

Prerequisites: Docker with Compose v2, GNU Make, and Git. **No host PHP or Composer is required or
used** — every command runs in a container. The Makefile targets GNU Make 3.81, the version macOS
ships, so no `.ONESHELL`, `$(file …)`, or `!=` assignment.

`make` with no argument prints this surface.

| Target | Effect | Prerequisites | Evidence |
|---|---|---|---|
| `help` | Print this surface. The default goal, so bare `make` shows it | — | — |
| `install` | Ensure `.env`, build images, install dependencies, ensure `APP_KEY`, start services, ensure `ledgerflow_test`, migrate | Docker running | Prints `docker compose ps`; four services healthy |
| `start` | `up -d --wait` then `ps` | built images | four services healthy |
| `stop` | Halt containers. **Never removes volumes**; data survives | — | volumes remain |
| `destroy` | Remove containers, networks and project volumes | **`CONFIRM=destroy`** | refuses without it; touches no file in the working tree |
| `db-ensure-test` | Idempotently create `ledgerflow_test` | postgres running | `present` or `created` |
| `db-migrate` | `migrate --force` on `ledgerflow` | stack running | guard prints `target ok: postgres/ledgerflow` |
| `db-seed` | `db:seed --force`. Seeds two deterministic merchants and their users | stack running | exits 0; repeatable, creating no duplicates on a second run |
| `db-reset` | `migrate:fresh` on **both** databases, then re-seed | **`CONFIRM=db-reset`** | prints its target before the guard |
| `quality` | `composer validate --strict`, Pint `--test`, Rector `--dry-run`, PHPStan level 10 | Docker running | read-only; modifies no file |
| `test` | Pest against `ledgerflow_test` | Docker running | **starts postgres and redis and leaves them running** |
| `check` | `quality` then `test`. **The single CI-equivalent entry point** | Docker running | works from a stopped stack |

**Confirmation values are typed and target-specific** — `CONFIRM=destroy`, `CONFIRM=db-reset`. A
stray `CONFIRM=1` cannot satisfy either. Destructive targets remove only generated containers,
volumes, and the two allowlisted databases; they can never delete source files.

**PostgreSQL and transport test isolation.** One pinned PostgreSQL service hosts `ledgerflow`
(development) and `ledgerflow_test` (suite). The suite truncates and re-migrates its database on
every run and is prevented from reaching any other by three layers: a pre-autoload guard in
`tests/bootstrap.php` that refuses a wrong connection *before* `RefreshDatabase` can migrate, the
declared values in `phpunit.xml`, and a live `select current_database()` assertion. `check` is
non-destructive to source and to the development database; the test database is reset by design.

**`phpunit.xml` declares the intent; `tests/bootstrap.php` is what enforces it.** PHPUnit's
`<env force="true">` writes `putenv()` and `$_ENV` but **never `$_SERVER`**, and Compose's `env_file`
puts the development values there, which Laravel's env resolver reads first. The bootstrap validates
each declared value and then forces it into all three sources. This covers the database keys and —
since T4 measured the same trap defeating them — `QUEUE_CONNECTION=sync`, `CACHE_STORE=array` and
`SESSION_DRIVER=array`. Until that repair the suite's **default queue was a real Redis queue on
logical database 0**, the development keyspace. Do not treat a `phpunit.xml` entry as effective on its
own: `tests/Feature/InfrastructureContractTest.php` asserts the live resolved `SyncQueue`,
`ArrayStore` and session driver, and the bootstrap **refuses to run** if a declaration goes missing or
changes. A test may still reach Redis explicitly by name; what is forbidden is reaching shared
infrastructure through a framework default nobody chose.

**Services left running.** `make test` starts `postgres` and `redis` if they are down and leaves
them up — it cannot know whether they belong to a development session, and stopping someone else's
would be worse. Use `make stop`.

**The Makefile is the sole interface** for environment lifecycle, database lifecycle, and
validation.

**CI** (`.github/workflows/ci.yml`) performs **all application validation through Make** — `make
install` from a clean checkout, then the single `make check` contract. It contains no Composer,
Pest, Pint, Rector, PHPStan or Artisan invocation of its own. Beyond that it runs exactly three
documented classes of assertion, none of which validate application code:

- **guard assertions** — that `destroy`, `db-reset`, `db-migrate` and `db-seed` refuse, with their
  documented messages, and that `make check` leaves the development database untouched;
- **image-contract builds** — `docker build --target production` and `--target web`, because
  Compose only ever builds the `dev` target and a broken stage would otherwise merge green;
- **failure diagnostics** — `docker compose ps` and `logs` on failure only.

These are contract assertions *about* the Makefile and the image, not validation that bypasses it.
There is deliberately no Docker layer caching: `docker compose build` cannot use the Actions cache
backend without a buildx setup local development would not share.

**Ad-hoc `artisan`, `tinker` and `psql` access is ordinary developer work, not a violation.** T4
must open concurrent `psql` sessions to prove advisory-lock connection affinity. Editing `.env`
requires `make start` to recreate the app container before the change takes effect; the database
guard reads the running container, so a stale container is refused rather than silently used.

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

[ADR 0001](docs/adr/0001-custom-ddd-module-layout.md) records the custom DDD module layout. Place
further ADRs under `docs/adr/` using `docs/adr/README.md`. Remaining candidates include the Spatie
Domain exception, PostgreSQL idempotency, Payments outbox, projection gate, three-repository
boundary, Filament admin boundary, Redis/Horizon and SQS workload ownership, application-image
versus infrastructure ownership, and the OpenSearch adoption gate.

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
