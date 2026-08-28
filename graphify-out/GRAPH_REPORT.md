# Graph Report - ledgerflow-api  (2026-08-28)

## Corpus Check
- 189 files · ~113,046 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1046 nodes · 1677 edges · 103 communities (87 shown, 16 thin omitted)
- Extraction: 97% EXTRACTED · 3% INFERRED · 0% AMBIGUOUS · INFERRED: 53 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `6f0907df`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- OutboxRelay
- Throwable
- Illuminate\Support\Facades\Schema
- RelayTransport
- RecordingConnection
- InvalidPaymentInitiation
- Payments Outbox Delivery Specification
- SpikeFailureSwitch
- StoredIdempotencyReceipt
- ADR 0003 — PostgreSQL idempotency arbitration
- Illuminate\Database\ConnectionInterface
- PDOException
- devDependencies
- InitiatePaymentController.php
- Idempotent Consumer Transaction
- Spatie Event Sourcing As Actually Installed
- MerchantContextTest.php
- SpikeCommandContext
- PaymentInitiated
- RelayPaymentOutboxCommandTest.php
- Payments bounded context
- M5 — Verification, rebuild, and resilience proof
- RelayHeartbeat
- SpikeProbeLog
- SpikeDatabase
- T7 — Scheduler-driven outbox relay and separated failure semantics
- PaymentsServiceProvider.php
- ModuleBoundaryTest.php
- Illuminate\Support\Facades\DB
- OutboxRelayTest.php
- PaymentInitiationContext
- OpenSearch Adoption Gate
- TransportBackoff
- compose `postgres` service (18.4-alpine)
- tests/Architecture/ModuleBoundaryTest.php enforcement
- composer.json
- require-dev
- Payments module instructions
- Merchant/composer.json
- InitiatePayment
- RelayConfiguration
- config
- SpikeRelayDispatchTest.php
- compose `app` service (php-fpm, dev target)
- ADR 0002 Payments Domain Event-Sourcing Allowlist
- assert-contracts.sh
- SpikeDatabase.php
- Payments/composer.json
- IntegrationDatabase
- Spatie\EventSourcing\StoredEvents\ShouldBeStored
- Permissive robots.txt Crawl Policy
- scripts
- Documentation instructions
- PaymentOutboxStatusCommand
- ProblemDetails
- keywords
- require
- POST /api/payments HTTP Contract
- Required Operational Evidence
- $_SERVER Environment-Precedence Defect and Repair
- Merchant
- Messaging policy (Redis/Horizon vs SQS/DLQ ownership)
- extra
- RFC 9457 Problem Details Surface
- T4 Blocking Compatibility Evidence (Q6-Q10)
- payment_outbox Envelope and Partial Active Index
- SpikeAdvisoryLock
- verify-agent-instructions.sh
- psr-4
- logging.php
- sanctum.php
- Test instructions
- InitiationCommitBoundaryTest.php
- rector.php
- Illuminate\Support\Facades\Route
- ExampleTest
- modules.php
- Makefile Public Interface
- Controller.php
- entrypoint.sh
- 10-create-test-database.sh
- What T3 Deliberately Did Not Add
- First Vertical Slice Scenario
- PaymentOutboxWriter
- .github/AGENTS.md

## God Nodes (most connected - your core abstractions)
1. `PaymentInitiated` - 29 edges
2. `Merchant` - 25 edges
3. `RelayConfiguration` - 25 edges
4. `RecordingConnection` - 25 edges
5. `SpikeDatabase` - 23 edges
6. `User` - 19 edges
7. `OutboxRelay` - 19 edges
8. `RelayHeartbeat` - 17 edges
9. `RelayTransport` - 17 edges
10. `PaymentInitiationContext` - 16 edges

## Surprising Connections (you probably didn't know these)
- `ci_isolation_marker development-database proof` --semantically_similar_to--> `H-2 — unwrapped commit-boundary suite`  [INFERRED] [semantically similar]
  .github/workflows/ci.yml → docs/adr/0003-postgresql-idempotency-arbitration.md
- `Quickstart (make install, make check)` --semantically_similar_to--> `CI `make check` job`  [INFERRED] [semantically similar]
  README.md → .github/workflows/ci.yml
