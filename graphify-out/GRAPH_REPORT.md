# Graph Report - ledgerflow-api  (2026-09-02)

## Corpus Check
- 80 files · ~156,896 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1250 nodes · 2092 edges · 114 communities (94 shown, 20 thin omitted)
- Extraction: 96% EXTRACTED · 4% INFERRED · 0% AMBIGUOUS · INFERRED: 74 edges (avg confidence: 0.86)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- Projection Envelope Decoding
- Framework Migrations
- Initiation Request Validation
- Laravel Runtime Config
- Idempotent Initiation Use Case
- Idempotency Failure Types
- RFC 9457 Problem Details
- Payment Domain Events
- Projection Failure Classification
- Event Sourcing Configuration
- Atomic Persistence Spikes
- Payments Schema Assertions
- Module Layout ADRs
- Projection Contract Script
- Architecture Index and Gates
- Documentation Ownership Map
- Lock-Through-Push Relay Decision
- Transaction Retry Adapter
- Sanctum Merchant Identity
- Merchant Eloquent Model
- Problem Mapping and Bootstrap
- Frontend Build Tooling
- Outbox Relay Command
- Relay Dispatch Spikes
- Spike Database Harness
- Payments Module Instructions
- Compose Runtime Topology
- Docs Instruction Adapters
- Payments Capability Summary
- Payment Query HTTP Tests
- Module Boundary Enforcement
- Outbox Relay Tests
- Projection Timeout Configuration
- Auth and User Model
- T9 Query Roadmap Entry
- Service Provider Wiring
- Outbox Status Command
- Horizon Supervision Tests
- Projection Consumer Internals
- App Service Providers
- Root Composer Manifest
- Dev Tooling Dependencies
- Test Evidence Discipline
- Merchant Module Manifest
- Spike Failure Switch
- CI Instruction Adapters
- Composer Plugin Config
- ADR 0004 Document
- Deferred Infrastructure Gates
- Relay Command Tests
- Spike Payment Aggregate
- Write Path Config Tests
- Process Topology Contract
- Outbox Retry Command
- Projection Failure Recorder
- Merchant Identity Tests
- Payments Module Manifest
- CI Workflow Contract
- Runtime Dependencies
- Composer Lifecycle Scripts
- Cross-Repository Ownership
- Outbox State and Evidence
- Module Provider Registration
- Spike Outbox Writer
- Project Keywords
- Database Seeders
- Payments Write Path Task
- Module Merge Plugin
- Rector Configuration
- Make Guard Assertions
- App Autoload Map
- Logging Channels
- Sanctum Configuration
- Boundary Test Limits
- Auth Boundary Decisions
- Example Unit Test
- Instruction Parity Script
- Module Package Config
- Relay Heartbeat States
- ADR Context Links
- Validation Standards
- Base Controller
- Container Entrypoint
- Test Database Bootstrap
- First Slice Scope Limits
- Realtime Boundaries
- Known Deferred Defects
- GitHub Instructions
- Problem Type Enum
- Instruction Adapter Pointer
- Empty Domain Rationale
- Outbox Payload Rationale
- First Slice Scenario
- Robots Policy

## God Nodes (most connected - your core abstractions)
1. `PaymentInitiated` - 27 edges
2. `RecordingConnection` - 25 edges
3. `SpikeDatabase` - 23 edges
4. `PaymentProjectionConsumer` - 22 edges
5. `Merchant` - 21 edges
6. `ProjectionEnvelopeRejected` - 21 edges
7. `User` - 19 edges
8. `OutboxRelay` - 19 edges
9. `RelayHeartbeat` - 17 edges
10. `IntegrationDatabase` - 17 edges

## Surprising Connections (you probably didn't know these)
- `Workflows Must Not Duplicate Tool Command Sequences` --semantically_similar_to--> `Makefile as Public Development Interface`  [INFERRED] [semantically similar]
  .github/AGENTS.md → AGENTS.md
