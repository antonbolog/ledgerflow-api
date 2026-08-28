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

**All validation runs through public Make targets.** CI installs from a clean checkout with
`make install`, then calls the single full-pipeline target `make ci`. That target composes the normal
`make check` application validation with three additional contract classes; the workflow contains no
Composer, Pest, Pint, Rector, PHPStan, Artisan, or image-build sequence of its own:

- **guard assertions** — `destroy`, `db-reset`, `db-migrate`, and `db-seed` must refuse, each with
  its documented refusal message;
- **isolation assertion** — a marker row proves `make check` leaves the development database
  untouched while the suite resets `ledgerflow_test`;
- **image-contract builds** — `docker build --target production` and `--target web`, because
  Compose only ever builds the `dev` target;
Failure diagnostics remain workflow orchestration only. The guard and image commands live behind
public Make targets, so local and CI behavior cannot drift into two implementations.

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
| Validation | `agent-check`, `test`, `quality`, `check` | Run instruction parity, supported tests, quality tools, and normal local validation |
| Full CI | `ci-contract`, `image-contract`, `ci` | Assert guards/isolation, build deployable image targets, and compose the CI-equivalent contract |
| Database | `db-migrate`, `db-seed` | Apply schema and deterministic development fixtures |
| Destructive database | `db-reset` | Reset local/test data only after explicit confirmation |

`AGENTS.md` documents prerequisites, effects, and expected evidence. CI must call the same public
targets instead of duplicating hidden command sequences.

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
  same ground `laravel/pail` was removed on. It arrives with the first DTO that needs **casting,
  transformation, or more than one representation**. T6 shipped three DTO-shaped types and installed
  nothing: `FormRequest` already produces the validation failure shape, the command context is an
  immutable set of scalars that must live in a layer where the boundary rule forbids external
  namespaced classes, and the success body is contractually one pinned `json_encode()` rather than a
  transformer chain a package upgrade can re-order.

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