- `Lock-through-Redis-push relay (design 12A)` --conceptually_related_to--> `T7 — Scheduler-driven outbox relay and separated failure semantics`  [INFERRED]
  TODOS.md → docs/roadmap.md
- `Typed target-specific confirmation guards` --semantically_similar_to--> `Evolution-trigger discipline (do not build in anticipation of load)`  [INFERRED] [semantically similar]
  AGENTS.md → TODOS.md
- `Worker/scheduler processes reuse the app image via command override` --conceptually_related_to--> `T7 — Scheduler-driven outbox relay and separated failure semantics`  [INFERRED]
  compose.yaml → docs/roadmap.md

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **ADRs governing the Payments write path** — docs_adr_0001_custom_ddd_module_layout_adr_0001, docs_adr_0002_payments_domain_event_sourcing_allowlist_adr_0002, docs_adr_0003_postgresql_idempotency_arbitration_adr_0003, agents_module_boundary_test, agents_payments_bounded_context [EXTRACTED 1.00]
- **Local development stack services and their contracts** — compose_web_service, compose_app_service, compose_postgres_service, compose_redis_service, agents_makefile_command_contract, _github_workflows_ci_make_check_job [EXTRACTED 1.00]
- **M4 asynchronous tracer bullet (relay → projection → query)** — docs_roadmap_t7, docs_roadmap_t8, docs_roadmap_t9, agents_t7_transport_delivery_state, agents_t8_projection_outcome_state, todos_lock_through_push_relay [EXTRACTED 1.00]
- **Outbox Delivery Lifecycle** — docs_specifications_payments_outbox_delivery_envelope_and_index, docs_specifications_payments_outbox_delivery_relay_algorithm, docs_specifications_payments_outbox_delivery_state_transitions, docs_specifications_payments_outbox_delivery_scheduler_contract, docs_specifications_payments_projection_and_query_consumer_transaction, docs_architecture_messaging_and_queues_payments_projection_queue [EXTRACTED 1.00]
- **One-Transaction Payment Initiation Commit** — docs_specifications_payments_initiation_and_idempotency_atomic_write, docs_specifications_payments_initiation_and_idempotency_targeted_on_conflict, docs_specifications_payments_initiation_and_idempotency_transaction_retry, docs_specifications_payments_outbox_delivery_payment_outbox_writer, docs_specifications_foundation_development_environment_catch_exceptions_pin, docs_specifications_foundation_development_environment_persist_opens_no_transaction [EXTRACTED 1.00]
- **Advisory-Lock Projection Gate and Rebuild Safety** — docs_specifications_payments_verification_and_rebuild_projection_gate, docs_specifications_payments_verification_and_rebuild_rebuild_algorithm, docs_specifications_payments_verification_and_rebuild_session_loss_evidence, docs_specifications_foundation_development_environment_connection_loss_behavior, docs_specifications_payments_verification_and_rebuild_read_only_verification [EXTRACTED 1.00]

## Communities (103 total, 16 thin omitted)

### Community 0 - "OutboxRelay"
Cohesion: 0.18
Nodes (7): Illuminate\Contracts\Queue\Factory, OutboxRelay, relayWithRejectingTransport(), self, RejectingQueue, RedisException, RelayOutcome

### Community 1 - "Throwable"
Cohesion: 0.08
Nodes (21): ProblemType, ProblemDetailsMapper, Illuminate\Auth\AuthenticationException, Illuminate\Foundation\Application, Illuminate\Foundation\Configuration\Exceptions, Illuminate\Foundation\Configuration\Middleware, Illuminate\Foundation\Inspiring, Illuminate\Http\Request (+13 more)

### Community 2 - "Illuminate\Support\Facades\Schema"
Cohesion: 0.08
Nodes (3): Illuminate\Database\Migrations\Migration, Illuminate\Database\Schema\Blueprint, Illuminate\Support\Facades\Schema

### Community 3 - "RelayTransport"
Cohesion: 0.06
Nodes (17): Illuminate\Cache\ArrayStore, Illuminate\Cache\RedisStore, Illuminate\Console\Scheduling\Schedule, Illuminate\Queue\Jobs\InspectedJob, Illuminate\Queue\SyncQueue, Illuminate\Support\Carbon, Illuminate\Support\Collection, Illuminate\Support\ConfigurationUrlParser (+9 more)

