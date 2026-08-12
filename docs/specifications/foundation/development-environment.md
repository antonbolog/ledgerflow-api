# Development Environment Specification

## Goal

A clean checkout must behave the same in PhpStorm, VS Code, Cursor, or a terminal. Docker and a
small Makefile interface define the development contract; an editor does not.

## Current state

**T1 and T2 are complete.** The container contract, Makefile interface, and tooling below are
implemented and verified from a clean checkout. `AGENTS.md` is canonical for exact target names,
prerequisites, effects, and evidence; this document states the requirements they satisfy.

Implemented in T1: the multi-stage `Dockerfile` (`base`/`vendor`/`dev`/`production`/`web`),
`compose.yaml` with health checks and persistent volumes, the separate `ledgerflow_test` database,
the twelve-target Makefile, Pest on PostgreSQL with a three-layer database guard, Pint, Rector,
PHPStan level 10, and CI.

Implemented in T2: the `Merchant` and `Payments` modules with enforced layer boundaries
([ADR 0001](../../adr/0001-custom-ddd-module-layout.md)), merchant-user identity moved into
`Modules/Merchant` behind a Sanctum-authenticated application contract, a required and indexed
`users.merchant_id` foreign key, and two deterministic seeded merchants and users.

**All application validation runs through public Make targets** — CI installs from a clean checkout
with `make install` and validates with the single `make check` contract, and contains no Composer,
Pest, Pint, Rector, PHPStan, or Artisan invocation of its own. CI *additionally* performs four
explicitly documented classes of assertion, none of which validate application code:

- **guard assertions** — `destroy`, `db-reset`, `db-migrate`, and `db-seed` must refuse, each with
  its documented refusal message;
- **isolation assertion** — a marker row proves `make check` leaves the development database
  untouched while the suite resets `ledgerflow_test`;
- **image-contract builds** — `docker build --target production` and `--target web`, because
  Compose only ever builds the `dev` target;
- **failure diagnostics** — `docker compose ps` and `logs`, on failure only.

These are contract assertions about the Makefile and the image, not a second validation path.

The entrypoint ends in `exec "$@"` with `CMD ["php-fpm"]`, so Horizon, the scheduler/relay, Reverb,
and future SQS workers reuse the same image with a `command:` override and no image change. That
invariant is what makes "reuse the application image for future worker and scheduler services"
below true rather than aspirational.

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

This is the Milestone 1–3 inventory, split by the task that installs each item. T1 installs only
what it validates.

**Installed and verified by T1**

- PHP **8.4** pinned (`php:8.4.24-fpm-alpine`); `composer.json` requires `^8.4.1`, the minimum the
  locked graph resolves against. Laravel 13.
- Pinned by project configuration: `postgres:18.4-alpine`, `redis:8.10.0-alpine`,
  `nginx:1.30.4-alpine`, `composer:2.8.12`, phpredis 6.3.0.
- Pest 5 and `pest-plugin-arch` 5. Both require PHP `^8.4`, which is what forces the pin.
- Larastan 3 (level 10, no baseline), Pint 1, Rector 2 with `driftingly/rector-laravel`.

**Installed and verified by T2**

- `nwidart/laravel-modules` **13.0.0** — module registration. Pulls
  `wikimedia/composer-merge-plugin` 2.1.0, which needs an `allow-plugins` entry. Editing
  `extra` by hand also invalidates the lock's content hash, so `composer update --lock` is a
  required step, not a fallback: `composer validate --strict` inside `make quality` fails without it.
- `laravel/sanctum` **4.3.3** — merchant-user identity. Installed with `composer require` and
  `vendor:publish --tag=sanctum-config` only. `install:api` was **not** run: it would also create
  `routes/api.php`, register `api:` in `bootstrap/app.php`, and ship an example route, none of which
  has a consumer until the task that serves a real endpoint.

**Deferred to the task with the first consumer**

- `spatie/laravel-data` 4 — a DTO package with no DTO is a dependency with no consumer, which is the
  same ground `laravel/pail` was removed on. It arrives with the first Payments DTO.

**Installed and verified by T3**

