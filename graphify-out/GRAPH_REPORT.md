# Graph Report - ledgerflow-api  (2026-09-03)

## Corpus Check
- 241 files · ~189,655 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1408 nodes · 2545 edges · 124 communities (101 shown, 23 thin omitted)
- Extraction: 97% EXTRACTED · 3% INFERRED · 0% AMBIGUOUS · INFERRED: 75 edges (avg confidence: 0.86)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `001b8afe`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- PaymentInitiatedEnvelope
- Illuminate\Support\Facades\Schema
- RecordingConnection
- RelayTransport
- PaymentInitiationContext
- RuntimeException
- PaymentQueryTest.php
- InvalidPaymentInitiation
- IntegrationDatabase
- PaymentAggregate
- SpikeProbeLog
- PaymentProjectionConsumerTest.php
- ADR 0003 — PostgreSQL idempotency arbitration
- Projection Contract Script
- Payments Projection Gate
- ADR 0004 — Lock-Through-Push Outbox Relay
- Initial Relay Algorithm
- Illuminate\Database\ConnectionInterface
- MerchantContextTest.php
- Merchant
- ProblemDetailsMapper.php
- devDependencies
- PaymentOutboxRepairTest.php
- Illuminate\Support\Facades\DB
- Spike Database Harness
- Payments Module Instructions
- Docker Compose Local Stack
- Documentation Instructions
- GET /api/payments/{paymentId} Owned Payment Query
- QueryPaymentTest.php
- ModuleBoundaryTest.php
- OutboxRelayTest.php
- ProjectionConfiguration
- User
- GET /api/payments/{paymentId} Contract
- Throwable
- RelayConfiguration
- HorizonSupervisionTest.php
- SpikeDatabase.php
- Illuminate\Support\ServiceProvider
- composer.json
- require-dev
- Test Instructions
- Merchant/composer.json
- SpikeFailureSwitch
- Canonical Repository Instructions (root AGENTS.md)
- config
- 0004 — Publish the payment outbox with a lock-through-push relay, without a shared projection gate
- One Job, One Transport Rule
- PaymentsServiceProvider.php
- Spatie\EventSourcing\StoredEvents\ShouldBeStored
- Illuminate\Support\Str
- Paired Scheduler+Horizon Activation
- PaymentOutboxRetryCommand
- Illuminate\Database\Connection
- MerchantIdentityTest.php
- Payments/composer.json
- CI GitHub Actions Workflow
- require
- scripts
- Ledgerflow System Overview
- T8 — Horizon-Supervised Projection Consumer
- MerchantServiceProvider
- PaymentInitiated
- keywords
- MerchantDatabaseSeeder.php
- Atomic Idempotent Write
- extra
- rector.php
- assert-contracts.sh
- psr-4
- logging.php
- DatabaseProjectionVerifier
- Token-scanner enforcement limits
- Deferred Sanctum/SPA Session Decisions
- Example Unit Test
- verify-agent-instructions.sh
- modules.php
- Five-State Relay Heartbeat
- Payments and Merchant Bounded Contexts
- Public Makefile Interface
- Controller.php
- entrypoint.sh
- 10-create-test-database.sh
- Filament Admin Boundary
- Cross-Repository Contract Ownership
- $_SERVER Env-Precedence Defect
- .github/AGENTS.md
- Closure
- CLAUDE.md → AGENTS.md pointer
- Empty Domain directories are deliberate
- Outbox payload read back, never re-serialised
- First Vertical Slice Scenario
- Permissive robots.txt Crawl Policy
- PaymentsSchemaTest.php
- PaymentInitiationPersistenceTest.php
- Modules\Merchant\Application\Identity\MerchantContext
- TransportBackoff
- OutboxEnvelopeNotDerivable
- T10 — Verification and Targeted Repair

## God Nodes (most connected - your core abstractions)
1. `Merchant` - 40 edges
2. `PaymentInitiated` - 37 edges
3. `DatabaseProjectionVerifier` - 32 edges
4. `PaymentInitiatedEnvelope` - 30 edges
5. `RetryingAtomicTransaction` - 28 edges
6. `ProjectionEnvelopeRejected` - 27 edges
7. `PaymentProjectionConsumer` - 26 edges
8. `RelayConfiguration` - 26 edges
9. `RecordingConnection` - 25 edges
10. `SpikeDatabase` - 23 edges