### Community 4 - "RecordingConnection"
Cohesion: 0.06
Nodes (11): Closure, Illuminate\Contracts\Queue\ShouldQueueAfterCommit, Illuminate\Database\DeadlockException, Illuminate\Foundation\Http\FormRequest, Illuminate\Validation\Rule, LogicException, run(), InitiatePaymentRequest (+3 more)

### Community 5 - "InvalidPaymentInitiation"
Cohesion: 0.17
Nodes (4): DateTimeImmutable, InvalidArgumentException, InvalidPaymentInitiation, self

### Community 6 - "Payments Outbox Delivery Specification"
Cohesion: 0.09
Nodes (34): 0004 — Publish the payment outbox with a lock-through-push relay, without a shared projection gate, Alternatives considered, Consequences, Context, Decision, HS-1 — the shared projection gate is deliberately omitted here, Measured evidence, Related (+26 more)

### Community 7 - "SpikeFailureSwitch"
Cohesion: 0.19
Nodes (5): SpikeFailureSwitch, SpikeOutboxWriter, SpikeStoredEvent, Spatie\EventSourcing\Facades\Projectionist, Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent

### Community 8 - "StoredIdempotencyReceipt"
Cohesion: 0.22
Nodes (4): findWinner(), reserve(), self, StoredIdempotencyReceipt

### Community 9 - "ADR 0003 — PostgreSQL idempotency arbitration"
Cohesion: 0.18
Nodes (20): spatie/laravel-data deferral criterion, Ledgerflow architecture index, ADR 0001 — Custom DDD module layout, Cross-module access only through Application, Four-layer module convention (Domain/Application/Infrastructure/Http), Rejected: third-party DDD scaffolding package, ADR 0002 — Payments Domain event-sourcing allowlist, Rejected: hand-written framework-independent aggregate/event abstraction (+12 more)

### Community 10 - "Illuminate\Database\ConnectionInterface"
Cohesion: 0.18
Nodes (5): Illuminate\Database\ConnectionInterface, RetryingAtomicTransaction, ContentionProbe, ThrowingProjector, Spatie\EventSourcing\EventHandlers\Projectors\Projector

### Community 11 - "PDOException"
Cohesion: 0.26
Nodes (4): Illuminate\Database\UniqueConstraintViolationException, SqlStates, StringCodePdoException, PDOException

### Community 12 - "devDependencies"
Cohesion: 0.11
Nodes (17): concurrently, laravel-vite-plugin, devDependencies, concurrently, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite (+9 more)

### Community 13 - "InitiatePaymentController.php"
Cohesion: 0.36
Nodes (4): Illuminate\Http\Response, Modules\Merchant\Application\Identity\MerchantContext, InitiatePaymentController, __construct()

### Community 14 - "Idempotent Consumer Transaction"
Cohesion: 0.16
Nodes (18): Event and Delivery Path, Payments Minimal Data Model, Gated Rebuild Path, Single-Transaction Write Path, Payments Tracer Bullet, timestampTz(6) Precision Requirement, Shared DatabaseTransactionsManager Callback Locality, Idempotency Receipt Schema Invariants (+10 more)

### Community 15 - "Spatie Event Sourcing As Actually Installed"
Cohesion: 0.15
Nodes (18): AggregateRoot Four-Call Lifecycle, Projector Auto-Discovery Scans app/ Only, catch_exceptions Pinned to Literal false, Laravel ConcurrencyErrorDetector Cannot Express the Retry Allowlist, Duplicate Aggregate Version Never Reaches SQLSTATE Classification, Observed SQLSTATE Exception Shape, persist() Opens No Transaction, Nested Transaction Needed After an Expected Constraint Violation (+10 more)

### Community 16 - "MerchantContextTest.php"
Cohesion: 0.21
Nodes (6): Illuminate\Contracts\Auth\Factory, Laravel\Sanctum\PersonalAccessToken, Laravel\Sanctum\Sanctum, NoAuthenticatedMerchantUser, self, AuthenticatedMerchantContext