- `spatie/laravel-event-sourcing` **7.15.1** — the compatibility spike's subject. It requires
  `illuminate/* ^10.0|^11.0|^12.0|^13.0` and `php ^8.0`, so Laravel 13.24.0 on PHP 8.4 is in range;
  Laravel 13 is only accepted from 7.14.0 onward, and 7.13.0 stops at `^12.0`. It pulls nine
  transitive packages: `spatie/laravel-package-tools` 1.93.1, `spatie/laravel-schemaless-attributes`
  2.6.0, `spatie/better-types` 1.1.0, `spatie/php-attribute-reader` 1.1.0, `symfony/serializer`
  8.1.4, `symfony/property-info` 8.1.4, `symfony/property-access` 8.1.4, `symfony/type-info` 8.1.0,
  and `symfony/polyfill-php82` 1.38.1. T3 installed the package and read its source; it published no
  configuration and no migration. See **Spatie event sourcing, as actually installed** below.

`laravel/pail` was removed from the skeleton's dependencies: it has no consumer, since the Makefile
owns the command surface and no `logs` target exists.

No dependency is added only because it is fashionable, and none is kept without a consumer. The DDD
module layout is owned by this repository rather than delegated to an opinionated scaffolding
package; see [ADR 0001](../../adr/0001-custom-ddd-module-layout.md).

### Sanctum, as actually installed

Two things differ from what was assumed when T2 was planned, and both are load-bearing:

- **Sanctum 4.3.3 does not load its migration from the package.** Its service provider calls
  `publishesMigrations()` and never `loadMigrationsFrom()`, so `personal_access_tokens` simply does
  not exist until the migration is published. T2 therefore published it as
  `database/migrations/2026_08_10_175810_create_personal_access_tokens_table.php`. Laravel re-stamps
  a published migration with the current time, so it sorts **after** the two Merchant migrations
  rather than at the package's original `2019_12_14` timestamp. That is harmless here:
  `personal_access_tokens` is polymorphic and holds no foreign key to `users` or `merchants`, so it
  has no ordering dependency in either direction.
- **`config/sanctum.php` needed a `(string)` cast** on `env('SANCTUM_STATEFUL_DOMAINS', …)`. Larastan
  types `env()` as `bool|string|null`, `config/` is analysed at level 10, and the file fails analysis
  exactly as published.

Sanctum registers its own `sanctum` guard through `SanctumServiceProvider::configureGuard()`, proven
by a test that authenticates a real bearer token. No guard entry was added to `config/auth.php`,
because an unnecessary one is configuration nobody can later tell is load-bearing.

**Deferred because T2 ships no API endpoint.** Token abilities are unscoped (`['*']` wherever a token
is minted), `sanctum.expiration` is null so tokens do not expire, and the SPA cookie-versus-token
decision named in [interfaces and realtime](../../architecture/interfaces-and-realtime.md) is
untouched. `config/sanctum.php` is published in full, so its `stateful`, `guard`, `expiration` and
`middleware` keys are unreviewed defaults. Whoever serves the first endpoint owns all four questions
and should read that file rather than inherit it.

### Spatie event sourcing, as actually installed

Read from `vendor/spatie/laravel-event-sourcing` at 7.15.1 during T3. Everything below is a source
citation, not a reading of the package's documentation.

**The three Spatie base types that bear on the Payments `Domain` allowlist.** They are listed
together because the spike extends all three, **not** because they share a destination. Only the
first two are candidates for a future Payments `Domain` allowlist; the third belongs in
`Infrastructure` and is not part of that exception at all.
[ADR 0001](../../adr/0001-custom-ddd-module-layout.md) reserves the decision for the task that first
puts one in production code:

| Type | Kind | Layer it would live in | Source |
|---|---|---|---|
| `Spatie\EventSourcing\AggregateRoots\AggregateRoot` | abstract class | `Domain` — **allowlist candidate** | `src/AggregateRoots/AggregateRoot.php:26` |
| `Spatie\EventSourcing\StoredEvents\ShouldBeStored` | abstract class | `Domain` — **allowlist candidate** | `src/StoredEvents/ShouldBeStored.php:12` |
| `Spatie\EventSourcing\EventHandlers\Projectors\Projector` | abstract class, implements `EventHandler` | `Infrastructure` — **not** an allowlist candidate | `src/EventHandlers/Projectors/Projector.php:7` |

These three are not everything the spike subclassed: it also extends `EloquentStoredEvent` and
`EloquentSnapshot`, both test-only `Infrastructure` persistence models that raise no allowlist
question and are described later in this section.

A projector writes a read model. It is a persistence adapter driven by the event stream, so it sits
in `Infrastructure` beside the Eloquent models, and the boundary rules already permit that without
any exception. Widening the `Domain` allowlist to include it would grant `Domain` access to a type it
must never reference.

**The aggregate lifecycle is four calls**, all on `AggregateRoot`:

- `public static function retrieve(string $uuid): static` (`:50-57`) resolves a **new instance through
  the container** with `app(static::class)`, assigns the uuid, and replays stored events via
  `reconstituteFromEvents()`. Because it resolves rather than reuses, every call yields a distinct
  object — which is what makes "fresh aggregate per retry attempt" achievable without any extra
  machinery.
- `public function loadUuid(string $uuid): static` (`:59-64`) is the instance-level equivalent: it
  assigns the uuid onto an aggregate you already hold and replays from there. Same reconstitution, no
  container resolution, so it does **not** give a fresh object.
- `public function recordThat(ShouldBeStored $domainEvent): static` (`:107-120`) stamps the aggregate
  uuid and `created_at` onto the event, appends it to the in-memory recorded set, applies it, and
  stamps the aggregate version. Nothing is written to the database here.
- `public function persist(): static` (`:122-133`) is the only call that writes.

Both retrieval paths read through the stored-event repository, so both touch the database on whatever
connection that repository's model resolves to.

- **It publishes its migrations and does not load them**, the same trap Sanctum sprang in T2.
  `EventSourcingServiceProvider::configurePackage()` calls `->hasMigrations([...])` but never
  `->runsMigrations()` (`src/EventSourcingServiceProvider.php:29-32`), and
  `ProcessMigrations::bootPackageMigrations()` only calls `loadMigrationsFrom()` when
  `$this->package->runsMigrations` is true, which defaults to `false`
  (`laravel-package-tools/src/Concerns/PackageServiceProvider/ProcessMigrations.php:39-41`,
  `Concerns/Package/HasMigrations.php:7`). Confirmed by `artisan migrate:status`, which does not list
  `create_stored_events_table` even with the provider discovered. **No application migration creates
  `stored_events` until someone publishes that one.** T3 published none, so the table is absent from
  the development database `ledgerflow`; the test-only spike harness creates it in `ledgerflow_test`
  from its own verbatim copy of the stub.
- **Its configuration does not need publishing.** `hasConfigFile()` routes through
  `ProcessConfigs::registerPackageConfigs()`, which calls `mergeConfigFrom()` during `register()`
  (`laravel-package-tools/src/Concerns/PackageServiceProvider/ProcessConfigs.php:7-27`), so every
  `event-sourcing.*` default is readable without `config/event-sourcing.php` existing.
- **`persist()` opens no transaction.** `AggregateRoot::persist()`
  (`src/AggregateRoots/AggregateRoot.php:122-133`) calls `persistWithoutApplyingToEventHandlers()`
  and then handles the stored events inline. Only the opt-in
  `AggregateRoot::persistInTransaction()` wraps work in `DB::transaction()` (`:328-335`). A caller's
  own transaction is therefore the only transaction, which is what the Payments atomic write needs.
- **The stored-event model has no connection of its own.** `EloquentStoredEvent` sets
  `protected $table = 'stored_events'` and declares no `$connection`
  (`src/StoredEvents/Models/EloquentStoredEvent.php:15-21`), so it follows Eloquent's default
  connection. It can be pointed elsewhere without publishing configuration, because the repository
  reads `config('event-sourcing.stored_event_model', ...)` at construction
  (`src/StoredEvents/Repositories/EloquentStoredEventRepository.php:26`) and the aggregate resolves
  its repository through `$this->storedEventRepository ?? config('event-sourcing.stored_event_repository')`
  (`AggregateRoot.php:174-177`).
- **A handler runs inline unless it implements `ShouldQueue`.** `StoredEvent::handle()` calls
  `Projectionist::handleWithSyncEventHandlers()` first (`src/StoredEvents/StoredEvent.php:74-76`),
  and only dispatches a job when `shouldDispatchJob()` finds handlers that *are* `ShouldQueue`
  (`:101-107`). The sync and async sets are split purely on `instanceof ShouldQueue`
  (`src/EventHandlers/EventHandlerCollection.php:54-74`). The `event-sourcing.queue` config names the
  queue; it does not decide whether one is used.
- **`catch_exceptions` decides whether a failing handler is visible.** `Projectionist::callEventHandler()`
  catches `Exception` and swallows it when `catchExceptions` is true (`src/Projectionist.php:355-367`,
  flag set at `:42`). The shipped default is `false`
  (`config/event-sourcing.php:48`, `env('EVENT_PROJECTOR_CATCH_EXCEPTIONS', false)`), so a failing
  synchronous handler propagates and takes the caller's transaction down with it. **Setting
  `EVENT_PROJECTOR_CATCH_EXCEPTIONS=true` would silently break the Payments outbox atomicity
  guarantee**, because the stored event would persist while its outbox row did not.