- `Unknown and Foreign Payment IDs Answer Identically` --semantically_similar_to--> `Sensitive Payment Data Never Logged`  [INFERRED] [semantically similar]
  README.md → AGENTS.md
- `No Second Development Environment in CI` --conceptually_related_to--> `Docker Compose Local Stack`  [INFERRED]
  .github/AGENTS.md → compose.yaml
- `Record Evidence Only After Source Inspection and Validation` --semantically_similar_to--> `Payments Evidence Discipline (real infra only when needed)`  [INFERRED] [semantically similar]
  docs/AGENTS.md → Modules/Payments/AGENTS.md
- `Payments Evidence Discipline (real infra only when needed)` --semantically_similar_to--> `Avoid Sleeps When Synchronization Is Possible`  [INFERRED] [semantically similar]
  Modules/Payments/AGENTS.md → tests/AGENTS.md

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Asynchronous Payments Projection Delivery Flow** — readme_post_api_payments, agents_outbox_relay, agents_payments_projection_queue, agents_projection_consumer, readme_payment_details_read_model, readme_get_api_payments_paymentid [EXTRACTED 1.00]
- **Path-Scoped Agent Instruction System with CLAUDE.md Parity** — agents_repository_instructions, _github_agents_github_automation_instructions, modules_payments_agents_payments_module_instructions, docs_agents_documentation_instructions, tests_agents_test_instructions, agents_make_agent_check [EXTRACTED 1.00]
- **Make-Driven Validation Contract Across Local and CI** — agents_make_public_interface, agents_make_check, agents_make_ci, _github_workflows_ci_check_job, _github_agents_no_duplicate_command_sequences, compose_stack [EXTRACTED 1.00]
- **Deferred Projection Gate and Its Reciprocal Obligations** — docs_adr_0004_lock_through_push_outbox_relay_hs_1, docs_specifications_payments_verification_and_rebuild_projection_gate, docs_specifications_payments_outbox_delivery_initial_relay_algorithm, docs_specifications_payments_projection_and_query_consumer_transaction, docs_specifications_payments_projection_and_query_query_contract, docs_roadmap_t11 [EXTRACTED 1.00]
- **POST to GET Payments Delivery Pipeline** — docs_specifications_payments_initiation_and_idempotency_atomic_write, docs_specifications_payments_outbox_delivery_initial_relay_algorithm, docs_architecture_messaging_and_queues_payments_projection_queue, docs_specifications_payments_projection_and_query_consumer_transaction, docs_specifications_payments_projection_and_query_query_contract, docs_operations_runtime_contract_projection_profile_pairing [EXTRACTED 1.00]
- **Separation of Poison Budget from Transport and Backlog** — docs_specifications_payments_projection_and_query_failure_classification, docs_specifications_payments_projection_and_query_projection_failure_generation_fence, docs_specifications_payments_projection_and_query_operator_retry_command, docs_specifications_payments_outbox_delivery_state_transitions, docs_specifications_payments_outbox_delivery_writer_ownership_split [EXTRACTED 1.00]

## Communities (114 total, 20 thin omitted)

### Community 0 - "Projection Envelope Decoding"
Cohesion: 0.07
Nodes (15): Carbon\CarbonImmutable, Illuminate\Contracts\Queue\ShouldQueueAfterCommit, PaymentInitiatedEnvelope, self, PaymentProjectionConsumer, self, ProjectionEnvelopeRejected, ProjectPaymentEvent (+7 more)

### Community 1 - "Framework Migrations"
Cohesion: 0.06
Nodes (9): Illuminate\Database\Migrations\Migration, Illuminate\Database\Schema\Blueprint, Illuminate\Foundation\Inspiring, Illuminate\Support\Facades\Artisan, Illuminate\Support\Facades\Schedule, Illuminate\Support\Facades\Schema, SpikeSnapshot, Spatie\EventSourcing\Snapshots\EloquentSnapshot (+1 more)

