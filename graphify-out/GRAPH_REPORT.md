# Graph Report - ledgerflow-api  (2026-09-07)

## Corpus Check
- 261 files · ~226,220 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1576 nodes · 2907 edges · 147 communities (123 shown, 24 thin omitted)
- Extraction: 97% EXTRACTED · 3% INFERRED · 0% AMBIGUOUS · INFERRED: 78 edges (avg confidence: 0.86)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `3c91e298`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- PaymentInitiatedEnvelope
- Illuminate\Support\Facades\Schema
- RecordingConnection
- RelayTransport
- PaymentInitiationContext
- resilience-contract.sh
- PaymentQueryTest.php
- InvalidPaymentInitiation
- IntegrationDatabase
- PaymentAggregate
- Illuminate\Support\Facades\DB
- PDOException
- ADR 0003 — PostgreSQL idempotency arbitration
- projection-contract.sh
- Payments Projection Gate
- ADR 0004 — Lock-Through-Push Outbox Relay
- Initial Relay Algorithm
- Illuminate\Database\QueryException
- Modules\Merchant\Application\Identity\MerchantContext
- Merchant
- Illuminate\Support\ServiceProvider
- devDependencies
- VerifyPaymentProjectionsCommand.php
- Spike Database Harness
- Payments Module Instructions
- Docker Compose Local Stack
- Documentation Instructions
- GET /api/payments/{paymentId} Owned Payment Query
- QueryPaymentTest.php
- ModuleBoundaryTest.php
- Throwable
- HorizonSupervisionTest.php
- User
- GET /api/payments/{paymentId} Contract
- RelayConfiguration
- RelayHeartbeat
- contract.sh
- PaymentOutboxRetryTest.php
- DatabaseProjectionRebuild
- composer.json
- require-dev
- Test Instructions
- Merchant/composer.json
- SpikeFailureSwitch
- Canonical Repository Instructions (root AGENTS.md)
- config
- Decision
- One Job, One Transport Rule
- RelayPaymentOutboxCommandTest.php
- Spatie\EventSourcing\StoredEvents\ShouldBeStored
- ProjectionFailureRecorderTest.php
- Paired Scheduler+Horizon Activation
- RuntimeException
- Psr\Log\LoggerInterface
- OutboxRelayTest.php
- Payments/composer.json
- CI GitHub Actions Workflow
- require
- scripts
- Ledgerflow System Overview
- T8 — Horizon-Supervised Projection Consumer
- ProjectionRebuildTest.php
- InitiationCommitBoundaryTest.php
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
- PaymentInitiated
- Illuminate\Support\Str
- PaymentProjectionVerifierTest.php
- OutboxEnvelopeNotDerivable
- PaymentProjectionConsumer
- DatabaseOutboxRepair
- ProjectionGate
- RepairOutcome
- MerchantIdentityTest.php
- PaymentProjectionConsumerTest.php
- OutboxRelay
- PaymentOutboxRetryCommand
- SpikeDatabase.php
- PaymentOutboxRepairTest.php
- SpikeAdvisoryLock
- VerificationEnvelope
- sanctum.php
- ProblemDetails
- ProjectionConfiguration
- AdvisoryExclusiveWaiter
- MerchantServiceProvider
- RepairIntent
- .resolve
- FailingLogger
- ProjectionGateLocks

## God Nodes (most connected - your core abstractions)
1. `Merchant` - 42 edges
2. `PaymentInitiated` - 39 edges
3. `ProjectionGate` - 39 edges
4. `RetryingAtomicTransaction` - 34 edges
5. `DatabaseProjectionVerifier` - 33 edges
6. `PaymentInitiatedEnvelope` - 32 edges
7. `PaymentProjectionConsumer` - 30 edges
8. `ProjectionEnvelopeRejected` - 28 edges
9. `RelayConfiguration` - 26 edges
10. `DatabaseProjectionRebuild` - 25 edges

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

## Communities (147 total, 24 thin omitted)

### Community 0 - "PaymentInitiatedEnvelope"
Cohesion: 0.15
Nodes (5): PaymentInitiatedEnvelope, CarbonImmutable, self, self, ProjectionEnvelopeRejected

### Community 1 - "Illuminate\Support\Facades\Schema"
Cohesion: 0.07
Nodes (3): Illuminate\Database\Migrations\Migration, Illuminate\Database\Schema\Blueprint, Illuminate\Support\Facades\Schema