**Still deferred, and T6 deliberately left them deferred.** T6 serves the first authenticated
endpoint and proves real bearer-token authentication against this guard — which establishes that the
guard works, not which session mode the SPA will use. Token abilities remain unscoped (`['*']`
wherever a token is minted), `sanctum.expiration` remains null so tokens do not expire, and the SPA
cookie-versus-token decision named in
[interfaces and realtime](../../architecture/interfaces-and-realtime.md) is untouched.
`config/sanctum.php` is published in full, so its `stateful`, `guard`, `expiration` and `middleware`
keys are unreviewed defaults. All four questions — together with CORS, CSRF and the deployment
domains — now belong to
[Merchant dashboard integration](../../roadmap.md#merchant-dashboard-integration), not to whoever
served the first endpoint: none of them can be answered before the SPA and its deployment topology
are fixed. Read that file rather than inherit it.

### Spatie event sourcing, as actually installed

Read from `vendor/spatie/laravel-event-sourcing` at 7.15.1 during T3. Everything below is a source
citation, not a reading of the package's documentation.

**The three Spatie base types that bear on the Payments `Domain` allowlist.** They are listed
together because the spike extends all three, **not** because they share a destination. **T5 settled
this in [ADR 0002](../../adr/0002-payments-domain-event-sourcing-allowlist.md)**: the first two are
permitted in Payments `Domain`, matched as exact class names and in that module only; the third is
named and excluded, and belongs in `Infrastructure` where the unmodified rules already permit it.

| Type | Kind | Layer it lives in | Source |
|---|---|---|---|
| `Spatie\EventSourcing\AggregateRoots\AggregateRoot` | abstract class | `Domain` — **permitted (ADR 0002)** | `src/AggregateRoots/AggregateRoot.php:26` |
| `Spatie\EventSourcing\StoredEvents\ShouldBeStored` | abstract class | `Domain` — **permitted (ADR 0002)** | `src/StoredEvents/ShouldBeStored.php:12` |
| `Spatie\EventSourcing\EventHandlers\Projectors\Projector` | abstract class, implements `EventHandler` | `Infrastructure` — **excluded by name** | `src/EventHandlers/Projectors/Projector.php:7` |

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
  `stored_events` until the application owns one.** That was the state through T3 and T4. **T5 now
  owns four migrations** under `Modules/Payments/database/migrations/`, so `stored_events` and
  `snapshots` — schema-identical to the vendor stubs — plus `payment_idempotency_receipts` and
  `payment_outbox` exist in `ledgerflow` and `ledgerflow_test`. The spike harness creates its copies
  only when the tables are absent, so from T5 onward the T3/T4 suites run against these migrations.
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
  synchronous handler propagates and takes the caller's transaction down with it. A `true` value
  **would silently break the Payments outbox atomicity guarantee**, because the stored event would
  persist while its outbox row did not.

  **T5 closed that hole.** The application's `config/event-sourcing.php` pins `catch_exceptions` to a
  **literal `false`**, so the environment variable is no longer consulted at all and no deployment can
  set it. A test reads the application file directly — `require`, plus a token scan proving the file
  contains no `env()` call — because the merged runtime value is `false` either way and therefore
  cannot tell a deliberate pin from a deleted key.
- **No event class map is required by the package.** `enforce_event_class_map` defaults to `false`
  and `event_class_map` to `[]` (`config/event-sourcing.php:90,97`), so
  `EloquentStoredEventRepository::getEventClass()` stores the fully-qualified class name
  (`:163-177`). **T5 nonetheless supplies one** — not because the package demands it, but because the
  alias is what lets the namespace move without rewriting history. See **What T5 added** below.
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

> **Historical record of T3, superseded in part by T5.** The four bullets below were true when T3
> landed and remain an accurate account of *that task*. T5 has since added production types, schema
> and configuration; where a bullet reads in the present tense, **What T5 added** below is current.

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

### Laravel queue, Redis and connection loss, as actually resolved

Read from `vendor/laravel/framework` at **13.24.0** during T4, and measured against PostgreSQL 18.4
and Redis 8.10.0 with phpredis 6.3.0. Every line is a source citation or a measurement, not a reading
of the documentation.

**Publication path.**

- `RedisQueue::push()` routes through `Queue::enqueueUsing()`, which consults
  `shouldDispatchAfterCommit($job)` and, when true and `db.transactions` is bound, registers the push
  as a transaction callback instead of performing it (`Queue/RedisQueue.php:302-313`,
  `Queue/Queue.php:366-387`).
- **`pushRaw()` bypasses that check entirely** and is unconditionally immediate
  (`Queue/RedisQueue.php:323-335`).
- **A job can override the connection.** `shouldDispatchAfterCommit()` returns true for any
  `ShouldQueueAfterCommit` job, and for any object with a truthy `$afterCommit` property, *before* the
  connection's `after_commit` config is read (`Queue/Queue.php:396-405`). The connection default comes
  from `RedisConnector::connect()` (`Queue/Connectors/RedisConnector.php:42-53`).
- One `push()` writes two keys through a single Lua `eval`: `queues:<name>` and `queues:<name>:notify`
  (`Queue/LuaScripts.php:32-38`). **Measured: exactly those two keys and no others.**
- `size()` runs through `eval`, while `pendingSize()` (`llen`) and `pendingJobs()` (`lrange`, decoded
  into `InspectedJob`) run through ordinary commands (`Queue/RedisQueue.php:110-131,161-172`,
  `Queue/Jobs/InspectedJob.php:29-49`). That is the axis on which phpredis prefixing could disagree
  with itself, so the spike asserts all three agree. **Measured: they do.**

**Configuration caching, and why order matters.**

- `RedisManager` captures `database.redis` once, when the singleton is built
  (`Redis/RedisServiceProvider.php:18-22`, `Redis/RedisManager.php:88-122`), so a connection added to
  config later is invisible until the instance is forgotten.
- `QueueManager::connection()` caches the resolved queue **per name**
  (`Queue/QueueManager.php:158-198`), so two behaviours need two configured names.
- The Redis queue connector resolves `app['redis']` **inside** a closure invoked on every `resolve()`
  (`Queue/QueueServiceProvider.php:202-207`), which is what makes forgetting the instance before the
  first `Queue::connection()` both sufficient and necessary.
- Per-connection Redis `options` beat the global ones: `PhpRedisConnector::connect()` merges the
  connection's own array last and applies `prefix` and `database` from it
  (`Redis/Connectors/PhpRedisConnector.php:25-39,115-120`).

**Transaction-manager callbacks.** Every connection — including one registered at runtime — is handed
the **same singleton** `DatabaseTransactionsManager` (`Database/DatabaseManager.php:102,236-253`,
`Database/DatabaseServiceProvider.php:79`). `addCallback()` attaches to
`getPendingTransactions()->last()` across **all** connections, not the current one
(`Database/DatabaseTransactionsManager.php:213-220,236-242`). **Callback locality is therefore
accidental**, and the spike asserts it rather than assuming it: with `RefreshDatabase` holding the
default connection open, the pending records read `["pgsql@1", "pgsql_spike@1"]` and `last()` is
`pgsql_spike` only because the spike began last. A commit fires the callbacks; a **rollback discards
them** (`Database/DatabaseTransactionsManager.php:66-93,120-146,150-176`).

**Connection loss.** `Connection::run()` rethrows only when `transactions >= 1`; otherwise it
reconnects and re-runs (`Database/Connection.php:799-828,1010-1040`). `reconnect()` swaps the PDO and
never touches the transaction counter (`Database/DatabaseManager.php:333-345,374-385`), and
`disconnect()` leaves it as well (`Database/Connection.php:1064-1081`), so `transactionLevel()` can
outlive the backend it describes (`Database/Concerns/ManagesTransactions.php:341-344`). Classification
is **substring matching on the message**, not SQLSTATE (`Database/LostConnectionDetector.php:17-105`).

**Measured shapes.**

| What | Observed on this stack |
|---|---|
| `pg_locks` for a two-integer advisory key | `locktype 'advisory'`, `classid` = first int, `objid` = second int, **`objsubid` = 2**, `mode` `ShareLock`/`ExclusiveLock`, `granted` boolean, `pid` = holder. Advisory locks are per-database and `pg_locks` reports the whole cluster, so the query filters on the current database OID |
| Bounded advisory wait | `lock_timeout` **does** apply to advisory waits: `QueryException`, SQLSTATE `55P03` in both `errorInfo[0]` and `getCode()` as a **string**, message `canceling statement due to lock timeout`. The `statement_timeout` hang defense never fired |
| `pg_terminate_backend`, no transaction open | No exception. Silent reconnect onto a **new backend pid**; the advisory lock is gone |
| `pg_terminate_backend`, transaction open | `QueryException` wrapping `PDOException`, SQLSTATE **`HY000`** — *not* `57P01`. The message carries both `FATAL: terminating connection due to administrator command` and `server closed the connection unexpectedly`, and it is the **second** that `LostConnectionDetector` matches. **Classification is message based, so anything keying off SQLSTATE would misread this as a generic error.** `transactionLevel()` still reported `1` |
| Immediate `push()` inside an open transaction | Redis held the message while the observer still read the pre-update row. `JobQueued` fired on the named connection at spike `transactionLevel() === 1` |
| `after_commit => true` twin | Queue empty inside the closure, one message after commit, `JobQueued` at `transactionLevel() === 0` |

**Test-only artifacts.** The spike writes Redis on **logical database 15**, pinned in its own
connection config with an empty key prefix, and deletes only the eight keys it can create, by name —
never `KEYS *`, `flushdb` or `flushall`. Database 15 is chosen to reduce blast radius and is **not**
claimed as exclusively owned; correctness comes from the key list. The outbox surrogate
`spike_outbox_delivery` is a **three-column test-only table** (`event_id`, `last_published_at`,
`publish_count`) in `ledgerflow_test` only, and is not a proposal for `payment_outbox`.

### The environment-precedence defect T4 found, and its repair

`phpunit.xml` declared `QUEUE_CONNECTION=sync`, `CACHE_STORE=array` and `SESSION_DRIVER=array` with
`force="true"`, but **PHPUnit writes only `putenv()` and `$_ENV`, never `$_SERVER`** — the same defeat
mechanism `tests/bootstrap.php` already documented for the database keys. Compose's `env_file` puts
the Redis-backed development values into `$_SERVER`, and Laravel's env resolver reads `$_SERVER`
first, so all three declarations silently lost.

**Measured before the repair:** `config('queue.default')` was `redis`, `config('cache.default')` was
`redis`, `config('session.driver')` was `redis`. The suite's **default queue connection was a real
Redis queue on logical database 0** — the database a developer's stack uses. Nothing in the suite
dispatched without naming a connection, so no data was written, but any future unnamed dispatch would
have gone straight into that keyspace.

**Repair.** `tests/bootstrap.php` now validates those three declared values the same way it validates
the database keys — refusing rather than silently substituting if `phpunit.xml` stops declaring them —
and then forces them into `$_SERVER`, `$_ENV` and `getenv()` before the autoloader runs. The
agreement proof at the end of that file covers them too. Three assertions in
`tests/Feature/InfrastructureContractTest.php` now check the **live** resolved objects: the default
queue is a `SyncQueue`, the default cache store is an `ArrayStore`, and the session driver is `array`.
`phpunit.xml` itself was not changed; it remains where the intent is declared, and the bootstrap is
what makes it win.

A suite that reaches Redis explicitly and by name is fine — `InfrastructureContractTest` still asserts
real Redis connectivity, and the T4 spike uses named connections on database 15. What is not fine is
reaching shared infrastructure through a framework default nobody chose.

### What T4 deliberately did not add

> **Historical record of T4, superseded in part by T5.** True when T4 landed and still an accurate
> account of *that task*. For the current state of production types, schema and configuration, see
> **What T5 added** below.

T4 is a compatibility spike. Every executable artifact it produced is test-only, under
`Modules/Payments/tests/`, which `tests/Architecture/ModuleBoundaryTest.php` excludes from every layer
scan and `phpstan.neon` excludes via `Modules/*/tests/*`. Recorded explicitly so a reader does not go
looking for artifacts that were never created:

- **No dependency.** `composer.json` and `composer.lock` are untouched.
- **No production Payments types.** Both `Domain` layers are still empty; every fixture is named
  `Spike*` and lives in the test tree.
- **No production schema.** `spike_outbox_delivery` exists only in `ledgerflow_test`, created by the
  test harness. `database/migrations/` and `Modules/Payments/database/migrations/` gained nothing.
- **No configuration published, and no `phpunit.xml` change.** The spike registers its Redis and queue
  connections at runtime, in `config()`, per test.
- **No Horizon, no scheduler, no relay command, no worker.** Nothing consumes a published message.
- **No ADR, no `Domain` allowlist, no architecture-rule exception.**
  `tests/Architecture/ModuleBoundaryTest.php` and `BaselineTest.php` are unchanged, because no vendor
  type entered production module code.

What T4 did add, by category:

- the test-only spike under `Modules/Payments/tests/` — `SpikeAdvisoryLock`, `SpikeRelayQueue`,
  `SpikeRelayJob`, `SpikeRelayAttempt`, additions to `SpikeDatabase` and `SpikeFailureSwitch`, and two
  Pest suites proving Q6–Q10;
- the test-isolation repair in `tests/bootstrap.php` and its three contract assertions in
  `tests/Feature/InfrastructureContractTest.php`;
- documentation: this file, [outbox delivery](../payments/outbox-delivery.md),
  [verification and rebuild](../payments/verification-and-rebuild.md),
  [the roadmap](../../roadmap.md), and `AGENTS.md`.

The measured invariant results live in
[outbox delivery](../payments/outbox-delivery.md#blocking-compatibility-evidence).

### What T5 added, and what it deliberately did not

T5 is the first task with production Payments code. It added no dependency: `composer.json` and
`composer.lock` are byte-identical to what T4 left.

**Added:**

- **Payments `Domain`** — `PaymentAggregate`, the immutable `PaymentInitiated`, and the exceptions
  `InvalidPaymentInitiation` and `PaymentAlreadyInitiated`. Merchant's `Domain` is still empty.
- **[ADR 0002](../../adr/0002-payments-domain-event-sourcing-allowlist.md)** and the narrowed
  `LEDGERFLOW_DOMAIN_ALLOWLIST` in `tests/Architecture/ModuleBoundaryTest.php`, permitting exactly
  two external namespaced Spatie classes — `AggregateRoots\AggregateRoot` and
  `StoredEvents\ShouldBeStored` — matched as exact names, in the Payments module only. `Projector`
  and `#[EventVersion]` are named and excluded. Native single-segment PHP types are unaffected: they
  were always outside the rule by ADR 0001's scanner semantics, and no native-type allowlist exists.
- **Four migrations** in `Modules/Payments/database/migrations/`. `stored_events` and `snapshots` are
  schema-identical to the vendor stubs; `payment_idempotency_receipts` and `payment_outbox` are
  Payments' own. All four now exist in `ledgerflow` as well as `ledgerflow_test`.
- **`config/event-sourcing.php`**, hand-written with three keys only.
- **`Modules/*/tests/Unit` in the `phpunit.xml` Unit suite**, so module unit tests are collected at
  all. Nothing inside `<php>` was touched.

**Findings that shaped those artifacts:**

- **`snapshots` is required even though nothing writes it.**
  `AggregateRoot::reconstituteFromEvents()` calls `getSnapshotRepository()->retrieve($uuid)`
  unconditionally, and it is the only body reachable from either uuid-binding path — `retrieve()` and
  `loadUuid()`. `EloquentSnapshotRepository::retrieve()` selects from `snapshots` via a `scopeUuid()`
  the model really declares. No configuration switch skips it. The alternative, overriding
  reconstitution or installing a null repository, buys one empty table at the price of an aggregate
  that cannot be replayed.
- **Schema version comes from an overridden `eventVersion()`, not `#[EventVersion]`.** The attribute
  would be a third `Domain` type. Spatie's `ObjectNormalizer` filters `eventVersion` out of the
  payload unless the child declares a *property* of that name, so an overridden method changes the
  durable key set not at all — measured, not assumed.
- **`enforce_event_class_map` stays `false`.** Turning it on throws for any unmapped class, and the
  T3/T4 spikes persist test-only fixtures that must never enter production configuration. An
  exact-equality test on the map gives the same guarantee at `make check` time instead.
- **The dotted alias key is safe.** `Arr::get()` checks `Arr::exists()` *before* it splits on `.`, so
  `event_class_map['payments.payment_initiated']` resolves as a literal key and never as nested
  `['payments']['payment_initiated']`.
- **`occurredAt` is a string because RFC3339 has no sub-second component.** Symfony's
  `DateTimeNormalizer` is in the default normalizer chain, so a `DateTimeImmutable` property would
  silently truncate business time to whole seconds inside the stored payload.
- **`Schema\Builder::$defaultTimePrecision` is `0`**, so a bare `$table->timestamp()` yields
  `timestamp(0)` in PostgreSQL, which **rounds to whole seconds**. Every Payments-owned timestamp
  therefore declares `timestampTz(…, 6)`.
- **PostgreSQL truncates identifiers at 63 bytes.** Laravel's generated name for the receipts
  merchant/key unique would be 68 characters, and a truncated name cannot be dropped by the name a
  later migration computes. The **Payments-authored** keys are named explicitly — the two receipts
  uniques, the receipts composite index, the outbox aggregate-version unique and the outbox partial
  index. Generated names remain on the primary keys, the receipts foreign key and both vendor tables.
  Every generated identifier across the four tables, constraints included, was measured against the
  limit; the longest is 58 characters
  (`payment_idempotency_receipts_idempotency_key_hash_not_null`).
- **A failed statement aborts the whole transaction.** Any test that expects a constraint violation
  and then keeps asserting must wrap the failing statement in a nested transaction, which Laravel
  compiles to `SAVEPOINT` / `ROLLBACK TO SAVEPOINT`. Without it every later statement returns
  `25P02`. T6's persistence tests used this pattern throughout.

**Deliberately not added, and not claimed:**

- **No commit-boundary evidence of its own.** T5's persistence tests run inside `RefreshDatabase`'s
  wrapping transaction. Real cross-connection commit atomicity is T3's measurement and is not
  restated here as new.
- **No concurrency.** No second process, no second connection, no `SKIP LOCKED`, no advisory lock,
  no transaction retry.
- **No outbox writer.** `payment_outbox` has schema and a derivation proof, not a producer. T6 owns
  the projector, its `ShouldQueue` prohibition and its registration — including that
  `auto_discover_projectors_and_reactors` defaults to `[app()->path()]` and never scans `Modules/`.
- **No receipt writer.** `payment_idempotency_receipts` is written only by test fixtures.
- **No relay, queue, worker, projector, command, route, controller or query.** Nothing was enqueued
  and no handler was registered.
- **No query plan asserted.** The eligibility index is proven to exist, partial, on the documented
  columns, with its two predicates joined by `AND`. The planner remains free to choose a sequential
  scan on a handful of rows, and no test pretends otherwise.
- **No snapshot ever written.** `snapshots` is created and only read.
- **`40P01` and `40001` remain unmeasured**, exactly as T3 left them.

### What T7 added, and what it deliberately did not

T7 is the first task to add production Payments *delivery* code, and it added **no Composer
dependency**: `composer.json` and `composer.lock` are byte-identical to what T6 left.

**Added:**

- **Eight production types**, no more, all under `Modules\Payments\Infrastructure\{Relay,Console}`:
  `RelayConfiguration`, `TransportBackoff`, `ProjectPaymentEvent`, `RelayOutcome`, `OutboxRelay`,
  `RelayHeartbeat`, `RelayPaymentOutboxCommand`, `PaymentOutboxStatusCommand`. See
  [ADR 0004](../../adr/0004-lock-through-push-outbox-relay.md) for the decision each one serves and
  why no ninth type — a generic transport abstraction, a custom consumer exception, an Application
  port — was added ahead of a second implementation that would need it.
- **One forward migration**, `2026_08_21_000000_add_payment_outbox_transport_checks.php`, adding the
  two non-negative `CHECK` constraints T5 deliberately deferred for `publish_count` and
  `consecutive_transport_failures`. It does not edit the merged T5 migration.
- **`config/payments.php`**, at the repository root because `ModuleBoundaryTest` forbids a module
  carrying a `config` directory — the same reason `routes/api.php` is root. Every value is either a
  literal three connection/queue/store names Horizon supervision must also agree on, or an env-tunable
  operational timing; `RelayConfiguration::fromArray()` validates all of them once, at container
  resolution, so a mistyped `.env` fails before any row is touched rather than becoming a silently
  wrong delivery schedule.
- **A dedicated Redis connection** `payments` in `config/database.php` (bounded connect/read timeouts,
  `max_retries => 0`) and **a dedicated cache store** `payments-relay` in `config/cache.php`, pinned to
  that same connection — never the default cache store, so a heartbeat write cannot retry or block
  longer than the pass it describes.
- **Schedule registration** in root `routes/console.php` —
  `Schedule::command('payments:outbox:relay')->everySecond()` — and an **opt-in** `scheduler` Compose
  service behind profile `relay`, plus `relay-start`/`relay-stop` Make targets and profile-aware
  `stop`/`destroy`.

**Findings that shaped those artifacts:**

- **`Connection::transaction()` was measured and deliberately not reused.** T6 already found its
  commit-exception path sets `transactions = 0` and rethrows while rolling the PDO back only for a
  concurrency error with attempts remaining — so at one attempt, every other commit-boundary failure
  leaves the *server* transaction open while `transactionLevel()` reports 0. `OutboxRelay` therefore
  manages `beginTransaction()`/`commit()`/`rollBack()` explicitly, tracking `transaction_started` and
  `commit_attempted` as local booleans rather than inferring either from end-of-request connection
  state — which the historical description below the M-3 evidence table proves matters: a failure
  before `BEGIN` and a failure after a successful `COMMIT` leave *identical* end states.
- **`ScheduleWorkCommand` spawns a new `schedule:run` subprocess only at `second === 0` of each
  minute** (`Console/Scheduling/ScheduleWorkCommand.php`); sub-second cadence
  (`everySecond()`/`repeatSeconds`) is handled *inside* that per-minute subprocess
  (`Console/Scheduling/ScheduleRunCommand.php`), not by `schedule:work` itself. A freshly started
  scheduler container can therefore wait up to ~60 real seconds for its first tick, independent of
  anything this project controls. Combined with `redis-data` persisting across container restarts,
  a restarted scheduler correctly reports its own *previous* run's now-stale heartbeat during that
  wait — the five-state heartbeat design working as intended, just not one a 20-second healthcheck
  `start_period` could tolerate. Measured against the real disposable project: `start_period: 75s`
  comfortably covers a worst case of ~62 real seconds.
- **An empty-table `COUNT(*)` aggregate always returns exactly one row**, so `PaymentOutboxStatusCommand`'s
  `selectOne()` result is narrowed with an `instanceof stdClass` check plus `is_numeric()` on each
  column — the same "types, not validation" pattern `OutboxRelay` already uses for its own row read —
  rather than a null-coalescing default that would hide a genuinely malformed aggregate.

**Environment limitation, from Slice 4's disposable-project measurement, recorded rather than
smoothed over.** `docker build`/`docker compose build` could not resolve the `docker/dockerfile:1`
syntax-frontend image over this session's registry proxy — every attempt failed with
`DeadlineExceeded`/`Canceled` regardless of retry. Since the `dev`-target image had already been built
for the main project earlier in the same session, the disposable-project evidence run retagged that
already-built image as the disposable project's `app` and `scheduler` images (removed again
afterward) rather than rebuilding from `Dockerfile`. **This is not a fresh-build proof of the current
`Dockerfile`.** The image *content* was independently proved current by the same `make check` that
built and ran it for the main project; only the disposable-project *rebuild step itself* was skipped,
for an environment reason unrelated to `compose.yaml` or the `Dockerfile`.

**Deliberately not added, and not claimed:**

- **No consumer, no Horizon configuration, no `payments:outbox:retry`.** T8 owns all three; nothing
  in T7 reads `payment_outbox` for projection purposes or writes `projection_completed_at`,
  `projection_failure_count`, `last_projection_error`, or `exhausted_at`.
- **No shared projection gate.** `OutboxRelay::relayOne()` acquires no advisory lock and reads no
  `payment_projection_controls` row — recorded as HS-1 in
  [outbox delivery](../payments/outbox-delivery.md#hs-1-no-shared-projection-gate-is-acquired) and as
  T11's reciprocal obligation in
  [verification and rebuild](../payments/verification-and-rebuild.md#t7s-reciprocal-obligation-the-gate-does-not-exist-yet-in-the-relay).
- **No live scheduler ran inside Pest.** Every `everySecond()`, heartbeat, and status assertion in the
  suite uses an injected monotonic clock or a direct command invocation; the only evidence that
  `schedule:work` itself drives `payments:outbox:relay` on a real cadence is the disposable Compose
  measurement above, which is explicitly not a claim about what Pest exercised.
- **No multi-process relay race.** The `SKIP LOCKED` proof is two connections in one process, matching
  T4's own scope note; genuine multi-process racing remains T12's.
- **No load, throughput, or index-plan claim**, and **no rebuild-safety claim** — see HS-1 above.
- **No end-to-end projector-receipt reconciliation.** The 30-second grace and redelivery are proved
  from the relay's own transport state alone; no projector receipt exists to reconcile against until
  T8.

The measured invariant results live in
[outbox delivery](../payments/outbox-delivery.md#implementation-evidence).

## Editor and ignore policy

`.gitignore` excludes IDE metadata such as `.idea/`, `.vscode/`, `.cursor/`, `.codex/`, `.fleet/`,
and local history, plus local environment files, credentials, dependencies, OS metadata, logs,
coverage, and machine-local runtime data.

**Two directories are deliberate exceptions where generated content is tracked.** `/.claude/` is
tracked so that the shared project permissions, the Graphify PreToolUse hooks, and the vendored
`graphify` skill travel with the checkout and every contributor's agent behaves identically. Only
`/.claude/settings.local.json`, the per-machine permission cache, is ignored — and it is ignored by
this repository's `.gitignore`, not by a user-global ignore file, so the exclusion holds on a fresh
clone on any machine. Under `graphify-out/`, exactly four artifacts are tracked because they *are*
the queryable graph: `graph.json`, `graph.html`, `GRAPH_REPORT.md`, and `.graphify_labels.json`.
`.gitattributes` marks those four `linguist-generated` with `-diff -merge`, so they never produce a
reviewable textual diff and a merge conflict is resolved by regenerating rather than hand-editing.
Everything else in `graphify-out/` is machine-local and ignored: `cache/`, `cost.json`,
`manifest.json` (per-file incremental build state, which conflicts on every merge and proves
nothing), `.graphify_python` and `.graphify_root` (absolute host paths), the dated pre-update
backup directories, and the `*.sig` integrity signatures.

The tracked graph artifacts are refreshed only after a completed feature or a material
architecture, dependency, API-flow, ADR, or documentation change — never after a routine edit — and
the refresh is committed **after** the source commit it analyzes, so its recorded build stamp names
a commit that actually contains the analyzed source.

Direct reading of `graphify-out/` is denied at the project level by a `Read(graphify-out/**)` rule
in `.claude/settings.json`; `graph.json` alone is ~1.1 MB. The rule constrains the agent's file
tools only, leaving the `graphify` CLI free to build and query the graph. `.claudeignore` is not a
Claude Code feature and was removed after being verified absent from the installed 2.1.250 binary.

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

Validation must not be invented ad hoc: `check` is the normal local entry point; after `install`,
`ci` is the CI-equivalent entry point. The workflow calls those public targets rather than
reproducing their steps.

## Known deferred concerns

Recorded so they are not rediscovered as surprises:

- **`public/storage` is not served.** The local nginx container mounts only `./public`, so a
  `storage:link` symlink points outside its filesystem and user-uploaded files would 404. Nothing in
  T1 uses it; the first feature that does needs a narrow second mount or object storage.
- **The Pest suites are not statically analysed.** PHPStan cannot see through Pest's fluent DSL, so
  only `tests/bootstrap.php` is analysed from `tests/`, and module suites are excluded by
  `Modules/*/tests/*`. The trailing `/*` is load-bearing: PHPStan matches that pattern against file
  paths, so without it the exclusion silently does nothing. The suites are verified by running them.
- **PHPStan level 10 holds against domain code too. Resolved, not deferred.** T2's Eloquent models,
  factories, seeder and container binding pass at level 10 with no baseline; the cost there is
  `@property` annotations on models, since attribute access and assignment are typed from them and an
  incomplete list fails analysis rather than silently passing. **T5 populated the Payments `Domain`
  and level 10 still holds with no baseline and no level change** — the aggregate, the immutable
  event with its promoted `readonly` properties, and both exceptions were accepted as written. The
  earlier prediction that the Payments slice would force a lowering did not come true. `phpstan.neon`
  forbids a baseline in writing, so if a future slice cannot hold level 10 the level must be lowered
  explicitly, with a recorded reason.
- **Editing `.env` requires `make start`** before the app container observes the change. The
  database guard reads the running container, so a stale container is refused rather than silently
  used.
- **`make test` leaves `postgres` and `redis` running.**
- **The `$_SERVER` env-precedence trap** that let the suite reach the development database is fixed
  in `tests/bootstrap.php`. T4 found the same trap defeating the queue, cache and session
  declarations and extended the fix to them; those three *are* covered by live assertions in
  `tests/Feature/InfrastructureContractTest.php`. The database half of the fix still has no test of
  its own.
- **CI has no Docker layer caching**, deliberately: `docker compose build` cannot use the Actions
  cache backend without a buildx bake setup that local development would not share.

## Related

- [Roadmap](../../roadmap.md)
- [Runtime contract](../../operations/runtime-contract.md)
- [Outbox delivery](../payments/outbox-delivery.md) — T7's implementation evidence.
- [ADR 0004](../../adr/0004-lock-through-push-outbox-relay.md)
- [Resilience test plan](../../testing/payments-resilience.md)