- **No event class map is required.** `enforce_event_class_map` defaults to `false` and
  `event_class_map` to `[]` (`config/event-sourcing.php:90,97`), so
  `EloquentStoredEventRepository::getEventClass()` stores the fully-qualified class name
  (`:163-177`).
- **A duplicate aggregate version does not surface as a database error.**
  `persistWithoutApplyingToEventHandlers()` catches `QueryException`, and for a
  `UniqueConstraintViolationException` rethrows it as `CouldNotPersistAggregate::invalidVersion()`
  (`AggregateRoot.php:139-155`). Any retry policy that classifies failures by SQLSTATE will not see
  this one.
- **Auto-discovery scans `app/` only.** `auto_discover_projectors_and_reactors` defaults to
  `[app()->path()]` (`config/event-sourcing.php:9-11`), so handlers living in a module are registered
  explicitly through `Projectionist::addProjector()` (`src/Projectionist.php:71`).
- **`persist()` writes each stored event twice.** `EloquentStoredEventRepository::persist()` calls
  `save()`, then writes the event's own id into `meta_data` and calls `save()` again
  (`:130,134`) — one `INSERT` followed by one `UPDATE` per event.

**Laravel's concurrency detector cannot express the Payments retry allowlist.**
`Illuminate\Database\ConcurrencyErrorDetector` matches `40001` by code and detects `40P01` only
through the English message `'deadlock detected'`; it does not recognize `55P03` at all
(`ConcurrencyErrorDetector.php:20,26-28`). Measured against a real PostgreSQL 18.4 lock conflict, it
returns `false`. The task that implements the documented `40P01`/`40001`/`55P03` retry allowlist must
own its own SQLSTATE matching rather than delegating to the framework.

**Observed exception shape** (PostgreSQL 18.4, PDO pgsql, forced against a real row lock and a real
unique index):

| SQLSTATE | Exception class | `getCode()` | `errorInfo[0]` |
|---|---|---|---|
| `55P03` lock_not_available | `Illuminate\Database\QueryException` | `'55P03'` (string) | `'55P03'` |
| `23505` unique_violation | `Illuminate\Database\UniqueConstraintViolationException` | `'23505'` (string) | `'23505'` |

Both wrap a `PDOException` and both carry the SQLSTATE in `errorInfo[0]` and in `getCode()` as a
**string**, so a comparison against an integer would silently never match. `23505` arriving as
`UniqueConstraintViolationException` rather than a plain `QueryException` is what makes the aggregate
version behavior above possible.

### What T3 deliberately did not add

T3 is a compatibility spike. Every executable artifact it produced — spike fixtures, the copied
migrations, the schema they create, and the retry runner — is test-only, under
`Modules/Payments/tests/`, which `tests/Architecture/ModuleBoundaryTest.php` excludes from every layer
scan and `phpstan.neon` excludes via `Modules/*/tests/*`. It also changed tooling configuration and
documentation, listed below. Recorded explicitly so a reader does not go looking for artifacts that
were never created:

- **No production Payments types.** Both `Domain` layers are still empty. The spike's aggregate,
  event, projector and stored-event model live in its test tree and are named `Spike*` throughout.
- **No production schema.** `stored_events` and `snapshots` are created only inside `ledgerflow_test`,
  by migrations copied byte-for-byte from the vendor stubs into the spike's own directory and run
  against a test-only connection. **`stored_events` does not exist in the development database
  (`ledgerflow`)**, and `database/migrations/` gained nothing.
- **No configuration published.** `config/event-sourcing.php` does not exist; the package's defaults
  are merged, and the spike swaps `stored_event_model` and `snapshot_model` at runtime per test and
  restores them afterwards.
- **No `Domain` allowlist, no ADR, no architecture-rule exception.**
  `tests/Architecture/ModuleBoundaryTest.php` and `BaselineTest.php` are unchanged, and `docs/adr/`
  gained no record. Because no vendor type entered production module code, there was nothing to
  permit. The task that first puts one there owns that decision, as
  [ADR 0001](../../adr/0001-custom-ddd-module-layout.md) reserves.

What T3 did add, by category:

- the `spatie/laravel-event-sourcing` dependency, in `composer.json` and `composer.lock`;
- the complete test-only spike under `Modules/Payments/tests/` — fixtures, the two verbatim vendor
  migration copies, and the four Pest suites that prove Q1–Q5;