## Surprising Connections (you probably didn't know these)
- `Unknown and Foreign Payment IDs Answer Identically` --semantically_similar_to--> `Sensitive Payment Data Never Logged`  [INFERRED] [semantically similar]
  README.md → AGENTS.md
- `No Second Development Environment in CI` --conceptually_related_to--> `Docker Compose Local Stack`  [INFERRED]
  .github/AGENTS.md → compose.yaml
- `Record Evidence Only After Source Inspection and Validation` --semantically_similar_to--> `Payments Evidence Discipline (real infra only when needed)`  [INFERRED] [semantically similar]
  docs/AGENTS.md → Modules/Payments/AGENTS.md
- `Payments Evidence Discipline (real infra only when needed)` --semantically_similar_to--> `Avoid Sleeps When Synchronization Is Possible`  [INFERRED] [semantically similar]
  Modules/Payments/AGENTS.md → tests/AGENTS.md
- `Payments Evidence Discipline (real infra only when needed)` --semantically_similar_to--> `Non-Vacuous Controls and Proven Failure Paths`  [INFERRED] [semantically similar]
  Modules/Payments/AGENTS.md → tests/AGENTS.md

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Asynchronous Payments Projection Delivery Flow** — readme_post_api_payments, agents_outbox_relay, agents_payments_projection_queue, agents_projection_consumer, readme_payment_details_read_model, readme_get_api_payments_paymentid [EXTRACTED 1.00]
- **Make-Driven Validation Contract Across Local and CI** — agents_make_public_interface, agents_make_check, agents_make_ci, _github_workflows_ci_check_job, _github_agents_no_duplicate_command_sequences, compose_stack [EXTRACTED 1.00]
- **POST to GET Payments Delivery Pipeline** — docs_specifications_payments_initiation_and_idempotency_atomic_write, docs_specifications_payments_outbox_delivery_initial_relay_algorithm, docs_architecture_messaging_and_queues_payments_projection_queue, docs_specifications_payments_projection_and_query_consumer_transaction, docs_specifications_payments_projection_and_query_query_contract, docs_operations_runtime_contract_projection_profile_pairing [EXTRACTED 1.00]
- **Separation of Poison Budget from Transport and Backlog** — docs_specifications_payments_projection_and_query_failure_classification, docs_specifications_payments_projection_and_query_projection_failure_generation_fence, docs_specifications_payments_projection_and_query_operator_retry_command, docs_specifications_payments_outbox_delivery_state_transitions, docs_specifications_payments_outbox_delivery_writer_ownership_split [EXTRACTED 1.00]
- **Deferred Projection Gate and Its Reciprocal Obligations** — docs_adr_0004_lock_through_push_outbox_relay_hs_1, docs_specifications_payments_verification_and_rebuild_projection_gate, docs_specifications_payments_outbox_delivery_initial_relay_algorithm, docs_specifications_payments_projection_and_query_consumer_transaction, docs_specifications_payments_projection_and_query_query_contract, docs_roadmap_t11 [EXTRACTED 1.00]
- **Path-Scoped Agent Instruction System with CLAUDE.md Parity** — agents_repository_instructions, _github_agents_github_automation_instructions, modules_payments_agents_payments_module_instructions, docs_agents_documentation_instructions, tests_agents_test_instructions, agents_make_agent_check [EXTRACTED 1.00]

## Communities (124 total, 23 thin omitted)

### Community 0 - "PaymentInitiatedEnvelope"
Cohesion: 0.05
Nodes (25): Carbon\CarbonImmutable, Illuminate\Contracts\Queue\ShouldQueueAfterCommit, PaymentInitiatedEnvelope, CarbonImmutable, self, PaymentProjectionConsumer, self, ProjectionEnvelopeRejected (+17 more)

### Community 1 - "Illuminate\Support\Facades\Schema"
Cohesion: 0.08
Nodes (3): Illuminate\Database\Migrations\Migration, Illuminate\Database\Schema\Blueprint, Illuminate\Support\Facades\Schema

### Community 2 - "RecordingConnection"
Cohesion: 0.14
Nodes (3): LogicException, IncompleteVerificationScope, RecordingConnection