### Community 2 - "RecordingConnection"
Cohesion: 0.14
Nodes (3): LogicException, IncompleteVerificationScope, RecordingConnection

### Community 3 - "RelayTransport"
Cohesion: 0.06
Nodes (18): Illuminate\Cache\ArrayStore, Illuminate\Cache\RedisStore, Illuminate\Console\Scheduling\Schedule, Illuminate\Queue\Jobs\InspectedJob, Illuminate\Queue\RedisQueue, Illuminate\Queue\SyncQueue, Illuminate\Support\Carbon, Illuminate\Support\Collection (+10 more)

### Community 4 - "PaymentInitiationContext"
Cohesion: 0.06
Nodes (15): Illuminate\Foundation\Http\FormRequest, Illuminate\Http\Response, Illuminate\Validation\Rule, findWinner(), reserve(), InitiatePayment, AtomicTransaction, IdempotencyReceipts (+7 more)

### Community 5 - "resilience-contract.sh"
Cohesion: 0.12
Nodes (23): APP_BIND, APP_PORT, assert_budget_untouched(), assert_converged_once(), await_backend_lock_wait(), await_projected(), body_contains(), CONTRACT_NAME (+15 more)

### Community 6 - "PaymentQueryTest.php"
Cohesion: 0.08
Nodes (16): Illuminate\Database\Events\TransactionBeginning, Illuminate\Database\Events\TransactionCommitted, PaymentNotFound, self, findOwned(), PendingPayment, ProjectedPayment, self (+8 more)

### Community 7 - "InvalidPaymentInitiation"
Cohesion: 0.15
Nodes (4): DateTimeImmutable, InvalidArgumentException, InvalidPaymentInitiation, self

### Community 9 - "PaymentAggregate"
Cohesion: 0.21
Nodes (7): DomainException, PaymentAlreadyInitiated, self, PaymentAggregate, static, persistOnePayment(), initiateOn()

### Community 10 - "Illuminate\Support\Facades\DB"
Cohesion: 0.11
Nodes (11): Illuminate\Support\Facades\DB, Illuminate\Support\Facades\Queue, Closure, spikeContention(), self, SpikeCommandContext, SpikeOutboxWriter, SpikeProbeLog (+3 more)

### Community 11 - "PDOException"
Cohesion: 0.19
Nodes (6): Illuminate\Database\UniqueConstraintViolationException, SqlStates, StringCodePdoException, classifiedFailure(), Throwable, PDOException

### Community 12 - "ADR 0003 — PostgreSQL idempotency arbitration"
Cohesion: 0.10
Nodes (21): ADR 0001 — Custom DDD module layout, Cross-module access only through Application, Four-layer module convention (Domain/Application/Infrastructure/Http), Rejected: third-party DDD scaffolding package, ADR 0002 — Payments Domain event-sourcing allowlist, Exact class-name matching, never namespace prefixes, LEDGERFLOW_DOMAIN_ALLOWLIST constant, Projector named and excluded from Domain (+13 more)

### Community 13 - "projection-contract.sh"
Cohesion: 0.33
Nodes (4): CONTRACT_NAME, DEADLINE_CEILING, PROJECT, projection-contract.sh script

### Community 14 - "Payments Projection Gate"
Cohesion: 0.12
Nodes (20): Ledgerflow architecture index, Payments vertical slice, Ambiguous Commit Outcome, HS-1 — Deliberately Omitted Projection Gate, Lightweight ADR Format, Architecture Decision Records Index, Rebuild Path, T11 — Connection-Affine Gated Rebuild (+12 more)

### Community 15 - "ADR 0004 — Lock-Through-Push Outbox Relay"
Cohesion: 0.24
Nodes (20): ADR 0004 — Lock-Through-Push Outbox Relay, Messaging and Queues, Payments Flow, Runtime Contract, Documentation Ownership Rules, Ledgerflow Documentation Index, Sequential Delivery Order, External Trackers (Linear and Asana) (+12 more)

### Community 16 - "Initial Relay Algorithm"
Cohesion: 0.12
Nodes (20): Lock-Through-Push Publication, OutboxRelay::relayOne(), RelayPaymentOutboxCommand, Safe Duplicate Over Lost Message, SKIP LOCKED Relay Concurrency, Event and Delivery Path, Payments Minimal Data Model, T10 — Verification and Targeted Repair (+12 more)

### Community 17 - "Illuminate\Database\QueryException"
Cohesion: 0.29
Nodes (3): Illuminate\Contracts\Database\LostConnectionDetector, Illuminate\Database\QueryException, ContentionProbe