- `autoload-dev` PSR-4 for `Modules\Payments\Tests\` in `Modules/Payments/composer.json`;
- `pint.json` and `rector.php` exclusions for the copied migrations, so formatters cannot rewrite
  files whose value is being byte-identical to the vendor stubs;
- `.gitignore` for the ignored `/.ai/` local workspace;
- documentation: this file, [initiation and
  idempotency](../payments/initiation-and-idempotency.md), [the roadmap](../../roadmap.md), and
  `AGENTS.md`.

The measured invariant results live in
[initiation and idempotency](../payments/initiation-and-idempotency.md#blocking-compatibility-evidence).

## Editor and ignore policy

`.gitignore` excludes IDE metadata such as `.idea/`, `.vscode/`, `.cursor/`, `.codex/`, `.fleet/`,
and local history, plus local environment files, credentials, dependencies, OS metadata, logs,
coverage, and generated runtime data.

Shared editor configuration is limited to `.editorconfig`, adopted as an explicit project decision
in T1 so that PhpStorm, VS Code, and Cursor apply the same whitespace and encoding rules to one
checkout. Any further shared editor configuration requires its own explicit decision.

## Acceptance evidence

From a clean checkout with Git, Docker, and Docker Compose:

1. The documented installation path succeeds. **(T1)**
2. Application, PostgreSQL, and Redis health checks become healthy. **(T1)**
3. The seeding command runs successfully and is repeatable, establishing the deterministic
   `db-seed` contract. **(T1)** Two deterministic merchants and users are seeded through that same
   command. **(T2 — satisfied.)**

   The determinism contract is precise. A re-run creates no duplicate identities, never moves an
   existing email to a different merchant, and never rewrites a password hash. It does **not**
   promise byte-identical rows: `updateOrCreate()` and `save()` will refresh timestamps if a
   fixture's non-key attributes ever drift from what is stored, which is a correction rather than a
   violation. Identities are keyed by slug and email, never by id, because ids come from a
   PostgreSQL sequence and are not stable across a rebuild. The whole fixture set is written in one
   transaction, so a conflict on the second identity cannot leave the first one committed.
4. Unit, integration, architecture, static-analysis, formatting, and full-check contracts run only
   through documented project targets. **(T1)**
5. Destructive targets refuse to run without explicit confirmation and cannot remove source files.
   **(T1)**

This list is the Milestone 1 exit criterion. T1 established the command contracts; T2 supplied the
deterministic identities that item 3's second clause requires. **Every item is now verified.**

Note what proves what. `make check` runs `quality` and `test`; it never invokes `db-seed`. The suite
proves the seeder's logic — idempotence, collision refusal, transactional rollback — because those
tests call `$this->seed()` directly. Running `make db-seed` twice against the development database is
what proves the command contract. Both are required; neither substitutes for the other.

Validation must not be invented ad hoc: `check` is the only CI-equivalent entry point, and CI calls
it rather than reproducing its steps.

## Known deferred concerns

Recorded so they are not rediscovered as surprises:

- **`public/storage` is not served.** The local nginx container mounts only `./public`, so a
  `storage:link` symlink points outside its filesystem and user-uploaded files would 404. Nothing in
  T1 uses it; the first feature that does needs a narrow second mount or object storage.
- **The Pest suites are not statically analysed.** PHPStan cannot see through Pest's fluent DSL, so
  only `tests/bootstrap.php` is analysed from `tests/`, and module suites are excluded by
  `Modules/*/tests/*`. The trailing `/*` is load-bearing: PHPStan matches that pattern against file
  paths, so without it the exclusion silently does nothing. The suites are verified by running them.
- **PHPStan level 10 holds against the code T2 landed.** Eloquent models, factories, a seeder and a
  container binding all pass at level 10 with no baseline. The cost is `@property` annotations on
  models: attribute access and assignment are typed from them, and an incomplete list fails analysis
  rather than silently passing. Both `Domain` layers are still empty, so level 10 remains untested
  against domain code proper; the earlier prediction that it would need lowering is deferred to the
  Payments slice, not resolved.
- **Editing `.env` requires `make start`** before the app container observes the change. The
  database guard reads the running container, so a stale container is refused rather than silently
  used.
- **`make test` leaves `postgres` and `redis` running.**
- **The `$_SERVER` env-precedence trap** that let the suite reach the development database is fixed
  in `tests/bootstrap.php`, but the fix is not itself covered by a test.
- **CI has no Docker layer caching**, deliberately: `docker compose build` cannot use the Actions
  cache backend without a buildx bake setup that local development would not share.

## Related

- [Roadmap](../../roadmap.md)
- [Runtime contract](../../operations/runtime-contract.md)
- [Resilience test plan](../../testing/payments-resilience.md)