### Community 17 - "SpikeCommandContext"
Cohesion: 0.20
Nodes (4): self, SpikeCommandContext, SpikePaymentAggregate, Spatie\EventSourcing\AggregateRoots\AggregateRoot

### Community 18 - "PaymentInitiated"
Cohesion: 0.16
Nodes (11): DomainException, PaymentInitiated, PaymentAlreadyInitiated, self, PaymentAggregate, static, persistOnePayment(), t5StoredEvent() (+3 more)

### Community 19 - "RelayPaymentOutboxCommandTest.php"
Cohesion: 0.13
Nodes (11): Carbon\CarbonImmutable, Illuminate\Log\Events\MessageLogged, Illuminate\Support\Facades\Event, Illuminate\Support\Str, bindRelayCommand(), passContexts(), passLogs(), ArrayObject (+3 more)

### Community 20 - "Payments bounded context"
Cohesion: 0.15
Nodes (15): assert_guard_refuses (database allowlist guard assertion), Failure diagnostics step, CI `make check` job, Make-only validation contract in CI, restore (EXIT trap restoring .env and app container), ledgerflow-api repository, Makefile command contract, Merchant bounded context (+7 more)

### Community 21 - "M5 — Verification, rebuild, and resilience proof"
Cohesion: 0.13
Nodes (15): assert_refuses (destructive-target guard assertion), Typed target-specific confirmation guards, 40P01 and 40001 allowlisted but never produced, M5 — Verification, rebuild, and resilience proof, T10 — Bounded projection/outbox verification and targeted repair, T11 — Connection-affine gated batch rebuild and recovery, T12 — Prove end-to-end and process-level resilience failures, T13 — Five-minute resilience demo and operational guide (+7 more)

### Community 22 - "RelayHeartbeat"
Cohesion: 0.22
Nodes (6): Factory, Illuminate\Contracts\Cache\Factory, RelayPaymentOutboxCommand, RelayHeartbeat, Modules\Payments\Infrastructure\Relay\RelayOutcome, Psr\Log\LoggerInterface

### Community 23 - "SpikeProbeLog"
Cohesion: 0.16
Nodes (4): Closure, spikeContention(), SpikeInitiationHandler, SpikeProbeLog

### Community 25 - "T7 — Scheduler-driven outbox relay and separated failure semantics"
Cohesion: 0.19
Nodes (14): T7 transport delivery state ownership, T8 projection outcome and threshold-driven exhaustion ownership, Outbox payload read back, never re-serialised, M3 — Atomic payment initiation, M4 — Relay, projection, and query, Planned system evolution after M5, T5 — Initiation aggregate, event, schema, and invariants, T6 — Atomic idempotent payment initiation and Problem Details (+6 more)

### Community 26 - "PaymentsServiceProvider.php"
Cohesion: 0.12
Nodes (12): AppServiceProvider, Illuminate\Contracts\Foundation\Application, Illuminate\Database\DatabaseManager, Illuminate\Database\Eloquent\Relations\Relation, Illuminate\Support\ServiceProvider, MerchantServiceProvider, DatabaseIdempotencyReceipts, PaymentsServiceProvider (+4 more)

### Community 27 - "ModuleBoundaryTest.php"
Cohesion: 0.24
Nodes (9): Illuminate\Database\ConcurrencyErrorDetector, Illuminate\Database\DetectsConcurrencyErrors, Spatie\EventSourcing\Attributes\EventVersion, ledgerflowAfterCommitOptIns(), ledgerflowHelperCalls(), ledgerflowNextSymbol(), ledgerflowPreviousToken(), ledgerflowReferences() (+1 more)

### Community 28 - "Illuminate\Support\Facades\DB"
Cohesion: 0.20
Nodes (4): Illuminate\Contracts\Database\LostConnectionDetector, Illuminate\Database\QueryException, Illuminate\Support\Facades\DB, SpikeInjectedFailure

### Community 29 - "OutboxRelayTest.php"
Cohesion: 0.20
Nodes (7): Illuminate\Database\Events\TransactionCommitted, Illuminate\Database\Events\TransactionRolledBack, Illuminate\Support\Facades\Log, ArrayObject, relay(), relayCapturedLogs(), relayLogField()

### Community 30 - "PaymentInitiationContext"
Cohesion: 0.22
Nodes (3): PaymentInitiationContext, Initiations, slice2Context()