### Community 3 - "RelayTransport"
Cohesion: 0.06
Nodes (19): Illuminate\Cache\ArrayStore, Illuminate\Cache\RedisStore, Illuminate\Console\Scheduling\Schedule, Illuminate\Queue\Jobs\InspectedJob, Illuminate\Queue\RedisQueue, Illuminate\Queue\SyncQueue, Illuminate\Support\Carbon, Illuminate\Support\Collection (+11 more)

### Community 4 - "PaymentInitiationContext"
Cohesion: 0.06
Nodes (17): Illuminate\Foundation\Http\FormRequest, Illuminate\Http\Response, Illuminate\Validation\Rule, findWinner(), reserve(), InitiatePayment, AtomicTransaction, IdempotencyReceipts (+9 more)

### Community 5 - "RuntimeException"
Cohesion: 0.07
Nodes (10): IdempotencyPayloadMismatch, self, ProjectionEnvelopeMissing, VerificationSnapshotUnavailable, SpikeAdvisoryLock, FailingLogger, StringCodeException, Psr\Log\AbstractLogger (+2 more)

### Community 6 - "PaymentQueryTest.php"
Cohesion: 0.08
Nodes (18): ProblemType, ProblemDetails, Illuminate\Database\Events\TransactionBeginning, Illuminate\Database\Events\TransactionCommitted, Illuminate\Http\JsonResponse, PaymentNotFound, self, findOwned() (+10 more)

### Community 7 - "InvalidPaymentInitiation"
Cohesion: 0.16
Nodes (4): DateTimeImmutable, InvalidArgumentException, InvalidPaymentInitiation, self

### Community 8 - "IntegrationDatabase"
Cohesion: 0.11
Nodes (6): IntegrationDatabase, operatorCapturedLogs(), operatorEnvelope(), operatorLogsOfCategory(), ArrayObject, verifierCounts()

### Community 9 - "PaymentAggregate"
Cohesion: 0.17
Nodes (9): DomainException, PaymentAlreadyInitiated, self, PaymentAggregate, static, persistOnePayment(), SpikePaymentAggregate, initiateOn() (+1 more)

### Community 10 - "SpikeProbeLog"
Cohesion: 0.13
Nodes (7): self, SpikeCommandContext, SpikeOutboxWriter, SpikeProbeLog, SpikeStoredEvent, Spatie\EventSourcing\Facades\Projectionist, Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent

### Community 11 - "PaymentProjectionConsumerTest.php"
Cohesion: 0.10
Nodes (11): Illuminate\Database\UniqueConstraintViolationException, Modules\Payments\Infrastructure\Projection\ProjectionOutcome, ArrayObject, projectionCapturedLogs(), projectionEnvelope(), projectionLogContexts(), SqlStates, StringCodePdoException (+3 more)

### Community 12 - "ADR 0003 — PostgreSQL idempotency arbitration"
Cohesion: 0.10
Nodes (21): ADR 0001 — Custom DDD module layout, Cross-module access only through Application, Four-layer module convention (Domain/Application/Infrastructure/Http), Rejected: third-party DDD scaffolding package, ADR 0002 — Payments Domain event-sourcing allowlist, Exact class-name matching, never namespace prefixes, LEDGERFLOW_DOMAIN_ALLOWLIST constant, Projector named and excluded from Domain (+13 more)

### Community 13 - "Projection Contract Script"
Cohesion: 0.22
Nodes (19): artisan_disposable(), assert_query(), await(), await_query(), check_deadline(), cleanup(), CLEANUP_BUDGET_SECONDS, COMPOSE_PROJECT_NAME (+11 more)

### Community 14 - "Payments Projection Gate"
Cohesion: 0.15
Nodes (16): Ledgerflow architecture index, Payments vertical slice, HS-1 — Deliberately Omitted Projection Gate, Lightweight ADR Format, Architecture Decision Records Index, Rebuild Path, T11 — Connection-Affine Gated Rebuild, T7 — Scheduler-Driven Outbox Relay (+8 more)

### Community 15 - "ADR 0004 — Lock-Through-Push Outbox Relay"
Cohesion: 0.24
Nodes (20): ADR 0004 — Lock-Through-Push Outbox Relay, Messaging and Queues, Payments Flow, Runtime Contract, Documentation Ownership Rules, Ledgerflow Documentation Index, Sequential Delivery Order, External Trackers (Linear and Asana) (+12 more)