### Community 18 - "Modules\Merchant\Application\Identity\MerchantContext"
Cohesion: 0.13
Nodes (10): Illuminate\Contracts\Auth\Factory, Illuminate\Contracts\Foundation\Application, Illuminate\Database\Eloquent\Relations\Relation, Laravel\Sanctum\PersonalAccessToken, NoAuthenticatedMerchantUser, self, AuthenticatedMerchantContext, Modules\Merchant\Application\Identity\MerchantContext (+2 more)

### Community 19 - "Merchant"
Cohesion: 0.17
Nodes (9): Illuminate\Database\Eloquent\Factories\Factory, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Database\Eloquent\Model, Illuminate\Database\Eloquent\Relations\HasMany, Illuminate\Support\Facades\Hash, Merchant, MerchantFactory, static (+1 more)

### Community 20 - "Illuminate\Support\ServiceProvider"
Cohesion: 0.25
Nodes (4): AppServiceProvider, HorizonServiceProvider, Illuminate\Support\ServiceProvider, Laravel\Horizon\Horizon

### Community 21 - "devDependencies"
Cohesion: 0.11
Nodes (17): concurrently, laravel-vite-plugin, devDependencies, concurrently, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite (+9 more)

### Community 22 - "VerifyPaymentProjectionsCommand.php"
Cohesion: 0.15
Nodes (8): VerificationFinding, VerificationExample, RepairPaymentOutboxCommand, VerifyPaymentProjectionsCommand, Modules\Payments\Application\Repair\OutboxRepair, Symfony\Component\Console\Exception\ExceptionInterface, Symfony\Component\Console\Input\InputInterface, Symfony\Component\Console\Output\OutputInterface

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
Cohesion: 0.27
Nodes (5): Illuminate\Contracts\Debug\ExceptionHandler, Illuminate\Support\Facades\Auth, Illuminate\Testing\TestResponse, showPayment(), Initiation

### Community 30 - "ModuleBoundaryTest.php"
Cohesion: 0.24
Nodes (10): Illuminate\Database\ConcurrencyErrorDetector, Illuminate\Database\DetectsConcurrencyErrors, Spatie\EventSourcing\Attributes\EventVersion, ledgerflowAfterCommitOptIns(), ledgerflowEnvironmentBranches(), ledgerflowHelperCalls(), ledgerflowNextSymbol(), ledgerflowPreviousToken() (+2 more)

### Community 31 - "Throwable"
Cohesion: 0.17
Nodes (5): ProjectionFailureClassification, relayWithRejectingTransport(), self, RejectingQueue, Throwable

### Community 32 - "HorizonSupervisionTest.php"
Cohesion: 0.18
Nodes (4): Illuminate\Support\Facades\Route, Laravel\Horizon\ProvisioningPlan, Laravel\Horizon\SupervisorOptions, Symfony\Component\HttpKernel\Exception\HttpException

### Community 33 - "User"
Cohesion: 0.21
Nodes (7): Illuminate\Database\Eloquent\Attributes\Fillable, Illuminate\Database\Eloquent\Attributes\Hidden, Illuminate\Database\Eloquent\Relations\BelongsTo, Illuminate\Foundation\Auth\User, Illuminate\Notifications\Notifiable, Laravel\Sanctum\HasApiTokens, User

### Community 34 - "GET /api/payments/{paymentId} Contract"
Cohesion: 0.18
Nodes (12): Eight Production Types Budget, Query a Payment Endpoint, Delivery Status Summary, M4 — Relay, Projection, and Query, T9 — Merchant-Scoped Payment Query, No Dependency Without a Consumer, Merchant-Scoped Idempotency Receipt, POST /api/payments Contract (+4 more)

### Community 35 - "RelayConfiguration"
Cohesion: 0.15
Nodes (3): self, RelayConfiguration, TransportBackoff

### Community 36 - "RelayHeartbeat"
Cohesion: 0.16
Nodes (7): Illuminate\Console\Command, Illuminate\Contracts\Cache\Factory, PaymentOutboxStatusCommand, RelayPaymentOutboxCommand, RelayHeartbeat, Modules\Payments\Infrastructure\Relay\RelayOutcome, stdClass

### Community 37 - "contract.sh"
Cohesion: 0.18
Nodes (18): artisan_disposable(), assert_query(), await(), await_query(), check_deadline(), cleanup(), CLEANUP_BUDGET_SECONDS, COMPOSE_PROJECT_NAME (+10 more)