### Community 2 - "Initiation Request Validation"
Cohesion: 0.07
Nodes (9): Closure, Illuminate\Database\DeadlockException, Illuminate\Foundation\Http\FormRequest, Illuminate\Validation\Rule, LogicException, run(), InitiatePaymentRequest, SpikeTransactionRetry (+1 more)

### Community 3 - "Laravel Runtime Config"
Cohesion: 0.06
Nodes (19): Illuminate\Cache\ArrayStore, Illuminate\Cache\RedisStore, Illuminate\Console\Scheduling\Schedule, Illuminate\Queue\Jobs\InspectedJob, Illuminate\Queue\RedisQueue, Illuminate\Queue\SyncQueue, Illuminate\Support\Carbon, Illuminate\Support\Collection (+11 more)

### Community 4 - "Idempotent Initiation Use Case"
Cohesion: 0.07
Nodes (15): CarbonImmutable, Illuminate\Http\Response, findWinner(), reserve(), InitiatePayment, AtomicTransaction, IdempotencyReceipts, PaymentInitiationContext (+7 more)

### Community 5 - "Idempotency Failure Types"
Cohesion: 0.06
Nodes (10): IdempotencyContention, self, IdempotencyPayloadMismatch, self, ProjectionEnvelopeMissing, OutboxEnvelopeNotDerivable, self, SpikeAdvisoryLock (+2 more)

### Community 6 - "RFC 9457 Problem Details"
Cohesion: 0.09
Nodes (18): ProblemType, ProblemDetails, Illuminate\Database\Events\TransactionBeginning, Illuminate\Database\Events\TransactionCommitted, Illuminate\Http\JsonResponse, PaymentNotFound, self, findOwned() (+10 more)

### Community 7 - "Payment Domain Events"
Cohesion: 0.08
Nodes (7): DateTimeImmutable, InvalidArgumentException, InvalidPaymentInitiation, self, self, RelayConfiguration, TransportBackoff

### Community 8 - "Projection Failure Classification"
Cohesion: 0.07
Nodes (10): ProjectionFailureClassification, IntegrationDatabase, operatorCapturedLogs(), operatorLogsOfCategory(), ArrayObject, ArrayObject, recorder(), recorderCapturedLogs() (+2 more)

### Community 9 - "Event Sourcing Configuration"
Cohesion: 0.12
Nodes (16): DomainException, Illuminate\Database\Events\QueryExecuted, PaymentInitiated, PaymentAlreadyInitiated, self, PaymentAggregate, static, PaymentOutboxWriter (+8 more)

### Community 10 - "Atomic Persistence Spikes"
Cohesion: 0.10
Nodes (10): Closure, spikeContention(), self, SpikeCommandContext, SpikeInitiationHandler, SpikeInjectedFailure, SpikeProbeLog, SpikeStoredEvent (+2 more)

### Community 11 - "Payments Schema Assertions"
Cohesion: 0.09
Nodes (6): Illuminate\Database\UniqueConstraintViolationException, SqlStates, StringCodePdoException, classifiedFailure(), Throwable, PDOException

### Community 12 - "Module Layout ADRs"
Cohesion: 0.10
Nodes (21): ADR 0001 — Custom DDD module layout, Cross-module access only through Application, Four-layer module convention (Domain/Application/Infrastructure/Http), Rejected: third-party DDD scaffolding package, ADR 0002 — Payments Domain event-sourcing allowlist, Exact class-name matching, never namespace prefixes, LEDGERFLOW_DOMAIN_ALLOWLIST constant, Projector named and excluded from Domain (+13 more)

### Community 13 - "Projection Contract Script"
Cohesion: 0.22
Nodes (19): artisan_disposable(), assert_query(), await(), await_query(), check_deadline(), cleanup(), CLEANUP_BUDGET_SECONDS, COMPOSE_PROJECT_NAME (+11 more)