### Community 16 - "Initial Relay Algorithm"
Cohesion: 0.14
Nodes (17): Ambiguous Commit Outcome, OutboxRelay::relayOne(), RelayPaymentOutboxCommand, SKIP LOCKED Relay Concurrency, Event and Delivery Path, Payments Minimal Data Model, T4 Blocking Compatibility Evidence (Q6–Q10), Evolution Gate — Claim/Lease Publishing (+9 more)

### Community 17 - "Illuminate\Database\ConnectionInterface"
Cohesion: 0.33
Nodes (4): Illuminate\Database\ConnectionInterface, ContentionProbe, ThrowingProjector, Spatie\EventSourcing\EventHandlers\Projectors\Projector

### Community 18 - "MerchantContextTest.php"
Cohesion: 0.15
Nodes (9): Illuminate\Contracts\Auth\Factory, Illuminate\Cookie\Middleware\EncryptCookies, Illuminate\Foundation\Http\Middleware\ValidateCsrfToken, Laravel\Sanctum\Http\Middleware\AuthenticateSession, Laravel\Sanctum\PersonalAccessToken, Laravel\Sanctum\Sanctum, NoAuthenticatedMerchantUser, self (+1 more)

### Community 19 - "Merchant"
Cohesion: 0.17
Nodes (9): Illuminate\Database\Eloquent\Factories\Factory, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Database\Eloquent\Model, Illuminate\Database\Eloquent\Relations\HasMany, Illuminate\Support\Facades\Hash, Merchant, MerchantFactory, static (+1 more)

### Community 20 - "ProblemDetailsMapper.php"
Cohesion: 0.21
Nodes (9): ProblemType, ProblemDetailsMapper, Illuminate\Auth\AuthenticationException, Illuminate\Foundation\Application, Illuminate\Foundation\Configuration\Exceptions, Illuminate\Foundation\Configuration\Middleware, Illuminate\Http\Request, Illuminate\Validation\ValidationException (+1 more)

### Community 21 - "devDependencies"
Cohesion: 0.11
Nodes (17): concurrently, laravel-vite-plugin, devDependencies, concurrently, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite (+9 more)

### Community 22 - "PaymentOutboxRepairTest.php"
Cohesion: 0.06
Nodes (26): Command, Illuminate\Database\DatabaseManager, Illuminate\Database\Events\TransactionCommitting, repair(), RepairMutationState, RepairReason, self, RepairOutcome (+18 more)

### Community 23 - "Illuminate\Support\Facades\DB"
Cohesion: 0.13
Nodes (7): Illuminate\Contracts\Database\LostConnectionDetector, Illuminate\Database\QueryException, Illuminate\Queue\Events\JobQueued, Illuminate\Support\Facades\DB, Illuminate\Support\Facades\Event, SpikeInjectedFailure, SpikeRelayJob

### Community 25 - "Payments Module Instructions"
Cohesion: 0.12
Nodes (19): ADR 0001 Custom DDD Module Layout, Merchant Bounded Context, Custom DDD Module Layout (Domain/Application/Infrastructure/Http), One-Connection Transaction Ownership, Payments Bounded Context, Sensitive Payment Data Never Logged, Boundaries, Payments Compatibility Contracts (aliases, versions, payloads, fingerprints) (+11 more)

### Community 26 - "Docker Compose Local Stack"
Cohesion: 0.27
Nodes (11): make projection-contract Disposable Compose Proof, make projection-start / projection-stop Pair Activation, app (PHP-FPM) Service, horizon Service (projection profile), postgres Service, projection Compose Profile, redis Service, scheduler Service (projection profile) (+3 more)

### Community 27 - "Documentation Instructions"
Cohesion: 0.17
Nodes (12): Ledgerflow Fintech System, docs/roadmap.md Delivery Authority, Documentation Instructions, Documentation Ownership Map, Ownership, Rules, Update the Smallest Canonical Document, docs CLAUDE.md Adapter (+4 more)