### Community 38 - "PaymentOutboxRetryTest.php"
Cohesion: 0.17
Nodes (7): Illuminate\Foundation\Inspiring, Illuminate\Support\Facades\Artisan, Illuminate\Support\Facades\Schedule, operatorCapturedLogs(), operatorEnvelope(), operatorLogsOfCategory(), ArrayObject

### Community 39 - "DatabaseProjectionRebuild"
Cohesion: 0.13
Nodes (6): rebuild(), RebuildReason, self, RebuildOutcome, DatabaseProjectionRebuild, RebuildReason

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

### Community 44 - "SpikeFailureSwitch"
Cohesion: 0.14
Nodes (5): Illuminate\Queue\Events\JobQueued, Illuminate\Support\Facades\Event, SpikeFailureSwitch, SpikeRelayAttempt, SpikeRelayJob

### Community 45 - "Canonical Repository Instructions (root AGENTS.md)"
Cohesion: 0.25
Nodes (8): GitHub Automation Instructions, No Second Development Environment in CI, .github CLAUDE.md Adapter, ADR 0002 Payments Domain Event-Sourcing Allowlist, make agent-check Instruction Parity Guard, Nested AGENTS.md Path Scoping with CLAUDE.md Adapters, Canonical Repository Instructions (root AGENTS.md), Framework-Independent Domain with Event-Sourcing Allowlist

### Community 46 - "config"
Cohesion: 0.20
Nodes (10): pestphp/pest-plugin, php-http/discovery, wikimedia/composer-merge-plugin, config, allow-plugins, optimize-autoloader, platform, preferred-install (+2 more)

### Community 47 - "Decision"
Cohesion: 0.08
Nodes (24): 0004 — Publish the payment outbox with a lock-through-push relay, without a shared projection gate, Alternatives considered, Consequences, Context, Decision, HS-1 — the shared projection gate is deliberately omitted here, Measured evidence, Related (+16 more)

### Community 48 - "One Job, One Transport Rule"
Cohesion: 0.22
Nodes (10): ADR Candidate Backlog, OpenSearch Adoption Gate, Planned AWS Topology, One Image, Independently Supervised Processes, Redis/SQS Capability Map, SQS Adoption Boundary, One Job, One Transport Rule, Source-of-Truth Hierarchy (+2 more)

### Community 49 - "RelayPaymentOutboxCommandTest.php"
Cohesion: 0.24
Nodes (6): bindRelayCommand(), passContexts(), passLogs(), ArrayObject, Closure, steppingClock()

### Community 50 - "Spatie\EventSourcing\StoredEvents\ShouldBeStored"
Cohesion: 0.24
Nodes (4): SpikePaymentAggregate, SpikePaymentInitiated, Spatie\EventSourcing\AggregateRoots\AggregateRoot, Spatie\EventSourcing\StoredEvents\ShouldBeStored

### Community 51 - "ProjectionFailureRecorderTest.php"
Cohesion: 0.31
Nodes (6): Illuminate\Log\Events\MessageLogged, ArrayObject, recorderCapturedLogs(), recorderEnvelope(), recorderLogContexts(), recorderLogsOfCategory()

### Community 52 - "Paired Scheduler+Horizon Activation"
Cohesion: 0.22
Nodes (9): payments-projection Logical Queue, Application Process Topology, Repository Deploy Ownership, Bounded projection-contract Deadline, Paired Scheduler+Horizon Activation, Container Contract, Disposable-Project Image Build Limitation, Queue Transport Policy (+1 more)

### Community 53 - "RuntimeException"
Cohesion: 0.06
Nodes (25): ProblemType, ProblemDetailsMapper, Illuminate\Auth\AuthenticationException, Illuminate\Foundation\Application, Illuminate\Foundation\Configuration\Exceptions, Illuminate\Foundation\Configuration\Middleware, Illuminate\Http\Request, Illuminate\Validation\ValidationException (+17 more)

### Community 54 - "Psr\Log\LoggerInterface"
Cohesion: 0.21
Nodes (4): Factory, Illuminate\Database\Connection, ProjectionFailureRecorder, Psr\Log\LoggerInterface

### Community 55 - "OutboxRelayTest.php"
Cohesion: 0.20
Nodes (5): Illuminate\Database\Events\TransactionRolledBack, Illuminate\Support\Facades\Log, ArrayObject, relayCapturedLogs(), relayLogField()

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