### Community 14 - "Architecture Index and Gates"
Cohesion: 0.12
Nodes (20): Ledgerflow architecture index, Payments vertical slice, Ambiguous Commit Outcome, HS-1 — Deliberately Omitted Projection Gate, Lightweight ADR Format, Architecture Decision Records Index, Rebuild Path, T11 — Connection-Affine Gated Rebuild (+12 more)

### Community 15 - "Documentation Ownership Map"
Cohesion: 0.24
Nodes (20): ADR 0004 — Lock-Through-Push Outbox Relay, Messaging and Queues, Payments Flow, Runtime Contract, Documentation Ownership Rules, Ledgerflow Documentation Index, Sequential Delivery Order, External Trackers (Linear and Asana) (+12 more)

### Community 16 - "Lock-Through-Push Relay Decision"
Cohesion: 0.12
Nodes (20): Lock-Through-Push Publication, OutboxRelay::relayOne(), RelayPaymentOutboxCommand, Safe Duplicate Over Lost Message, SKIP LOCKED Relay Concurrency, Event and Delivery Path, Payments Minimal Data Model, T10 — Verification and Targeted Repair (+12 more)

### Community 17 - "Transaction Retry Adapter"
Cohesion: 0.15
Nodes (6): Illuminate\Contracts\Database\LostConnectionDetector, Illuminate\Database\ConnectionInterface, Illuminate\Database\QueryException, RetryingAtomicTransaction, ContentionProbe, ThrowingProjector

### Community 18 - "Sanctum Merchant Identity"
Cohesion: 0.15
Nodes (8): Illuminate\Contracts\Auth\Factory, Laravel\Sanctum\PersonalAccessToken, NoAuthenticatedMerchantUser, self, AuthenticatedMerchantContext, Modules\Merchant\Application\Identity\MerchantContext, __construct(), CountingMerchantContext

### Community 19 - "Merchant Eloquent Model"
Cohesion: 0.17
Nodes (9): Illuminate\Database\Eloquent\Factories\Factory, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Database\Eloquent\Model, Illuminate\Database\Eloquent\Relations\HasMany, Illuminate\Support\Facades\Hash, Merchant, MerchantFactory, static (+1 more)

### Community 20 - "Problem Mapping and Bootstrap"
Cohesion: 0.18
Nodes (11): ProblemDetailsMapper, Illuminate\Auth\AuthenticationException, Illuminate\Foundation\Application, Illuminate\Foundation\Configuration\Exceptions, Illuminate\Foundation\Configuration\Middleware, Illuminate\Http\Request, Illuminate\Validation\ValidationException, Modules\Payments\Application\Initiation\IdempotencyContention (+3 more)

### Community 21 - "Frontend Build Tooling"
Cohesion: 0.11
Nodes (17): concurrently, laravel-vite-plugin, devDependencies, concurrently, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite (+9 more)

### Community 22 - "Outbox Relay Command"
Cohesion: 0.22
Nodes (7): Illuminate\Contracts\Queue\Factory, RelayPaymentOutboxCommand, OutboxRelay, RelayConfiguration, Psr\Log\LoggerInterface, RedisException, RelayOutcome

### Community 23 - "Relay Dispatch Spikes"
Cohesion: 0.14
Nodes (6): Illuminate\Database\Events\TransactionCommitting, Illuminate\Queue\Events\JobQueued, Illuminate\Support\Facades\DB, Illuminate\Support\Facades\Event, Modules\Payments\Application\Initiation\InitiatePayment, SpikeRelayJob

### Community 25 - "Payments Module Instructions"
Cohesion: 0.16
Nodes (13): ADR 0001 Custom DDD Module Layout, Custom DDD Module Layout (Domain/Application/Infrastructure/Http), One-Connection Transaction Ownership, Payments Bounded Context, Sensitive Payment Data Never Logged, Boundaries, Evidence, Invariants (+5 more)