### Community 28 - "GET /api/payments/{paymentId} Owned Payment Query"
Cohesion: 0.21
Nodes (12): ADR 0004 Lock-Through-Push Outbox Relay, Single-Owner Messaging and Queue Ownership, Scheduler-Driven Outbox Relay, payments:outbox:retry Reactivation Command, payments-projection Redis Queue, Horizon-Supervised Projection Consumer, Monotonic projection_failure_generation Fence, Deferred Shared Projection Gate (T11) (+4 more)

### Community 29 - "QueryPaymentTest.php"
Cohesion: 0.24
Nodes (5): Illuminate\Contracts\Debug\ExceptionHandler, Illuminate\Support\Facades\Auth, Illuminate\Testing\TestResponse, showPayment(), Initiation

### Community 30 - "ModuleBoundaryTest.php"
Cohesion: 0.24
Nodes (9): Illuminate\Database\ConcurrencyErrorDetector, Illuminate\Database\DetectsConcurrencyErrors, Spatie\EventSourcing\Attributes\EventVersion, ledgerflowAfterCommitOptIns(), ledgerflowHelperCalls(), ledgerflowNextSymbol(), ledgerflowPreviousToken(), ledgerflowReferences() (+1 more)

### Community 31 - "OutboxRelayTest.php"
Cohesion: 0.11
Nodes (12): Illuminate\Contracts\Queue\Factory, Illuminate\Database\Events\TransactionRolledBack, Illuminate\Support\Facades\Log, OutboxRelay, ArrayObject, relayCapturedLogs(), relayLogField(), relayWithRejectingTransport() (+4 more)

### Community 33 - "User"
Cohesion: 0.24
Nodes (7): Illuminate\Database\Eloquent\Attributes\Fillable, Illuminate\Database\Eloquent\Attributes\Hidden, Illuminate\Database\Eloquent\Relations\BelongsTo, Illuminate\Foundation\Auth\User, Illuminate\Notifications\Notifiable, Laravel\Sanctum\HasApiTokens, User

### Community 34 - "GET /api/payments/{paymentId} Contract"
Cohesion: 0.18
Nodes (12): Eight Production Types Budget, Query a Payment Endpoint, Delivery Status Summary, M4 — Relay, Projection, and Query, T9 — Merchant-Scoped Payment Query, No Dependency Without a Consumer, Merchant-Scoped Idempotency Receipt, POST /api/payments Contract (+4 more)

### Community 35 - "Throwable"
Cohesion: 0.14
Nodes (9): Illuminate\Log\Events\MessageLogged, IdempotencyContention, self, RetryingAtomicTransaction, ProjectionFailureClassification, Modules\Payments\Application\Initiation\AtomicTransaction, Closure, run() (+1 more)

### Community 36 - "RelayConfiguration"
Cohesion: 0.13
Nodes (8): Illuminate\Console\Command, Illuminate\Contracts\Cache\Factory, PaymentOutboxStatusCommand, RelayPaymentOutboxCommand, self, RelayConfiguration, RelayHeartbeat, Modules\Payments\Infrastructure\Relay\RelayOutcome

### Community 37 - "HorizonSupervisionTest.php"
Cohesion: 0.18
Nodes (4): Illuminate\Support\Facades\Route, Laravel\Horizon\ProvisioningPlan, Laravel\Horizon\SupervisorOptions, Symfony\Component\HttpKernel\Exception\HttpException

### Community 38 - "SpikeDatabase.php"
Cohesion: 0.22
Nodes (6): Illuminate\Foundation\Inspiring, Illuminate\Support\Facades\Artisan, Illuminate\Support\Facades\Schedule, SpikeSnapshot, Spatie\EventSourcing\Snapshots\EloquentSnapshot, Spatie\EventSourcing\StoredEvents\Repositories\StoredEventRepository

### Community 39 - "Illuminate\Support\ServiceProvider"
Cohesion: 0.25
Nodes (4): AppServiceProvider, HorizonServiceProvider, Illuminate\Support\ServiceProvider, Laravel\Horizon\Horizon

### Community 40 - "composer.json"
Cohesion: 0.18
Nodes (10): autoload-dev, psr-4, description, license, minimum-stability, name, prefer-stable, Tests\\ (+2 more)

### Community 41 - "require-dev"
Cohesion: 0.18
Nodes (11): require-dev, driftingly/rector-laravel, fakerphp/faker, larastan/larastan, laravel/pint, mockery/mockery, nunomaduro/collision, pestphp/pest (+3 more)