### Community 62 - "ProjectionRebuildTest.php"
Cohesion: 0.12
Nodes (7): self, RebuildConfiguration, expectAbandonedRebuildGateClosed(), rebuildLockHeld(), rebuildSeed(), rebuildWithConfig(), rebuildWithFailingLogger()

### Community 63 - "InitiationCommitBoundaryTest.php"
Cohesion: 0.18
Nodes (5): Illuminate\Database\Events\TransactionCommitting, IdentityRecorder, ThrowingProjector, Spatie\EventSourcing\EventHandlers\Projectors\Projector, SplObjectStorage

### Community 64 - "keywords"
Cohesion: 0.29
Nodes (7): keywords, cqrs, ddd, event-sourcing, fintech, laravel, payments

### Community 65 - "MerchantDatabaseSeeder.php"
Cohesion: 0.39
Nodes (4): DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Seeder, MerchantDatabaseSeeder

### Community 66 - "Atomic Idempotent Write"
Cohesion: 0.29
Nodes (7): Payments Write Path, Payments Tracer Bullet, T6 — Atomic Idempotent Payment Initiation, Atomic Idempotent Write, T3 Blocking Compatibility Evidence (Q1–Q5), SQLSTATE Retry Allowlist, Transport Exception Classification

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
Cohesion: 0.08
Nodes (11): verify(), self, VerificationReport, DatabaseProjectionVerifier, CarbonImmutable, VerificationFinding, self, VerificationConfiguration (+3 more)

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
Nodes (8): Closure, Command, Illuminate\Database\DeadlockException, run(), RebuildPaymentProjectionsCommand, Modules\Payments\Application\Rebuild\ProjectionRebuild, SpikeInitiationHandler, SpikeTransactionRetry

### Community 115 - "PaymentInitiated"
Cohesion: 0.23
Nodes (9): Illuminate\Database\Events\QueryExecuted, PaymentInitiated, PaymentOutboxWriter, t5StoredEvent(), handWrittenPaymentInitiated(), runOutboxWriter(), Spatie\EventSourcing\Projectionist, Spatie\EventSourcing\StoredEvents\StoredEvent (+1 more)

### Community 116 - "Illuminate\Support\Str"
Cohesion: 0.19
Nodes (6): Carbon\CarbonImmutable, Illuminate\Support\Str, Modules\Payments\Application\Repair\RepairMutationState, Modules\Payments\Application\Verification\ProjectionVerifier, Modules\Payments\Infrastructure\Projection\ProjectionGateState, Pdo\Mysql

### Community 117 - "PaymentProjectionVerifierTest.php"
Cohesion: 0.20
Nodes (5): verifierBulkSeed(), verifierCounts(), verifierPlans(), verifierRun(), verifierSeed()

### Community 119 - "PaymentProjectionConsumer"
Cohesion: 0.23
Nodes (4): Illuminate\Contracts\Queue\ShouldQueueAfterCommit, PaymentProjectionConsumer, ProjectPaymentEvent, ProjectionOutcome

### Community 124 - "DatabaseOutboxRepair"
Cohesion: 0.24
Nodes (4): DatabaseOutboxRepair, RepairMutationState, RepairReason, Modules\Payments\Application\Repair\RepairReason

### Community 125 - "ProjectionGate"
Cohesion: 0.15
Nodes (14): Illuminate\Database\ConnectionInterface, DatabaseIdempotencyReceipts, RetryingAtomicTransaction, ProjectionGate, Modules\Payments\Application\Initiation\AtomicTransaction, Modules\Payments\Application\Initiation\IdempotencyReceipts, controlRowViolation(), gate() (+6 more)

### Community 126 - "RepairOutcome"
Cohesion: 0.29
Nodes (5): repair(), RepairMutationState, RepairReason, self, RepairOutcome

### Community 127 - "MerchantIdentityTest.php"
Cohesion: 0.28
Nodes (4): Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase, ExampleTest, TestCase

### Community 128 - "PaymentProjectionConsumerTest.php"
Cohesion: 0.17
Nodes (5): Modules\Payments\Infrastructure\Projection\ProjectionOutcome, ArrayObject, projectionCapturedLogs(), projectionEnvelope(), projectionLogContexts()

### Community 129 - "OutboxRelay"
Cohesion: 0.27
Nodes (4): Illuminate\Contracts\Queue\Factory, OutboxRelay, RedisException, RelayOutcome