### Community 26 - "Compose Runtime Topology"
Cohesion: 0.21
Nodes (14): make projection-contract Disposable Compose Proof, make projection-start / projection-stop Pair Activation, Single-Owner Messaging and Queue Ownership, Scheduler-Driven Outbox Relay, payments-projection Redis Queue, app (PHP-FPM) Service, horizon Service (projection profile), postgres Service (+6 more)

### Community 27 - "Docs Instruction Adapters"
Cohesion: 0.17
Nodes (12): Ledgerflow Fintech System, docs/roadmap.md Delivery Authority, Documentation Instructions, Documentation Ownership Map, Ownership, Rules, Update the Smallest Canonical Document, docs CLAUDE.md Adapter (+4 more)

### Community 28 - "Payments Capability Summary"
Cohesion: 0.18
Nodes (13): Merchant Bounded Context, payments:outbox:retry Reactivation Command, Horizon-Supervised Projection Consumer, Monotonic projection_failure_generation Fence, Payments Compatibility Contracts (aliases, versions, payloads, fingerprints), Separate Transport and Projection Failure Counters, GET /api/payments/{paymentId} Owned Payment Query, Idempotent Retry Replay of Stored Response Bytes (+5 more)

### Community 29 - "Payment Query HTTP Tests"
Cohesion: 0.22
Nodes (6): Illuminate\Contracts\Debug\ExceptionHandler, Illuminate\Support\Facades\Auth, Illuminate\Testing\TestResponse, Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant, showPayment(), Initiation

### Community 30 - "Module Boundary Enforcement"
Cohesion: 0.24
Nodes (9): Illuminate\Database\ConcurrencyErrorDetector, Illuminate\Database\DetectsConcurrencyErrors, Spatie\EventSourcing\Attributes\EventVersion, ledgerflowAfterCommitOptIns(), ledgerflowHelperCalls(), ledgerflowNextSymbol(), ledgerflowPreviousToken(), ledgerflowReferences() (+1 more)

### Community 31 - "Outbox Relay Tests"
Cohesion: 0.18
Nodes (8): Illuminate\Database\Events\TransactionRolledBack, Illuminate\Support\Facades\Log, Modules\Payments\Infrastructure\Relay\RelayOutcome, ArrayObject, relay(), relayCapturedLogs(), relayLogField(), relayWithRejectingTransport()

### Community 33 - "Auth and User Model"
Cohesion: 0.21
Nodes (7): Illuminate\Database\Eloquent\Attributes\Fillable, Illuminate\Database\Eloquent\Attributes\Hidden, Illuminate\Database\Eloquent\Relations\BelongsTo, Illuminate\Foundation\Auth\User, Illuminate\Notifications\Notifiable, Laravel\Sanctum\HasApiTokens, User

### Community 34 - "T9 Query Roadmap Entry"
Cohesion: 0.18
Nodes (12): Eight Production Types Budget, Query a Payment Endpoint, Delivery Status Summary, M4 — Relay, Projection, and Query, T9 — Merchant-Scoped Payment Query, No Dependency Without a Consumer, Merchant-Scoped Idempotency Receipt, POST /api/payments Contract (+4 more)

### Community 35 - "Service Provider Wiring"
Cohesion: 0.21
Nodes (8): Factory, Illuminate\Contracts\Foundation\Application, Illuminate\Database\DatabaseManager, Illuminate\Database\Eloquent\Relations\Relation, Modules\Payments\Application\Initiation\AtomicTransaction, Modules\Payments\Infrastructure\Persistence\DatabaseIdempotencyReceipts, Modules\Payments\Infrastructure\Projectors\PaymentOutboxWriter, Override

### Community 36 - "Outbox Status Command"
Cohesion: 0.27
Nodes (6): Illuminate\Contracts\Cache\Factory, PaymentOutboxStatusCommand, RelayConfiguration, RelayHeartbeat, Modules\Payments\Infrastructure\Relay\RelayConfiguration, stdClass