### Community 42 - "Test Instructions"
Cohesion: 0.22
Nodes (10): Record Evidence Only After Source Inspection and Validation, Payments Evidence Discipline (real infra only when needed), Evidence quality, Isolation, ledgerflow_test Mutation Boundary, Never Flush Shared Redis; Delete Only Owned Keys, Avoid Sleeps When Synchronization Is Possible, Non-Vacuous Controls and Proven Failure Paths (+2 more)

### Community 43 - "Merchant/composer.json"
Cohesion: 0.18
Nodes (10): autoload, autoload-dev, psr-4, psr-4, description, name, Modules\\Merchant\\, Modules\\Merchant\\Database\\Factories\\ (+2 more)

### Community 45 - "Canonical Repository Instructions (root AGENTS.md)"
Cohesion: 0.25
Nodes (8): GitHub Automation Instructions, No Second Development Environment in CI, .github CLAUDE.md Adapter, ADR 0002 Payments Domain Event-Sourcing Allowlist, make agent-check Instruction Parity Guard, Nested AGENTS.md Path Scoping with CLAUDE.md Adapters, Canonical Repository Instructions (root AGENTS.md), Framework-Independent Domain with Event-Sourcing Allowlist

### Community 46 - "config"
Cohesion: 0.20
Nodes (10): pestphp/pest-plugin, php-http/discovery, wikimedia/composer-merge-plugin, config, allow-plugins, optimize-autoloader, platform, preferred-install (+2 more)

### Community 47 - "0004 — Publish the payment outbox with a lock-through-push relay, without a shared projection gate"
Cohesion: 0.20
Nodes (9): 0004 — Publish the payment outbox with a lock-through-push relay, without a shared projection gate, Alternatives considered, Consequences, Context, Decision, HS-1 — the shared projection gate is deliberately omitted here, Measured evidence, Related (+1 more)

### Community 48 - "One Job, One Transport Rule"
Cohesion: 0.22
Nodes (10): ADR Candidate Backlog, OpenSearch Adoption Gate, Planned AWS Topology, One Image, Independently Supervised Processes, Redis/SQS Capability Map, SQS Adoption Boundary, One Job, One Transport Rule, Source-of-Truth Hierarchy (+2 more)

### Community 49 - "PaymentsServiceProvider.php"
Cohesion: 0.17
Nodes (10): Factory, Illuminate\Contracts\Foundation\Application, Illuminate\Database\Eloquent\Relations\Relation, bindRelayCommand(), passContexts(), passLogs(), ArrayObject, Closure (+2 more)

### Community 52 - "Paired Scheduler+Horizon Activation"
Cohesion: 0.22
Nodes (9): payments-projection Logical Queue, Application Process Topology, Repository Deploy Ownership, Bounded projection-contract Deadline, Paired Scheduler+Horizon Activation, Container Contract, Disposable-Project Image Build Limitation, Queue Transport Policy (+1 more)

### Community 54 - "Illuminate\Database\Connection"
Cohesion: 0.23
Nodes (3): Illuminate\Database\Connection, ProjectionFailureRecorder, Psr\Log\LoggerInterface

### Community 55 - "MerchantIdentityTest.php"
Cohesion: 0.28
Nodes (4): Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase, ExampleTest, TestCase

### Community 56 - "Payments/composer.json"
Cohesion: 0.22
Nodes (8): autoload, autoload-dev, psr-4, psr-4, description, name, Modules\\Payments\\, Modules\\Payments\\Tests\\

### Community 57 - "CI GitHub Actions Workflow"
Cohesion: 0.32
Nodes (8): Least-Privilege Job Permissions and Non-Persisted Credentials, Workflows Must Not Duplicate Tool Command Sequences, check Job (make ci), Compose Diagnostics on Failure Step, CI GitHub Actions Workflow, make check Validation Contract, make ci Full Contract, Makefile as Public Development Interface

### Community 58 - "require"
Cohesion: 0.25
Nodes (8): require, laravel/framework, laravel/horizon, laravel/sanctum, laravel/tinker, nwidart/laravel-modules, php, spatie/laravel-event-sourcing