### Community 31 - "OpenSearch Adoption Gate"
Cohesion: 0.17
Nodes (13): OpenSearch Adoption Gate, Planned AWS Topology, One Image, Independently Supervised Processes, payments-projection Redis Queue, SQS Adoption Boundary, One Job, One Transport Rule, AWS Readiness Gates, Horizon Worker Process (+5 more)

### Community 33 - "compose `postgres` service (18.4-alpine)"
Cohesion: 0.25
Nodes (11): ci_isolation_marker development-database proof, PostgreSQL and transport test isolation, tests/bootstrap.php env enforcement (phpunit.xml declares, bootstrap enforces), compose `postgres` service (18.4-alpine), compose `redis` service (8.10.0-alpine), Illuminate ConcurrencyErrorDetector rejected on measurement, H-2 — unwrapped commit-boundary suite, Repository-owned SQLSTATE retry allowlist (40P01, 40001, 55P03) (+3 more)

### Community 34 - "tests/Architecture/ModuleBoundaryTest.php enforcement"
Cohesion: 0.18
Nodes (10): tests/Architecture/ModuleBoundaryTest.php enforcement, Named Laravel global-helper denylist, Token-scanner enforcement limits, Exact class-name matching, never namespace prefixes, LEDGERFLOW_DOMAIN_ALLOWLIST constant, Native single-segment PHP types outside the exception, Projector named and excluded from Domain, M1 — Laravel foundation and executable contracts (+2 more)

### Community 35 - "composer.json"
Cohesion: 0.18
Nodes (10): autoload-dev, psr-4, description, license, minimum-stability, name, prefer-stable, Tests\\ (+2 more)

### Community 36 - "require-dev"
Cohesion: 0.18
Nodes (11): require-dev, driftingly/rector-laravel, fakerphp/faker, larastan/larastan, laravel/pint, mockery/mockery, nunomaduro/collision, pestphp/pest (+3 more)

### Community 37 - "Payments module instructions"
Cohesion: 0.33
Nodes (5): Boundaries, Evidence, Invariants, Payments module instructions, Scope

### Community 38 - "Merchant/composer.json"
Cohesion: 0.18
Nodes (10): autoload, autoload-dev, psr-4, psr-4, description, name, Modules\\Merchant\\, Modules\\Merchant\\Database\\Factories\\ (+2 more)

### Community 39 - "InitiatePayment"
Cohesion: 0.43
Nodes (3): InitiatePayment, AtomicTransaction, IdempotencyReceipts

### Community 41 - "config"
Cohesion: 0.20
Nodes (10): pestphp/pest-plugin, php-http/discovery, wikimedia/composer-merge-plugin, config, allow-plugins, optimize-autoloader, platform, preferred-install (+2 more)

### Community 42 - "SpikeRelayDispatchTest.php"
Cohesion: 0.18
Nodes (5): Illuminate\Queue\Events\JobQueued, Illuminate\Queue\RedisQueue, Illuminate\Support\Facades\Queue, SpikeRelayAttempt, SpikeRelayJob

### Community 43 - "compose `app` service (php-fpm, dev target)"
Cohesion: 0.31
Nodes (9): Image contract builds (production and web targets), Multi-stage image topology (base → vendor → dev/production → web), Runtime topology (web → app → postgres/redis), compose `app` service (php-fpm, dev target), cgi-fcgi FastCGI ping healthcheck, Loopback-only published port, nginx mounts only ./public read-only, compose `web` service (nginx 1.30.4-alpine) (+1 more)

### Community 44 - "ADR 0002 Payments Domain Event-Sourcing Allowlist"
Cohesion: 0.25
Nodes (9): ADR 0001 Custom DDD Module Layout, ADR 0002 Payments Domain Event-Sourcing Allowlist, Payments and Merchant Bounded Contexts, Event Class Map Alias, eventVersion() Override Instead of #[EventVersion], occurredAt Stored as a String, Alias Is Stable Identity, Not Class Name, PaymentInitiated Event Contract (+1 more)

### Community 45 - "assert-contracts.sh"
Cohesion: 0.60
Nodes (5): assert_guard_refuses(), assert_refuses(), cleanup(), restore_environment(), assert-contracts.sh script