### Community 37 - "Horizon Supervision Tests"
Cohesion: 0.17
Nodes (5): Illuminate\Support\Facades\Route, Laravel\Horizon\ProvisioningPlan, Laravel\Horizon\SupervisorOptions, Modules\Payments\Http\Controllers\InitiatePaymentController, Symfony\Component\HttpKernel\Exception\HttpException

### Community 38 - "Projection Consumer Internals"
Cohesion: 0.23
Nodes (4): Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction, self, RejectingQueue, Throwable

### Community 39 - "App Service Providers"
Cohesion: 0.25
Nodes (4): AppServiceProvider, HorizonServiceProvider, Illuminate\Support\ServiceProvider, Laravel\Horizon\Horizon

### Community 40 - "Root Composer Manifest"
Cohesion: 0.18
Nodes (10): autoload-dev, psr-4, description, license, minimum-stability, name, prefer-stable, Tests\\ (+2 more)

### Community 41 - "Dev Tooling Dependencies"
Cohesion: 0.18
Nodes (11): require-dev, driftingly/rector-laravel, fakerphp/faker, larastan/larastan, laravel/pint, mockery/mockery, nunomaduro/collision, pestphp/pest (+3 more)

### Community 42 - "Test Evidence Discipline"
Cohesion: 0.22
Nodes (10): Record Evidence Only After Source Inspection and Validation, Payments Evidence Discipline (real infra only when needed), Evidence quality, Isolation, ledgerflow_test Mutation Boundary, Never Flush Shared Redis; Delete Only Owned Keys, Avoid Sleeps When Synchronization Is Possible, Non-Vacuous Controls and Proven Failure Paths (+2 more)

### Community 43 - "Merchant Module Manifest"
Cohesion: 0.18
Nodes (10): autoload, autoload-dev, psr-4, psr-4, description, name, Modules\\Merchant\\, Modules\\Merchant\\Database\\Factories\\ (+2 more)

### Community 45 - "CI Instruction Adapters"
Cohesion: 0.20
Nodes (10): GitHub Automation Instructions, No Second Development Environment in CI, .github CLAUDE.md Adapter, ADR 0002 Payments Domain Event-Sourcing Allowlist, ADR 0004 Lock-Through-Push Outbox Relay, make agent-check Instruction Parity Guard, Nested AGENTS.md Path Scoping with CLAUDE.md Adapters, Deferred Shared Projection Gate (T11) (+2 more)

### Community 46 - "Composer Plugin Config"
Cohesion: 0.20
Nodes (10): pestphp/pest-plugin, php-http/discovery, wikimedia/composer-merge-plugin, config, allow-plugins, optimize-autoloader, platform, preferred-install (+2 more)

### Community 47 - "ADR 0004 Document"
Cohesion: 0.20
Nodes (9): 0004 — Publish the payment outbox with a lock-through-push relay, without a shared projection gate, Alternatives considered, Consequences, Context, Decision, HS-1 — the shared projection gate is deliberately omitted here, Measured evidence, Related (+1 more)

### Community 48 - "Deferred Infrastructure Gates"
Cohesion: 0.22
Nodes (10): ADR Candidate Backlog, OpenSearch Adoption Gate, Planned AWS Topology, One Image, Independently Supervised Processes, Redis/SQS Capability Map, SQS Adoption Boundary, One Job, One Transport Rule, Source-of-Truth Hierarchy (+2 more)

### Community 49 - "Relay Command Tests"
Cohesion: 0.24
Nodes (6): bindRelayCommand(), passContexts(), passLogs(), ArrayObject, Closure, steppingClock()

### Community 50 - "Spike Payment Aggregate"
Cohesion: 0.24
Nodes (4): SpikePaymentAggregate, SpikePaymentInitiated, Spatie\EventSourcing\AggregateRoots\AggregateRoot, Spatie\EventSourcing\StoredEvents\ShouldBeStored