### Community 59 - "scripts"
Cohesion: 0.25
Nodes (8): scripts, post-autoload-dump, post-update-cmd, pre-package-uninstall, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump, Illuminate\\Foundation\\ComposerScripts::prePackageUninstall, @php artisan package:discover --ansi, @php artisan vendor:publish --tag=laravel-assets --ansi --force

### Community 60 - "Ledgerflow System Overview"
Cohesion: 0.25
Nodes (8): Deployment Boundaries, GitHub Actions OIDC Role Assumption, Immutable Artifact Delivery Model, Three-Repository Ownership Split, Interfaces and Realtime, User Interface Surface Ownership, Ledgerflow System Overview, Payments Specifications Index

### Community 61 - "T8 — Horizon-Supervised Projection Consumer"
Cohesion: 0.32
Nodes (8): Queue Failure Ownership, Required Operational Evidence, T8 — Horizon-Supervised Projection Consumer, Outbox State Transitions, Outbox Writer Ownership Split, Projection Failure Classification, payments:outbox:retry, projection_failure_generation Fence

### Community 62 - "MerchantServiceProvider"
Cohesion: 0.25
Nodes (3): MerchantServiceProvider, PaymentsServiceProvider, Nwidart\Modules\Facades\Module

### Community 63 - "PaymentInitiated"
Cohesion: 0.19
Nodes (6): Illuminate\Database\Events\QueryExecuted, PaymentInitiated, handWrittenPaymentInitiated(), runOutboxWriter(), IdentityRecorder, SplObjectStorage

### Community 64 - "keywords"
Cohesion: 0.29
Nodes (7): keywords, cqrs, ddd, event-sourcing, fintech, laravel, payments

### Community 65 - "MerchantDatabaseSeeder.php"
Cohesion: 0.39
Nodes (4): DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Seeder, MerchantDatabaseSeeder

### Community 66 - "Atomic Idempotent Write"
Cohesion: 0.20
Nodes (10): Lock-Through-Push Publication, Safe Duplicate Over Lost Message, Payments Write Path, Payments Tracer Bullet, T6 — Atomic Idempotent Payment Initiation, Atomic Idempotent Write, T3 Blocking Compatibility Evidence (Q1–Q5), SQLSTATE Retry Allowlist (+2 more)