### Community 46 - "SpikeDatabase.php"
Cohesion: 0.50
Nodes (3): SpikeSnapshot, Spatie\EventSourcing\Snapshots\EloquentSnapshot, Spatie\EventSourcing\StoredEvents\Repositories\StoredEventRepository

### Community 47 - "Payments/composer.json"
Cohesion: 0.22
Nodes (8): autoload, autoload-dev, psr-4, psr-4, description, name, Modules\\Payments\\, Modules\\Payments\\Tests\\

### Community 51 - "scripts"
Cohesion: 0.25
Nodes (8): scripts, post-autoload-dump, post-update-cmd, pre-package-uninstall, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump, Illuminate\\Foundation\\ComposerScripts::prePackageUninstall, @php artisan package:discover --ansi, @php artisan vendor:publish --tag=laravel-assets --ansi --force

### Community 52 - "Documentation instructions"
Cohesion: 0.50
Nodes (3): Documentation instructions, Ownership, Rules

### Community 53 - "PaymentOutboxStatusCommand"
Cohesion: 0.39
Nodes (4): Illuminate\Console\Command, Illuminate\Database\Connection, PaymentOutboxStatusCommand, stdClass

### Community 54 - "ProblemDetails"
Cohesion: 0.43
Nodes (3): ProblemType, ProblemDetails, Illuminate\Http\JsonResponse

### Community 55 - "keywords"
Cohesion: 0.29
Nodes (7): keywords, cqrs, ddd, event-sourcing, fintech, laravel, payments

### Community 56 - "require"
Cohesion: 0.29
Nodes (7): require, laravel/framework, laravel/sanctum, laravel/tinker, nwidart/laravel-modules, php, spatie/laravel-event-sourcing

### Community 57 - "POST /api/payments HTTP Contract"
Cohesion: 0.33
Nodes (7): Frontend Deployment Gate, Deferred Sanctum/SPA Session Decisions, MerchantContext Tenant Resolution, Sanctum Bearer-Token Authentication, POST /api/payments, Sanctum As Actually Installed, POST /api/payments HTTP Contract

### Community 58 - "Required Operational Evidence"
Cohesion: 0.29
Nodes (7): Filament Admin Boundary, Queue Failure Ownership, First-Slice Scope Exclusions, Required Operational Evidence, Raw Idempotency Key Never Persisted or Logged, payments:projections:verify Read-Only Verification, payments:outbox:repair Targeted Repair

### Community 59 - "$_SERVER Environment-Precedence Defect and Repair"
Cohesion: 0.29
Nodes (7): Known Deferred Concerns, $_SERVER Environment-Precedence Defect and Repair, Test-Only Spike Artifacts and Blast Radius, Spike Harness Limits, T3 Blocking Compatibility Evidence (Q1-Q5), Test Environment Rules, Failure Injection Discipline

### Community 60 - "Merchant"
Cohesion: 0.05
Nodes (28): DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Eloquent\Attributes\Fillable, Illuminate\Database\Eloquent\Attributes\Hidden, Illuminate\Database\Eloquent\Factories\Factory, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Database\Eloquent\Model, Illuminate\Database\Eloquent\Relations\BelongsTo (+20 more)

### Community 61 - "Messaging policy (Redis/Horizon vs SQS/DLQ ownership)"
Cohesion: 0.40
Nodes (6): Messaging policy (Redis/Horizon vs SQS/DLQ ownership), Three-repository ownership strategy, Patch-pinned images, ADR candidate list, Planned stack, Repository ecosystem table