### Community 51 - "Write Path Config Tests"
Cohesion: 0.22
Nodes (5): Illuminate\Log\Events\MessageLogged, Illuminate\Support\Str, Closure, run(), Pdo\Mysql

### Community 52 - "Process Topology Contract"
Cohesion: 0.22
Nodes (9): payments-projection Logical Queue, Application Process Topology, Repository Deploy Ownership, Bounded projection-contract Deadline, Paired Scheduler+Horizon Activation, Container Contract, Disposable-Project Image Build Limitation, Queue Transport Policy (+1 more)

### Community 55 - "Merchant Identity Tests"
Cohesion: 0.28
Nodes (4): Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase, ExampleTest, TestCase

### Community 56 - "Payments Module Manifest"
Cohesion: 0.22
Nodes (8): autoload, autoload-dev, psr-4, psr-4, description, name, Modules\\Payments\\, Modules\\Payments\\Tests\\

### Community 57 - "CI Workflow Contract"
Cohesion: 0.32
Nodes (8): Least-Privilege Job Permissions and Non-Persisted Credentials, Workflows Must Not Duplicate Tool Command Sequences, check Job (make ci), Compose Diagnostics on Failure Step, CI GitHub Actions Workflow, make check Validation Contract, make ci Full Contract, Makefile as Public Development Interface

### Community 58 - "Runtime Dependencies"
Cohesion: 0.25
Nodes (8): require, laravel/framework, laravel/horizon, laravel/sanctum, laravel/tinker, nwidart/laravel-modules, php, spatie/laravel-event-sourcing

### Community 59 - "Composer Lifecycle Scripts"
Cohesion: 0.25
Nodes (8): scripts, post-autoload-dump, post-update-cmd, pre-package-uninstall, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump, Illuminate\\Foundation\\ComposerScripts::prePackageUninstall, @php artisan package:discover --ansi, @php artisan vendor:publish --tag=laravel-assets --ansi --force

### Community 60 - "Cross-Repository Ownership"
Cohesion: 0.25
Nodes (8): Deployment Boundaries, GitHub Actions OIDC Role Assumption, Immutable Artifact Delivery Model, Three-Repository Ownership Split, Interfaces and Realtime, User Interface Surface Ownership, Ledgerflow System Overview, Payments Specifications Index

### Community 61 - "Outbox State and Evidence"
Cohesion: 0.32
Nodes (8): Queue Failure Ownership, Required Operational Evidence, T8 — Horizon-Supervised Projection Consumer, Outbox State Transitions, Outbox Writer Ownership Split, Projection Failure Classification, payments:outbox:retry, projection_failure_generation Fence

### Community 62 - "Module Provider Registration"
Cohesion: 0.25
Nodes (3): MerchantServiceProvider, PaymentsServiceProvider, Nwidart\Modules\Facades\Module

### Community 63 - "Spike Outbox Writer"
Cohesion: 0.32
Nodes (4): IdentityRecorder, SpikeOutboxWriter, Spatie\EventSourcing\EventHandlers\Projectors\Projector, SplObjectStorage

### Community 64 - "Project Keywords"
Cohesion: 0.29
Nodes (7): keywords, cqrs, ddd, event-sourcing, fintech, laravel, payments

### Community 65 - "Database Seeders"
Cohesion: 0.48
Nodes (4): DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Seeder, MerchantDatabaseSeeder

### Community 66 - "Payments Write Path Task"
Cohesion: 0.29
Nodes (7): Payments Write Path, Payments Tracer Bullet, T6 — Atomic Idempotent Payment Initiation, Atomic Idempotent Write, T3 Blocking Compatibility Evidence (Q1–Q5), SQLSTATE Retry Allowlist, Transport Exception Classification