### Community 131 - "SpikeDatabase.php"
Cohesion: 0.50
Nodes (3): SpikeSnapshot, Spatie\EventSourcing\Snapshots\EloquentSnapshot, Spatie\EventSourcing\StoredEvents\Repositories\StoredEventRepository

### Community 132 - "PaymentOutboxRepairTest.php"
Cohesion: 0.29
Nodes (4): Illuminate\Database\DatabaseManager, repairCanonicalLookupPlan(), repairRun(), repairSeed()

### Community 134 - "VerificationEnvelope"
Cohesion: 0.27
Nodes (5): self, VerificationEnvelope, verificationCanonicalEvent(), verificationCanonicalPayload(), verificationEnvelope()

### Community 135 - "sanctum.php"
Cohesion: 0.40
Nodes (4): Illuminate\Cookie\Middleware\EncryptCookies, Illuminate\Foundation\Http\Middleware\ValidateCsrfToken, Laravel\Sanctum\Http\Middleware\AuthenticateSession, Laravel\Sanctum\Sanctum

### Community 136 - "ProblemDetails"
Cohesion: 0.29
Nodes (4): ProblemType, ProblemDetails, Illuminate\Http\JsonResponse, QueryPaymentController

### Community 139 - "MerchantServiceProvider"
Cohesion: 0.25
Nodes (3): MerchantServiceProvider, PaymentsServiceProvider, Nwidart\Modules\Facades\Module

### Community 143 - ".resolve"
Cohesion: 0.33
Nodes (5): self, installContentionRig(), relay(), projectionConsumer(), recorder()

### Community 144 - "FailingLogger"
Cohesion: 0.47
Nodes (3): FailingLogger, Psr\Log\AbstractLogger, Stringable

## Knowledge Gaps
- **155 isolated node(s):** `name`, `description`, `Modules\\Merchant\\`, `Modules\\Merchant\\Database\\Factories\\`, `Modules\\Merchant\\Database\\Seeders\\` (+150 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **24 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Merchant` connect `Merchant` to `PaymentProjectionConsumerTest.php`, `MerchantDatabaseSeeder.php`, `User`, `PaymentOutboxRepairTest.php`, `PaymentOutboxRetryTest.php`, `PaymentQueryTest.php`, `PaymentAggregate`, `.resolve`, `Modules\Merchant\Application\Identity\MerchantContext`, `PaymentInitiated`, `Illuminate\Support\Str`, `PaymentsSchemaTest.php`, `InitiationCommitBoundaryTest.php`, `PaymentProjectionVerifierTest.php`, `ProjectionFailureRecorderTest.php`, `QueryPaymentTest.php`, `ProjectionRebuildTest.php`, `MerchantIdentityTest.php`?**
  _High betweenness centrality (0.044) - this node is a cross-community bridge._
- **Why does `PaymentInitiated` connect `PaymentInitiated` to `PaymentInitiatedEnvelope`, `PaymentInitiationContext`, `PaymentOutboxRepairTest.php`, `VerificationEnvelope`, `InvalidPaymentInitiation`, `PaymentAggregate`, `Illuminate\Database\QueryException`, `Spatie\EventSourcing\StoredEvents\ShouldBeStored`, `InitiationCommitBoundaryTest.php`, `Illuminate\Support\Str`, `PaymentProjectionVerifierTest.php`, `Psr\Log\LoggerInterface`, `OutboxEnvelopeNotDerivable`, `ModuleBoundaryTest.php`, `ProjectionGate`, `ProjectionRebuildTest.php`, `Throwable`?**
  _High betweenness centrality (0.039) - this node is a cross-community bridge._
- **Why does `DatabaseProjectionVerifier` connect `DatabaseProjectionVerifier` to `Illuminate\Support\Str`, `PaymentProjectionVerifierTest.php`, `Psr\Log\LoggerInterface`, `Closure`, `ProjectionGate`, `ProjectionRebuildTest.php`?**
  _High betweenness centrality (0.032) - this node is a cross-community bridge._
- **What connects `name`, `description`, `Modules\\Merchant\\` to the rest of the system?**
  _155 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `PaymentInitiatedEnvelope` be split into smaller, more focused modules?**
  _Cohesion score 0.14624505928853754 - nodes in this community are weakly interconnected._
- **Should `Illuminate\Support\Facades\Schema` be split into smaller, more focused modules?**
  _Cohesion score 0.07293868921775898 - nodes in this community are weakly interconnected._
- **Should `RecordingConnection` be split into smaller, more focused modules?**
  _Cohesion score 0.13538461538461538 - nodes in this community are weakly interconnected._