### Community 67 - "extra"
Cohesion: 0.33
Nodes (6): extra, laravel, merge-plugin, dont-discover, include, Modules/*/composer.json

### Community 68 - "rector.php"
Cohesion: 0.33
Nodes (5): Rector\Config\RectorConfig, Rector\Php55\Rector\String_\StringClassNameToClassConstantRector, RectorLaravel\Rector\ArrayDimFetch\EnvVariableToEnvHelperRector, RectorLaravel\Rector\ArrayDimFetch\ServerVariableToRequestFacadeRector, RectorLaravel\Set\LaravelSetList

### Community 69 - "assert-contracts.sh"
Cohesion: 0.60
Nodes (5): assert_guard_refuses(), assert_refuses(), cleanup(), restore_environment(), assert-contracts.sh script

### Community 71 - "psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 72 - "logging.php"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 73 - "DatabaseProjectionVerifier"
Cohesion: 0.06
Nodes (17): verify(), self, VerificationReport, DatabaseProjectionVerifier, CarbonImmutable, VerificationFinding, self, VerificationConfiguration (+9 more)

### Community 74 - "Token-scanner enforcement limits"
Cohesion: 0.50
Nodes (4): ModuleBoundaryTest Architecture Enforcement, Named Laravel global-helper denylist, Token-scanner enforcement limits, Native single-segment PHP types outside the exception

### Community 75 - "Deferred Sanctum/SPA Session Decisions"
Cohesion: 0.50
Nodes (4): Frontend Deployment Gate, Deferred Sanctum/SPA Session Decisions, MerchantContext Tenant Resolution, Sanctum Bearer-Token Authentication

### Community 77 - "verify-agent-instructions.sh"
Cohesion: 1.00
Nodes (3): assert_adapter(), fail(), verify-agent-instructions.sh script

### Community 79 - "Five-State Relay Heartbeat"
Cohesion: 0.67
Nodes (3): PaymentOutboxStatusCommand, Five-State Relay Heartbeat, schedule:work First-Tick Latency

### Community 80 - "Payments and Merchant Bounded Contexts"
Cohesion: 0.67
Nodes (3): ADR 0001 Custom DDD Module Layout, ADR 0002 Payments Domain Event-Sourcing Allowlist, Payments and Merchant Bounded Contexts

### Community 81 - "Public Makefile Interface"
Cohesion: 0.67
Nodes (3): Public Makefile Interface, PHPStan Level 10 Without Baseline, Resilience Test Environment Rules

### Community 90 - "Closure"
Cohesion: 0.14
Nodes (7): Closure, Illuminate\Database\DeadlockException, run(), Closure, spikeContention(), SpikeInitiationHandler, SpikeTransactionRetry

### Community 115 - "PaymentInitiationPersistenceTest.php"
Cohesion: 0.36
Nodes (5): PaymentOutboxWriter, t5StoredEvent(), Spatie\EventSourcing\Projectionist, Spatie\EventSourcing\StoredEvents\StoredEvent, Symfony\Component\Finder\Finder

### Community 116 - "Modules\Merchant\Application\Identity\MerchantContext"
Cohesion: 0.32
Nodes (3): Modules\Merchant\Application\Identity\MerchantContext, __construct(), CountingMerchantContext

### Community 119 - "T10 — Verification and Targeted Repair"
Cohesion: 0.50
Nodes (4): T10 — Verification and Targeted Repair, payments:projections:verify, payments:projections:verify command, Scheduled projection verification and corruption alerting

## Knowledge Gaps
- **131 isolated node(s):** `name`, `description`, `Modules\\Merchant\\`, `Modules\\Merchant\\Database\\Factories\\`, `Modules\\Merchant\\Database\\Seeders\\` (+126 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **23 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Merchant` connect `Merchant` to `PaymentInitiatedEnvelope`, `MerchantDatabaseSeeder.php`, `Throwable`, `PaymentQueryTest.php`, `IntegrationDatabase`, `PaymentAggregate`, `DatabaseProjectionVerifier`, `PaymentProjectionConsumerTest.php`, `MerchantContextTest.php`, `PaymentInitiationPersistenceTest.php`, `Modules\Merchant\Application\Identity\MerchantContext`, `PaymentsSchemaTest.php`, `Illuminate\Support\Facades\DB`, `MerchantIdentityTest.php`, `PaymentOutboxRepairTest.php`, `QueryPaymentTest.php`?**
  _High betweenness centrality (0.048) - this node is a cross-community bridge._
- **Why does `PaymentInitiated` connect `PaymentInitiated` to `PaymentInitiatedEnvelope`, `Throwable`, `PaymentInitiationContext`, `InvalidPaymentInitiation`, `PaymentAggregate`, `DatabaseProjectionVerifier`, `Illuminate\Database\ConnectionInterface`, `Spatie\EventSourcing\StoredEvents\ShouldBeStored`, `PaymentInitiationPersistenceTest.php`, `Illuminate\Support\Facades\DB`, `Illuminate\Database\Connection`, `OutboxEnvelopeNotDerivable`, `PaymentOutboxRepairTest.php`, `ModuleBoundaryTest.php`?**
  _High betweenness centrality (0.029) - this node is a cross-community bridge._
- **Why does `ProjectionConfiguration` connect `ProjectionConfiguration` to `PaymentInitiatedEnvelope`, `HorizonSupervisionTest.php`, `InvalidPaymentInitiation`, `PaymentProjectionConsumerTest.php`, `PaymentsServiceProvider.php`, `Illuminate\Database\Connection`, `MerchantServiceProvider`?**
  _High betweenness centrality (0.024) - this node is a cross-community bridge._
- **What connects `name`, `description`, `Modules\\Merchant\\` to the rest of the system?**
  _131 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `PaymentInitiatedEnvelope` be split into smaller, more focused modules?**
  _Cohesion score 0.053994732221246705 - nodes in this community are weakly interconnected._
- **Should `Illuminate\Support\Facades\Schema` be split into smaller, more focused modules?**
  _Cohesion score 0.07804878048780488 - nodes in this community are weakly interconnected._
- **Should `RecordingConnection` be split into smaller, more focused modules?**
  _Cohesion score 0.14153846153846153 - nodes in this community are weakly interconnected._