### Community 67 - "Module Merge Plugin"
Cohesion: 0.33
Nodes (6): extra, laravel, merge-plugin, dont-discover, include, Modules/*/composer.json

### Community 68 - "Rector Configuration"
Cohesion: 0.33
Nodes (5): Rector\Config\RectorConfig, Rector\Php55\Rector\String_\StringClassNameToClassConstantRector, RectorLaravel\Rector\ArrayDimFetch\EnvVariableToEnvHelperRector, RectorLaravel\Rector\ArrayDimFetch\ServerVariableToRequestFacadeRector, RectorLaravel\Set\LaravelSetList

### Community 69 - "Make Guard Assertions"
Cohesion: 0.60
Nodes (5): assert_guard_refuses(), assert_refuses(), cleanup(), restore_environment(), assert-contracts.sh script

### Community 71 - "App Autoload Map"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 72 - "Logging Channels"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 73 - "Sanctum Configuration"
Cohesion: 0.40
Nodes (4): Illuminate\Cookie\Middleware\EncryptCookies, Illuminate\Foundation\Http\Middleware\ValidateCsrfToken, Laravel\Sanctum\Http\Middleware\AuthenticateSession, Laravel\Sanctum\Sanctum

### Community 74 - "Boundary Test Limits"
Cohesion: 0.50
Nodes (4): ModuleBoundaryTest Architecture Enforcement, Named Laravel global-helper denylist, Token-scanner enforcement limits, Native single-segment PHP types outside the exception

### Community 75 - "Auth Boundary Decisions"
Cohesion: 0.50
Nodes (4): Frontend Deployment Gate, Deferred Sanctum/SPA Session Decisions, MerchantContext Tenant Resolution, Sanctum Bearer-Token Authentication

### Community 77 - "Instruction Parity Script"
Cohesion: 1.00
Nodes (3): assert_adapter(), fail(), verify-agent-instructions.sh script

### Community 79 - "Relay Heartbeat States"
Cohesion: 0.67
Nodes (3): PaymentOutboxStatusCommand, Five-State Relay Heartbeat, schedule:work First-Tick Latency

### Community 80 - "ADR Context Links"
Cohesion: 0.67
Nodes (3): ADR 0001 Custom DDD Module Layout, ADR 0002 Payments Domain Event-Sourcing Allowlist, Payments and Merchant Bounded Contexts

### Community 81 - "Validation Standards"
Cohesion: 0.67
Nodes (3): Public Makefile Interface, PHPStan Level 10 Without Baseline, Resilience Test Environment Rules

## Knowledge Gaps
- **131 isolated node(s):** `Controller`, `private`, `$schema`, `build`, `dev` (+126 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **20 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `InvalidPaymentInitiation` connect `Payment Domain Events` to `Event Sourcing Configuration`?**
  _High betweenness centrality (0.026) - this node is a cross-community bridge._
- **Why does `SpikeDatabase` connect `Spike Database Harness` to `Framework Migrations`, `Atomic Persistence Spikes`, `Transaction Retry Adapter`, `Spike Payment Aggregate`, `Relay Dispatch Spikes`?**
  _High betweenness centrality (0.025) - this node is a cross-community bridge._
- **Why does `Merchant` connect `Merchant Eloquent Model` to `Database Seeders`, `Auth and User Model`, `Event Sourcing Configuration`, `Sanctum Merchant Identity`, `Write Path Config Tests`, `Merchant Identity Tests`, `Payment Query HTTP Tests`?**
  _High betweenness centrality (0.023) - this node is a cross-community bridge._
- **What connects `Controller`, `private`, `$schema` to the rest of the system?**
  _131 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Projection Envelope Decoding` be split into smaller, more focused modules?**
  _Cohesion score 0.06666666666666667 - nodes in this community are weakly interconnected._
- **Should `Framework Migrations` be split into smaller, more focused modules?**
  _Cohesion score 0.061224489795918366 - nodes in this community are weakly interconnected._
- **Should `Initiation Request Validation` be split into smaller, more focused modules?**
  _Cohesion score 0.06717687074829932 - nodes in this community are weakly interconnected._