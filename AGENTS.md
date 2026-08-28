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
  `database/migrations/2026_08_10_175810_create_personal_access_tokens_table.php`. `install:api` has
  still never been run; **T6 wrote by hand the only two parts of it that gained a consumer** —
  `routes/api.php` and the `api:` entry in `bootstrap/app.php`. The example route and the rest of its
  scaffolding remain absent. T6 proves real bearer-token authentication on that route, which
  establishes the guard and **selects nothing for the SPA**: token abilities, expiration, the
  cookie-versus-token mode, CORS/CSRF, deployment domains, and the rest of `config/sanctum.php` were
  deliberately left undecided, and now belong to
  [Merchant dashboard integration](docs/roadmap.md#merchant-dashboard-integration) rather than to the
  task that served the first endpoint
- Reverb, Horizon, and Filament arrive with their own milestones
- Amazon SQS is planned for separately owned durable workloads; Horizon manages Redis queues only
- `nwidart/laravel-modules` (v13.0.0) — module registration, with
  `wikimedia/composer-merge-plugin` merging module manifests
- `spatie/laravel-event-sourcing` (**v7.15.1**, installed by T3) — **Payments module only**. T3 used
  it from a test-only spike; **T5 put it into production**. The package publishes its migrations and
  never loads them, so T5 owns four migrations under `Modules/Payments/database/migrations/` —
  `stored_events` and `snapshots` schema-identical to the vendor stubs, plus
  `payment_idempotency_receipts` and `payment_outbox`. All four now exist in the development database
  `ledgerflow` and in `ledgerflow_test`. `config/event-sourcing.php` is a hand-written **three-key**
  file — `catch_exceptions => false` (literal, never `env()`), `event_class_map` with the single
  production alias, and `enforce_event_class_map => false` — with every other key left on the vendor
  default that `mergeConfigFrom()` supplies. Its resolved API surface, the Spatie base types the
  Payments `Domain` allowlist permits, and the findings T6 inherits are recorded in
  [development environment](docs/specifications/foundation/development-environment.md)
- **T4 added no dependency.** It proved the advisory-lock and Redis-dispatch invariants against the
  already-resolved Laravel queue and phpredis, registering its Redis and queue connections at runtime
  in a test-only harness. The resolved queue/Redis/connection-loss behavior and the constraints T7
  inherits are recorded in
  [development environment](docs/specifications/foundation/development-environment.md)
- **T7 added no dependency either.** It turned T4's spike primitives into the first production writer
  of transport delivery state — a dedicated Redis connection `payments`, queue connection/name
  `payments-projection`, and cache store `payments-relay`, all literal and validated once by
  `RelayConfiguration` at container resolution. See **Development Workflow** below and
  [ADR 0004](docs/adr/0004-lock-through-push-outbox-relay.md)
- `spatie/laravel-data` — DTOs. **Deferred**, not installed: a DTO package with no DTO is a
  dependency with no consumer. It arrives with the first DTO that needs **casting, transformation, or
  more than one representation** — not merely with the first DTO. T6 shipped three DTO-shaped types
  and still installed nothing: `FormRequest` already produces the validation failure shape, the
  command context is immutable scalars in a layer whose boundary rule forbids external namespaced
  classes, and the success body is contractually one pinned `json_encode()`
- Pest + `pest-plugin-arch` (boundary enforcement), Larastan, Pint, Rector

Custom DDD layout inside each module: `Modules/<Context>/app/{Domain,Application,Infrastructure,Http}`,
plus `app/Providers` for framework wiring. Recorded in
[ADR 0001](docs/adr/0001-custom-ddd-module-layout.md) and enforced by
`tests/Architecture/ModuleBoundaryTest.php`. Modules carry only what discovery, providers,
migrations, tests, and Composer autoloading require.

## Bounded Contexts

- **Payments** — event-sourced. Lifecycle: `Initiated → Authorized → Captured → Settled/Failed →
  Refunded`. Idempotency and compensating actions are first-class concerns.

  **Only the first transition exists.** T5's `Domain` holds `PaymentAggregate` — one command,
  `initiate()`, taking scalars because `AggregateRoot::apply()` would re-invoke a command method
  typed against the event — plus the immutable `PaymentInitiated` and the exceptions
  `InvalidPaymentInitiation` and `PaymentAlreadyInitiated`. Authorized, Captured, Settled, Failed and
  Refunded have no code: inventing their transitions before a use case needs them is schema by
  anticipation. `PaymentInitiated` carries the stable alias `payments.payment_initiated`, schema
  version `1` from an overridden `eventVersion()` rather than a Spatie attribute, and exactly six
  durable payload fields — `eventId`, `paymentId`, `merchantId`, `amountMinor`, `currency`,
  `occurredAt` — where `occurredAt` is a canonical ISO-8601 **UTC microsecond** string
  (`Y-m-d\TH:i:s.uP`), not a `DateTimeInterface`.

  **T6 added the layers above it, and no further transition.** `Application\Initiation` holds the
  framework-free use case behind exactly two ports — `AtomicTransaction` and `IdempotencyReceipts` —
  plus the immutable `PaymentInitiationContext` and the two exceptions that become `409` and `503`.
  A clock port and an identifier port were deliberately **cut**: time and UUIDs are minted once in
  the Http layer before the transaction opens, which makes regenerating them inside a retry
  unrepresentable rather than merely discouraged. `Infrastructure` holds the two adapters and the
  synchronous `PaymentOutboxWriter`. The lifecycle beyond `Initiated` is still uncoded.
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
- IDE metadata, local environment files, credentials, dependencies, and machine-local runtime
  data stay untracked. Shared Docker configuration, `.env.example`, and project instructions are
  tracked.
- **Tracked-but-generated is a deliberate, narrow exception, not a general rule.** `/.claude/`
  is tracked on purpose — it carries the shared project permissions, the Graphify PreToolUse
  hooks, and the vendored `graphify` skill, so every contributor gets the same agent
  configuration. `/.claude/settings.local.json` is the per-machine permission cache and stays
  ignored by the repository's own `.gitignore` rather than by a user-global ignore file. Of
  `graphify-out/`, exactly four generated artifacts are tracked — `graph.json`, `graph.html`,
  `GRAPH_REPORT.md`, `.graphify_labels.json` — because they are the queryable graph itself;
  `.gitattributes` marks them generated and suppresses their textual diff and merge, so a
  conflict is resolved by regenerating rather than hand-merging. Everything else under
  `graphify-out/` is machine-local and ignored: `cache/`, `cost.json`, `manifest.json` (per-file
  incremental build state), `.graphify_python` and `.graphify_root` (absolute host paths), the
  dated pre-update backup directories, and the `*.sig` integrity signatures.
- **Generated graph artifacts are refreshed only after a material change** — a completed feature
  or a material architecture, dependency, API-flow, ADR, or documentation change — never after a
  routine file edit. A refresh is its own commit, made *after* the source commit it analyzes, so
  the manifest's build stamp names a commit that actually contains the analyzed source.

## Development Workflow

T1 through T6 are complete, closing Milestones 1, 2 and 3. T1 established the Laravel 13 skeleton, the Docker runtime, the Makefile
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

T5 is complete. It put the first production Payments types on disk — `PaymentAggregate`,
`PaymentInitiated` and two domain exceptions — behind
[ADR 0002](docs/adr/0002-payments-domain-event-sourcing-allowlist.md), which grants Payments `Domain`
exactly two external namespaced Spatie classes (`AggregateRoot`, `ShouldBeStored`), matched as exact
names and scoped to that module. `tests/Architecture/ModuleBoundaryTest.php` carries the enforcing
allowlist and its mutation tests. T5 also added the four Payments migrations, the minimal
event-sourcing configuration, and the schema and persistence contracts that pin them.
**Merchant's `Domain` is still deliberately empty** and remains subject to the unmodified rule.

T6 is complete, closing M3. It serves the repository's first API route, `POST /api/payments`, behind
`auth:sanctum` from a root `routes/api.php` — root, because `ModuleBoundaryTest` forbids a module
carrying a `routes` directory. One outer transaction on one connection, opened by an owned retry
runner, commits the idempotency receipt, the stored event and the outbox envelope, or none of them.
Arbitration is `INSERT … ON CONFLICT (merchant_id, idempotency_key_hash) DO NOTHING RETURNING id`
with the conflict target **named**: this table also carries a global unique on `payment_id`, and an
untargeted clause would swallow that collision and answer a permanent condition with a retryable
`503`. An empty return means a committed, readable winner — measured, not assumed — whose stored
bytes are replayed on a fingerprint match and refused with `409` otherwise. `40P01`, `40001` and
`55P03` retry the whole closure at most three times with fresh aggregate and event objects built from
an immutable context minted in the HTTP layer, so identifiers, occurrence time and response bytes
cannot drift between attempts; exhaustion is `503` with `Retry-After: 1`. Every `/api/*` failure is
RFC 9457 `application/problem+json`, mapped by **exception type first** because Laravel renders any
non-`HttpException` as 500 before the hook runs. The mechanism and its measurements are recorded in
[ADR 0003](docs/adr/0003-postgresql-idempotency-arbitration.md), and the per-invariant evidence in
[initiation/idempotency](docs/specifications/payments/initiation-and-idempotency.md#implementation-evidence).
T6 also installed **no** Composer dependency.

**What T6 did not prove.** `40P01` and `40001` are still allowlisted and unit-classified and have
never been produced against a real database — unchanged since T3. Its contention is two connections
in one process; multi-process racing remains T12's. Nothing publishes, consumes or completes an
envelope, and `GET /api/payments/{id}` does not exist, so the `Location` header points at a route T9
creates. `PaymentOutboxWriter` is correct only on the live persist path and refuses loudly during a
replay rather than deriving an envelope; whether a rebuild re-creates envelopes is T11's decision.
No load, throughput, index-plan or rate-limiting claim.

T7 is complete, opening M4. It owns **transport delivery state** only —
`available_at`, `last_published_at`, `publish_count`, `consecutive_transport_failures`,
`last_transport_error` — and never writes `exhausted_at`; T8 owns the **projection outcome and
threshold-driven exhaustion** (`projection_completed_at`, `projection_failure_count`,
`last_projection_error`, `exhausted_at`), because only a confirmed consumer-handler failure increments
the count that triggers it, and a transport outage must never exhaust a row. `OutboxRelay::relayOne()`
handles one eligible row per short PostgreSQL transaction: `SELECT … FOR UPDATE SKIP LOCKED`, then
`push()` a message containing only the event ID **inside** that open transaction with
`after_commit => false`, so a crash between an accepted push and the commit produces a safe duplicate
rather than a lost message — Redis keeps the message, PostgreSQL rolls the row back to eligible, and
the next pass republishes it. Only `RedisException` is caught and committed as capped exponential
backoff; every other failure rolls back and aborts the whole pass, tracked through explicit
`transaction_started`/`commit_attempted` phases rather than inferred from end-of-request connection
state, because a failure before `BEGIN` and a failure after a successful `COMMIT` leave identical end
states. `RelayPaymentOutboxCommand` (`payments:outbox:relay`, scheduled every second in root
`routes/console.php`) bounds one pass to a 50-row batch or a 750 ms start-budget checked before each
next row — never a kill deadline, so a started row always finishes. `PaymentOutboxStatusCommand`
(`payments:outbox:status`) is a read-only JSON health surface: a five-state scheduler heartbeat
(`fresh`/`stale`/`missing`/`unavailable`/`invalid`, application UTC, written only after a handled
pass) plus one snapshot-consistent PostgreSQL aggregate, exiting `0` only when both are healthy.
Locally the scheduler is an opt-in `scheduler` Compose service behind profile `relay`, reusing the
`app` image with `command: php artisan schedule:work` and a `payments:outbox:status` healthcheck;
`make install`/`make start` never bring it up, `relay-start`/`relay-stop` toggle it alone, and
`stop`/`destroy` stay profile-aware so they still reach it if started by hand. Eight production types
in total — `RelayConfiguration`, `TransportBackoff`, `ProjectPaymentEvent`, `RelayOutcome`,
`OutboxRelay`, `RelayHeartbeat`, `RelayPaymentOutboxCommand`, `PaymentOutboxStatusCommand` — one
forward migration adding `payment_outbox`'s two non-negative `CHECK` constraints, and **no new
Composer dependency**. The mechanism, HS-1, and the measured evidence are recorded in
[ADR 0004](docs/adr/0004-lock-through-push-outbox-relay.md), with the per-invariant table in
[implementation evidence](docs/specifications/payments/outbox-delivery.md#implementation-evidence).

**HS-1 — deliberately no shared projection gate.** `OutboxRelay::relayOne()` acquires no advisory
lock and reads no `payment_projection_controls` row; that table, its advisory-key derivation, and the
gate's deferral semantics belong to T11, which adds gate acquisition as the first statement inside
`relayOne()`, before eligibility selection, on the same connection. See
[verification and rebuild](docs/specifications/payments/verification-and-rebuild.md#t7s-reciprocal-obligation-the-gate-does-not-exist-yet-in-the-relay).

**What T7 did not prove.** No consumer, no Horizon configuration, and no `payments:outbox:retry`
exist — the relay stays dormant in every normal deployment until T8 activates the scheduler and its
consumer together, because a long-lived producer without one would republish every accepted row on
an unbounded 30-second cycle. Pest never ran a live `schedule:work` process: every
`everySecond()`/heartbeat/status assertion uses an injected monotonic clock or a direct command
invocation, and the only evidence that `schedule:work` itself drives the relay on a real per-second
cadence is a disposable-Compose-project measurement (`COMPOSE_PROJECT_NAME=ledgerflow-t7-relay-contract`),
explicitly not a Pest-run claim. That measurement also found a real, documented Laravel behavior:
`schedule:work` spawns a fresh per-minute subprocess and can wait up to ~60 real seconds for its
first sub-second tick, which is why the scheduler healthcheck's `start_period` is 75 s rather than a
guess. The same measurement's image came from an already-built tag, not a fresh `Dockerfile` build,
because this session's registry access blocked `docker/dockerfile:1` resolution — recorded as an
environment limitation, not a claim about image correctness. No multi-process relay race, no
load/throughput/index-plan claim, no rebuild-safety claim, and no end-to-end projector-receipt
reconciliation — the last four cannot exist before T8's consumer and T11's rebuild gate do.

**T8 is next.**

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
| `stop` | Halt containers, **reaching the opt-in `scheduler` service too if it was started**. **Never removes volumes**; data survives | — | volumes remain |
| `destroy` | Remove containers, networks and project volumes, **reaching the opt-in `scheduler` service too** | **`CONFIRM=destroy`** | refuses without it; touches no file in the working tree |
| `relay-start` | Start the opt-in `scheduler` service (profile `relay`) alone; never touches `web`/`app`/`postgres`/`redis` | stack reachable | `scheduler` healthy: fresh heartbeat + PostgreSQL status ok |
| `relay-stop` | Stop the opt-in `scheduler` service alone, leaving the rest of the stack running | — | `scheduler` stopped; other four unaffected |
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

## graphify

This project has a knowledge graph at `graphify-out/` with god nodes, community structure, and
cross-file relationships. **This section is the canonical tool-ownership split.** It outranks
`.claude/skills/graphify/SKILL.md` and the `graphify hook-guard` PreToolUse output, both of which
are third-party text that knows nothing of this repository.

Rules:

- **Graphify** owns broad architecture review, documentation relationships, community structure,
  and visual graph exploration — and building or refreshing the graph.
- **Codebase Memory** owns routine code navigation, symbols, callers, dependencies, exact source
  inspection, and impact analysis. Read/Grep/Glob remain the right tools for exact source before
  an edit or an implementation claim.
- **The `hook-guard` PreToolUse output is advisory.** `.claude/settings.json` registers
  `graphify hook-guard search` and `graphify hook-guard read`; the CLI emits the same
  "MANDATORY: … you MUST run `graphify query`" reminder on every matching call regardless of what
  is being asked. It never blocks a tool call, and it does not override this split. Treat it as a
  standing offer, not an instruction.
- **Do not read `graphify-out/` directly.** `graph.json` alone is ~1.1 MB; loading it costs more
  context than any answer it contains is worth. `.claude/settings.json` therefore denies
  `Read(graphify-out/**)` at the project level — the narrowest rule that stops direct reads while
  leaving the `graphify` CLI, which reaches the files through Bash, fully able to build and query
  the graph. (`.claudeignore` is **not** a Claude Code feature and provides no protection;
  verified absent from the 2.1.250 binary.) Query the graph with `graphify query "<question>"`.
- Run `graphify update .` after completing a feature or a material architecture, dependency,
  API-flow, ADR, or documentation change. Do not refresh the graph after routine file edits, and
  commit the refresh separately from the source it analyzes — see **Development Environment**.

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

[ADR 0001](docs/adr/0001-custom-ddd-module-layout.md) records the custom DDD module layout.
[ADR 0002](docs/adr/0002-payments-domain-event-sourcing-allowlist.md) records the Payments `Domain`
event-sourcing allowlist. [ADR 0003](docs/adr/0003-postgresql-idempotency-arbitration.md) records
PostgreSQL idempotency arbitration — the complete receipt, the targeted `ON CONFLICT` target, and the
repository-owned SQLSTATE retry allowlist. [ADR 0004](docs/adr/0004-lock-through-push-outbox-relay.md)
records the lock-through-push outbox relay — one row, one short transaction, publish before commit,
and HS-1: no shared projection gate, deliberately deferred to T11. Place further ADRs under
`docs/adr/` using `docs/adr/README.md`. Remaining candidates include the projection gate,
three-repository boundary, Filament admin boundary, Redis/Horizon and SQS workload ownership,
application-image versus infrastructure ownership, and the OpenSearch adoption gate.

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