### Community 62 - "extra"
Cohesion: 0.33
Nodes (6): extra, laravel, merge-plugin, dont-discover, include, Modules/*/composer.json

### Community 63 - "RFC 9457 Problem Details Surface"
Cohesion: 0.33
Nodes (6): GET /api/payments/{paymentId}, No Dependency Without a Consumer, Exception-Type-First Problem Mapping, RFC 9457 Problem Details Surface, No CHECK Constraints on Counters Yet, Eventual-Consistency Query Contract

### Community 64 - "T4 Blocking Compatibility Evidence (Q6-Q10)"
Cohesion: 0.47
Nodes (6): Connection Loss and Stale transactionLevel(), Queue and Redis Configuration Caching, Laravel Queue Publication Path, Constraints T7 Inherits, T4 Blocking Compatibility Evidence (Q6-Q10), Neither a Successful Query Nor transactionLevel() Proves Lock Survival

### Community 65 - "payment_outbox Envelope and Partial Active Index"
Cohesion: 0.33
Nodes (6): 63-Byte Identifier Limit and Explicit Key Names, PHPStan Level 10 With No Baseline, What T5 Added, payment_outbox Envelope and Partial Active Index, Five-Failure Exhaustion Threshold, Three-Task Outbox Writer Ownership Split

### Community 67 - "verify-agent-instructions.sh"
Cohesion: 1.00
Nodes (3): assert_adapter(), fail(), verify-agent-instructions.sh script

### Community 69 - "psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 70 - "logging.php"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 71 - "sanctum.php"
Cohesion: 0.50
Nodes (3): Illuminate\Cookie\Middleware\EncryptCookies, Illuminate\Foundation\Http\Middleware\ValidateCsrfToken, Laravel\Sanctum\Http\Middleware\AuthenticateSession

### Community 72 - "Test instructions"
Cohesion: 0.50
Nodes (3): Evidence quality, Isolation, Test instructions

### Community 73 - "InitiationCommitBoundaryTest.php"
Cohesion: 0.25
Nodes (4): Illuminate\Database\Events\TransactionCommitting, IdentityRecorder, installContentionRig(), SplObjectStorage

### Community 74 - "rector.php"
Cohesion: 0.40
Nodes (4): Rector\Config\RectorConfig, RectorLaravel\Rector\ArrayDimFetch\EnvVariableToEnvHelperRector, RectorLaravel\Rector\ArrayDimFetch\ServerVariableToRequestFacadeRector, RectorLaravel\Set\LaravelSetList

### Community 78 - "Makefile Public Interface"
Cohesion: 0.67
Nodes (3): CI Contract Assertions, Makefile Public Interface, Seeder Determinism Contract

### Community 97 - "PaymentOutboxWriter"
Cohesion: 0.19
Nodes (7): Illuminate\Database\Events\QueryExecuted, OutboxEnvelopeNotDerivable, self, PaymentOutboxWriter, handWrittenPaymentInitiated(), runOutboxWriter(), Spatie\EventSourcing\Projectionist

## Knowledge Gaps
- **115 isolated node(s):** `name`, `description`, `Modules\\Merchant\\`, `Modules\\Merchant\\Database\\Factories\\`, `Modules\\Merchant\\Database\\Seeders\\` (+110 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **16 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `RelayConfiguration` connect `RelayConfiguration` to `OutboxRelay`, `TransportBackoff`, `RelayTransport`, `InvalidPaymentInitiation`, `RelayPaymentOutboxCommandTest.php`, `PaymentOutboxStatusCommand`, `RelayHeartbeat`, `PaymentsServiceProvider.php`, `OutboxRelayTest.php`?**
  _High betweenness centrality (0.040) - this node is a cross-community bridge._
- **Why does `Merchant` connect `Merchant` to `PaymentOutboxWriter`, `InitiationCommitBoundaryTest.php`, `InitiatePaymentController.php`, `MerchantContextTest.php`, `PaymentInitiated`, `RelayPaymentOutboxCommandTest.php`?**
  _High betweenness centrality (0.034) - this node is a cross-community bridge._
- **Why does `RelayTransport` connect `RelayTransport` to `RelayPaymentOutboxCommandTest.php`, `OutboxRelayTest.php`?**
  _High betweenness centrality (0.026) - this node is a cross-community bridge._
- **What connects `name`, `description`, `Modules\\Merchant\\` to the rest of the system?**
  _115 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Throwable` be split into smaller, more focused modules?**
  _Cohesion score 0.0782051282051282 - nodes in this community are weakly interconnected._
- **Should `Illuminate\Support\Facades\Schema` be split into smaller, more focused modules?**
  _Cohesion score 0.08392603129445235 - nodes in this community are weakly interconnected._
- **Should `RelayTransport` be split into smaller, more focused modules?**
  _Cohesion score 0.0595959595959596 - nodes in this community are weakly interconnected._