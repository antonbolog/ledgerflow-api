# Graph Report - ledgerflow-api  (2026-09-23)

## Corpus Check
- 269 files · ~144,131 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1614 nodes · 3011 edges · 145 communities (125 shown, 20 thin omitted)
- Extraction: 98% EXTRACTED · 2% INFERRED · 0% AMBIGUOUS · INFERRED: 73 edges (avg confidence: 0.86)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `05e67a87`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- ModuleBoundaryTest.php
- Illuminate\Support\Facades\Schema
- RecordingConnection
- RelayTransport
- PaymentInitiationContext
- The sequence
- DatabasePaymentQuery.php
- PaymentInitiationPersistenceTest.php
- IntegrationDatabase
- InvalidPaymentInitiation
- RebuildConfiguration
- Authorization
- ADR 0003 — PostgreSQL idempotency arbitration
- RelayConfiguration
- Payments Projection Gate
- ADR 0004 — Lock-Through-Push Outbox Relay
- Initial Relay Algorithm
- DatabaseProjectionRebuild
- MerchantLoginController
- Merchant
- MerchantServiceProvider
- devDependencies
- Closure
- ProblemDetailsMapper.php
- Illuminate\Support\Str
- Payments Module Instructions
- Docker Compose Local Stack
- Documentation Instructions
- GET /api/payments/{paymentId} Owned Payment Query
- TransportBackoff
- OpenApiContractTest.php
- Throwable
- PDOException
- User
- GET /api/payments/{paymentId} Contract
- Illuminate\Support\Facades\DB
- MerchantServiceProvider.php
- ProjectionConfiguration
- PaymentQueryTest.php
- composer.json
- require-dev
- Test Instructions
- Merchant/composer.json
- PaymentInitiated
- Canonical Repository Instructions (root AGENTS.md)
- config
- Decision
- One Job, One Transport Rule
- RelayPaymentOutboxCommandTest.php
- Illuminate\Support\Facades\Route
- AppServiceProvider
- Paired Scheduler+Horizon Activation
- RuntimeException
- Psr\Log\LoggerInterface
- ProjectionRebuildTest.php
- Payments/composer.json
- CI GitHub Actions Workflow
- require
- scripts
- Merchant OAuth and BFF Authentication Specification
- T8 — Horizon-Supervised Projection Consumer
- Spatie\EventSourcing\EventHandlers\Projectors\Projector
- FailingLogger
- keywords
- Laravel\Passport\Passport
- Atomic Idempotent Write
- extra
- rector.php
- OutboxRelayTest.php
- T10 — Verification and Targeted Repair
- psr-4
- logging.php
- DatabaseProjectionVerifier
- Token-scanner enforcement limits
- Deferred Sanctum/SPA Session Decisions
- oauth-bff-authentication.md
- Illuminate\Support\Facades\Artisan
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
- IdempotencyContention
- CLAUDE.md → AGENTS.md pointer
- Empty Domain directories are deliberate
- Outbox payload read back, never re-serialised
- First Vertical Slice Scenario
- Permissive robots.txt Crawl Policy
- PaymentsSchemaTest.php
- Sequences
- Modules\Merchant\Application\Identity\MerchantContext
- PaymentProjectionVerifierTest.php
- PaymentOutboxWriter
- PaymentProjectionConsumer
- PaymentInitiatedEnvelope
- ProjectionGate
- Modules\Payments\Application\Query\PaymentQuery
- TestCase
- PaymentProjectionConsumerTest.php
- 0006 - Authenticate merchants with Passport OAuth 2.0 behind a Next.js BFF
- ProjectionRebuilding
- Modules\Payments\Application\Rebuild\RebuildReason
- 0007 - Hold merchant OAuth tokens in a server-side BFF session behind an opaque cookie
- ProjectionFailureRecorderTest.php
- PassportFoundationTest.php
- oauth.php
- PaymentOutboxRetryCommand
- BFF state in Redis
- AdvisoryExclusiveWaiter
- Origins

## God Nodes (most connected - your core abstractions)
1. `Merchant` - 49 edges
2. `ProjectionGate` - 40 edges
3. `PaymentInitiated` - 38 edges
4. `User` - 34 edges
5. `RetryingAtomicTransaction` - 34 edges
6. `DatabaseProjectionVerifier` - 33 edges
7. `PaymentInitiatedEnvelope` - 32 edges
8. `PaymentProjectionConsumer` - 30 edges
9. `ProjectionEnvelopeRejected` - 28 edges
10. `RelayConfiguration` - 26 edges

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

## Communities (145 total, 20 thin omitted)

### Community 0 - "ModuleBoundaryTest.php"
Cohesion: 0.22
Nodes (12): Illuminate\Database\ConcurrencyErrorDetector, Illuminate\Database\DetectsConcurrencyErrors, Spatie\EventSourcing\Attributes\EventVersion, ledgerflowAfterCommitOptIns(), ledgerflowCallReceiver(), ledgerflowEnvironmentBranches(), ledgerflowFirstStringArgument(), ledgerflowHelperCalls() (+4 more)

### Community 1 - "Illuminate\Support\Facades\Schema"
Cohesion: 0.05
Nodes (3): Illuminate\Database\Migrations\Migration, Illuminate\Database\Schema\Blueprint, Illuminate\Support\Facades\Schema

### Community 2 - "RecordingConnection"
Cohesion: 0.14
Nodes (3): LogicException, IncompleteVerificationScope, RecordingConnection

### Community 3 - "RelayTransport"
Cohesion: 0.07
Nodes (17): Illuminate\Cache\ArrayStore, Illuminate\Cache\RedisStore, Illuminate\Console\Scheduling\Schedule, Illuminate\Contracts\Console\Kernel, Illuminate\Queue\Jobs\InspectedJob, Illuminate\Queue\SyncQueue, Illuminate\Support\Carbon, Illuminate\Support\Collection (+9 more)

### Community 4 - "PaymentInitiationContext"
Cohesion: 0.08
Nodes (15): Illuminate\Http\Response, Laravel\Passport\AccessToken, MerchantLogoutController, MerchantTokenRevocation, findWinner(), reserve(), InitiatePayment, AtomicTransaction (+7 more)

### Community 5 - "The sequence"
Cohesion: 0.11
Nodes (17): 10. Stop, 1. Initiate a payment, 2. Watch the read model catch up, 3. Prove idempotency, 4. Take Redis away, 5. Give Redis back, 6. Break the read model on purpose, 7. Detect the corruption, read-only (+9 more)

### Community 6 - "DatabasePaymentQuery.php"
Cohesion: 0.18
Nodes (6): PaymentNotFound, self, findOwned(), PendingPayment, ProjectedPayment, DatabasePaymentQuery

### Community 7 - "PaymentInitiationPersistenceTest.php"
Cohesion: 0.17
Nodes (10): DomainException, PaymentAlreadyInitiated, self, PaymentAggregate, static, persistOnePayment(), initiateOn(), Spatie\EventSourcing\AggregateRoots\AggregateRoot (+2 more)

### Community 8 - "IntegrationDatabase"
Cohesion: 0.12
Nodes (5): Modules\Payments\Infrastructure\Projection\ProjectionGateState, IntegrationDatabase, isolationGateState(), gate(), markRebuilding()

### Community 9 - "InvalidPaymentInitiation"
Cohesion: 0.17
Nodes (3): InvalidArgumentException, InvalidPaymentInitiation, self

### Community 10 - "RebuildConfiguration"
Cohesion: 0.13
Nodes (9): self, RebuildConfiguration, self, installContentionRig(), relay(), projectionConsumer(), recorder(), rebuildWithConfig() (+1 more)

### Community 11 - "Authorization"
Cohesion: 0.15
Nodes (10): Illuminate\Database\Events\TransactionBeginning, Illuminate\Support\Facades\Auth, Illuminate\Testing\TestResponse, Laravel\Passport\Client, callLogout(), callPayments(), loggedInMerchant(), accessTokenId() (+2 more)

### Community 12 - "ADR 0003 — PostgreSQL idempotency arbitration"
Cohesion: 0.10
Nodes (21): ADR 0001 — Custom DDD module layout, Cross-module access only through Application, Four-layer module convention (Domain/Application/Infrastructure/Http), Rejected: third-party DDD scaffolding package, ADR 0002 — Payments Domain event-sourcing allowlist, Exact class-name matching, never namespace prefixes, LEDGERFLOW_DOMAIN_ALLOWLIST constant, Projector named and excluded from Domain (+13 more)

### Community 13 - "RelayConfiguration"
Cohesion: 0.14
Nodes (8): Illuminate\Console\Command, Illuminate\Contracts\Cache\Factory, PaymentOutboxStatusCommand, RelayPaymentOutboxCommand, self, RelayConfiguration, RelayHeartbeat, Modules\Payments\Infrastructure\Relay\RelayOutcome

### Community 14 - "Payments Projection Gate"
Cohesion: 0.15
Nodes (16): Ledgerflow architecture index, Payments vertical slice, HS-1 — Deliberately Omitted Projection Gate, Lightweight ADR Format, Architecture Decision Records Index, Rebuild Path, T11 — Connection-Affine Gated Rebuild, T7 — Scheduler-Driven Outbox Relay (+8 more)

### Community 15 - "ADR 0004 — Lock-Through-Push Outbox Relay"
Cohesion: 0.24
Nodes (20): ADR 0004 — Lock-Through-Push Outbox Relay, Messaging and Queues, Payments Flow, Runtime Contract, Documentation Ownership Rules, Ledgerflow Documentation Index, Sequential Delivery Order, External Trackers (Linear and Asana) (+12 more)

### Community 16 - "Initial Relay Algorithm"
Cohesion: 0.14
Nodes (17): Ambiguous Commit Outcome, OutboxRelay::relayOne(), RelayPaymentOutboxCommand, SKIP LOCKED Relay Concurrency, Event and Delivery Path, Payments Minimal Data Model, T4 Blocking Compatibility Evidence (Q6–Q10), Evolution Gate — Claim/Lease Publishing (+9 more)

### Community 17 - "DatabaseProjectionRebuild"
Cohesion: 0.13
Nodes (6): rebuild(), RebuildReason, self, RebuildOutcome, DatabaseProjectionRebuild, RebuildReason

### Community 18 - "MerchantLoginController"
Cohesion: 0.16
Nodes (7): Illuminate\Contracts\Auth\Factory, Illuminate\Contracts\Auth\StatefulGuard, Illuminate\Contracts\View\View, Illuminate\Http\RedirectResponse, Illuminate\Support\Facades\Redirect, MerchantLoginController, EndMerchantAuthorizationSession

### Community 19 - "Merchant"
Cohesion: 0.13
Nodes (11): Illuminate\Contracts\Debug\ExceptionHandler, Illuminate\Database\Eloquent\Factories\Factory, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Database\Eloquent\Model, Illuminate\Database\Eloquent\Relations\HasMany, Merchant, MerchantFactory, showPayment() (+3 more)

### Community 20 - "MerchantServiceProvider"
Cohesion: 0.38
Nodes (3): MerchantServiceProvider, AuthFactory, StatefulGuard

### Community 21 - "devDependencies"
Cohesion: 0.11
Nodes (17): concurrently, laravel-vite-plugin, devDependencies, concurrently, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite (+9 more)

### Community 22 - "Closure"
Cohesion: 0.08
Nodes (16): Closure, Command, Illuminate\Foundation\Http\FormRequest, Illuminate\Validation\Rule, run(), VerificationFinding, VerificationExample, InitiatePaymentRequest (+8 more)

### Community 23 - "ProblemDetailsMapper.php"
Cohesion: 0.21
Nodes (9): ProblemType, ProblemDetailsMapper, Illuminate\Auth\AuthenticationException, Illuminate\Foundation\Application, Illuminate\Foundation\Configuration\Exceptions, Illuminate\Foundation\Configuration\Middleware, Illuminate\Http\Request, Illuminate\Validation\ValidationException (+1 more)

### Community 24 - "Illuminate\Support\Str"
Cohesion: 0.12
Nodes (8): Illuminate\Database\QueryException, Illuminate\Support\Str, League\OAuth2\Server\Exception\OAuthServerException, operatorCapturedLogs(), operatorEnvelope(), operatorLogsOfCategory(), ArrayObject, Pdo\Mysql

### Community 25 - "Payments Module Instructions"
Cohesion: 0.16
Nodes (13): ADR 0001 Custom DDD Module Layout, Custom DDD Module Layout (Domain/Application/Infrastructure/Http), One-Connection Transaction Ownership, Payments Bounded Context, Sensitive Payment Data Never Logged, Boundaries, Evidence, Invariants (+5 more)

### Community 26 - "Docker Compose Local Stack"
Cohesion: 0.21
Nodes (14): make projection-contract Disposable Compose Proof, make projection-start / projection-stop Pair Activation, Single-Owner Messaging and Queue Ownership, Scheduler-Driven Outbox Relay, payments-projection Redis Queue, app (PHP-FPM) Service, horizon Service (projection profile), postgres Service (+6 more)

### Community 27 - "Documentation Instructions"
Cohesion: 0.17
Nodes (12): Ledgerflow Fintech System, docs/roadmap.md Delivery Authority, Documentation Instructions, Documentation Ownership Map, Ownership, Rules, Update the Smallest Canonical Document, docs CLAUDE.md Adapter (+4 more)

### Community 28 - "GET /api/payments/{paymentId} Owned Payment Query"
Cohesion: 0.18
Nodes (13): Merchant Bounded Context, payments:outbox:retry Reactivation Command, Horizon-Supervised Projection Consumer, Monotonic projection_failure_generation Fence, Payments Compatibility Contracts (aliases, versions, payloads, fingerprints), Separate Transport and Projection Failure Counters, GET /api/payments/{paymentId} Owned Payment Query, Idempotent Retry Replay of Stored Response Bytes (+5 more)

### Community 30 - "OpenApiContractTest.php"
Cohesion: 0.27
Nodes (3): openApiBodyProperties(), openApiDocument(), openApiOperation()

### Community 31 - "Throwable"
Cohesion: 0.13
Nodes (9): Illuminate\Contracts\Queue\Factory, ProjectionFailureClassification, OutboxRelay, relayWithRejectingTransport(), self, RejectingQueue, RedisException, RelayOutcome (+1 more)

### Community 32 - "PDOException"
Cohesion: 0.19
Nodes (6): Illuminate\Database\UniqueConstraintViolationException, SqlStates, StringCodePdoException, classifiedFailure(), Throwable, PDOException

### Community 33 - "User"
Cohesion: 0.10
Nodes (15): Illuminate\Database\Eloquent\Attributes\Fillable, Illuminate\Database\Eloquent\Attributes\Hidden, Illuminate\Database\Eloquent\Relations\BelongsTo, Illuminate\Foundation\Auth\User, Illuminate\Notifications\Notifiable, Illuminate\Support\Facades\Hash, Laravel\Passport\Contracts\OAuthenticatable, Laravel\Passport\HasApiTokens (+7 more)

### Community 34 - "GET /api/payments/{paymentId} Contract"
Cohesion: 0.18
Nodes (12): Eight Production Types Budget, Query a Payment Endpoint, Delivery Status Summary, M4 — Relay, Projection, and Query, T9 — Merchant-Scoped Payment Query, No Dependency Without a Consumer, Merchant-Scoped Idempotency Receipt, POST /api/payments Contract (+4 more)

### Community 35 - "Illuminate\Support\Facades\DB"
Cohesion: 0.10
Nodes (11): Illuminate\Console\Events\CommandFinished, Illuminate\Database\DatabaseManager, Illuminate\Database\Events\QueryExecuted, Illuminate\Database\Events\TransactionCommitting, Illuminate\Log\Events\MessageLogged, Illuminate\Support\Arr, Illuminate\Support\Facades\DB, Illuminate\Support\Facades\Event (+3 more)

### Community 36 - "MerchantServiceProvider.php"
Cohesion: 0.17
Nodes (9): Illuminate\Cache\RateLimiting\Limit, Illuminate\Contracts\Auth\Authenticatable, Illuminate\Database\Eloquent\Relations\Relation, Illuminate\Support\Facades\RateLimiter, Laravel\Passport\Bridge\RefreshTokenRepository, Laravel\Passport\Http\Controllers\AuthorizationController, MerchantAuthorizationController, AtomicRefreshTokenRepository (+1 more)

### Community 39 - "PaymentQueryTest.php"
Cohesion: 0.20
Nodes (6): Illuminate\Database\Events\TransactionCommitted, paymentQueryAdapter(), paymentQuerySql(), paymentQueryStatements(), ArrayObject, PaymentQuery

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

### Community 44 - "PaymentInitiated"
Cohesion: 0.29
Nodes (5): DateTimeImmutable, PaymentInitiated, handWrittenPaymentInitiated(), runOutboxWriter(), Spatie\EventSourcing\StoredEvents\ShouldBeStored

### Community 45 - "Canonical Repository Instructions (root AGENTS.md)"
Cohesion: 0.20
Nodes (10): GitHub Automation Instructions, No Second Development Environment in CI, .github CLAUDE.md Adapter, ADR 0002 Payments Domain Event-Sourcing Allowlist, ADR 0004 Lock-Through-Push Outbox Relay, make agent-check Instruction Parity Guard, Nested AGENTS.md Path Scoping with CLAUDE.md Adapters, Deferred Shared Projection Gate (T11) (+2 more)

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
Cohesion: 0.18
Nodes (8): Factory, Illuminate\Contracts\Foundation\Application, bindRelayCommand(), passContexts(), passLogs(), ArrayObject, Closure, steppingClock()

### Community 50 - "Illuminate\Support\Facades\Route"
Cohesion: 0.18
Nodes (4): Illuminate\Support\Facades\Route, Laravel\Horizon\ProvisioningPlan, Laravel\Horizon\SupervisorOptions, Symfony\Component\HttpKernel\Exception\HttpException

### Community 51 - "AppServiceProvider"
Cohesion: 0.15
Nodes (6): AppServiceProvider, HorizonServiceProvider, Illuminate\Console\Events\CommandStarting, Illuminate\Support\ServiceProvider, Laravel\Horizon\Horizon, PaymentsServiceProvider

### Community 52 - "Paired Scheduler+Horizon Activation"
Cohesion: 0.22
Nodes (9): payments-projection Logical Queue, Application Process Topology, Repository Deploy Ownership, Bounded projection-contract Deadline, Paired Scheduler+Horizon Activation, Container Contract, Disposable-Project Image Build Limitation, Queue Transport Policy (+1 more)

### Community 53 - "RuntimeException"
Cohesion: 0.14
Nodes (7): IdempotencyPayloadMismatch, self, ProjectionEnvelopeMissing, RebuildFenceFailed, VerificationSnapshotUnavailable, StringCodeException, RuntimeException

### Community 54 - "Psr\Log\LoggerInterface"
Cohesion: 0.19
Nodes (4): Illuminate\Database\Connection, ProjectionFailureRecorder, Psr\Log\LoggerInterface, ReflectionProperty

### Community 55 - "ProjectionRebuildTest.php"
Cohesion: 0.15
Nodes (4): ProjectionGateLocks, expectAbandonedRebuildGateClosed(), rebuildLockHeld(), rebuildSeed()

### Community 56 - "Payments/composer.json"
Cohesion: 0.22
Nodes (8): autoload, autoload-dev, psr-4, psr-4, description, name, Modules\\Payments\\, Modules\\Payments\\Tests\\

### Community 57 - "CI GitHub Actions Workflow"
Cohesion: 0.32
Nodes (8): Least-Privilege Job Permissions and Non-Persisted Credentials, Workflows Must Not Duplicate Tool Command Sequences, check Job (make ci), Compose Diagnostics on Failure Step, CI GitHub Actions Workflow, make check Validation Contract, make ci Full Contract, Makefile as Public Development Interface

### Community 58 - "require"
Cohesion: 0.25
Nodes (8): require, laravel/framework, laravel/horizon, laravel/passport, laravel/tinker, nwidart/laravel-modules, php, spatie/laravel-event-sourcing

### Community 59 - "scripts"
Cohesion: 0.25
Nodes (8): scripts, post-autoload-dump, post-update-cmd, pre-package-uninstall, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump, Illuminate\\Foundation\\ComposerScripts::prePackageUninstall, @php artisan package:discover --ansi, @php artisan vendor:publish --tag=laravel-assets --ansi --force

### Community 60 - "Merchant OAuth and BFF Authentication Specification"
Cohesion: 0.12
Nodes (17): Browser-to-BFF CSRF and origin validation, Client registration, Cookie contract, Decision, Deferred, Given, Guard layout, Limitations and non-claims (+9 more)

### Community 61 - "T8 — Horizon-Supervised Projection Consumer"
Cohesion: 0.32
Nodes (8): Queue Failure Ownership, Required Operational Evidence, T8 — Horizon-Supervised Projection Consumer, Outbox State Transitions, Outbox Writer Ownership Split, Projection Failure Classification, payments:outbox:retry, projection_failure_generation Fence

### Community 62 - "Spatie\EventSourcing\EventHandlers\Projectors\Projector"
Cohesion: 0.24
Nodes (4): IdentityRecorder, ThrowingProjector, Spatie\EventSourcing\EventHandlers\Projectors\Projector, SplObjectStorage

### Community 63 - "FailingLogger"
Cohesion: 0.47
Nodes (3): FailingLogger, Psr\Log\AbstractLogger, Stringable

### Community 64 - "keywords"
Cohesion: 0.29
Nodes (7): keywords, cqrs, ddd, event-sourcing, fintech, laravel, payments

### Community 65 - "Laravel\Passport\Passport"
Cohesion: 0.13
Nodes (8): DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Seeder, Illuminate\Support\Facades\Date, Laravel\Passport\ClientRepository, Laravel\Passport\Passport, ProvisionBffClientCommand, PersonalAccessClientSeeder

### Community 66 - "Atomic Idempotent Write"
Cohesion: 0.20
Nodes (10): Lock-Through-Push Publication, Safe Duplicate Over Lost Message, Payments Write Path, Payments Tracer Bullet, T6 — Atomic Idempotent Payment Initiation, Atomic Idempotent Write, T3 Blocking Compatibility Evidence (Q1–Q5), SQLSTATE Retry Allowlist (+2 more)

### Community 67 - "extra"
Cohesion: 0.33
Nodes (6): extra, laravel, merge-plugin, dont-discover, include, Modules/*/composer.json

### Community 68 - "rector.php"
Cohesion: 0.33
Nodes (5): Rector\Config\RectorConfig, Rector\Php55\Rector\String_\StringClassNameToClassConstantRector, RectorLaravel\Rector\ArrayDimFetch\EnvVariableToEnvHelperRector, RectorLaravel\Rector\ArrayDimFetch\ServerVariableToRequestFacadeRector, RectorLaravel\Set\LaravelSetList

### Community 69 - "OutboxRelayTest.php"
Cohesion: 0.20
Nodes (5): Illuminate\Database\Events\TransactionRolledBack, Illuminate\Support\Facades\Log, ArrayObject, relayCapturedLogs(), relayLogField()

### Community 70 - "T10 — Verification and Targeted Repair"
Cohesion: 0.50
Nodes (4): T10 — Verification and Targeted Repair, payments:projections:verify, payments:projections:verify command, Scheduled projection verification and corruption alerting

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

### Community 76 - "oauth-bff-authentication.md"
Cohesion: 0.25
Nodes (8): Deployment Boundaries, GitHub Actions OIDC Role Assumption, Immutable Artifact Delivery Model, Three-Repository Ownership Split, Interfaces and Realtime, User Interface Surface Ownership, Ledgerflow System Overview, Payments Specifications Index

### Community 77 - "Illuminate\Support\Facades\Artisan"
Cohesion: 0.18
Nodes (7): Illuminate\Database\Console\WipeCommand, Illuminate\Foundation\Inspiring, Illuminate\Support\Facades\Artisan, Illuminate\Support\Facades\Process, Illuminate\Support\Facades\Schedule, Symfony\Component\Console\Input\ArrayInput, Symfony\Component\Console\Output\BufferedOutput

### Community 79 - "Five-State Relay Heartbeat"
Cohesion: 0.67
Nodes (3): PaymentOutboxStatusCommand, Five-State Relay Heartbeat, schedule:work First-Tick Latency

### Community 80 - "Payments and Merchant Bounded Contexts"
Cohesion: 0.67
Nodes (3): ADR 0001 Custom DDD Module Layout, ADR 0002 Payments Domain Event-Sourcing Allowlist, Payments and Merchant Bounded Contexts

### Community 81 - "Public Makefile Interface"
Cohesion: 0.67
Nodes (3): Public Makefile Interface, PHPStan Level 10 Without Baseline, Resilience Test Environment Rules

### Community 90 - "IdempotencyContention"
Cohesion: 0.20
Nodes (4): IdempotencyContention, self, self, ProjectionRebuilding

### Community 115 - "Sequences"
Cohesion: 0.20
Nodes (10): 1. Login, 2. Authorization redirect, 3. Callback, 4. Token exchange, 5. Authenticated request, 6. Refresh, 7. Retry, 8. Logout (+2 more)

### Community 116 - "Modules\Merchant\Application\Identity\MerchantContext"
Cohesion: 0.15
Nodes (7): NoAuthenticatedMerchantUser, self, AuthenticatedMerchantContext, Modules\Merchant\Application\Identity\MerchantContext, actAsBearerOf(), __construct(), CountingMerchantContext

### Community 117 - "PaymentProjectionVerifierTest.php"
Cohesion: 0.20
Nodes (5): verifierBulkSeed(), verifierCounts(), verifierPlans(), verifierRun(), verifierSeed()

### Community 118 - "PaymentOutboxWriter"
Cohesion: 0.23
Nodes (5): OutboxEnvelopeNotDerivable, self, PaymentOutboxWriter, Spatie\EventSourcing\Projectionist, Spatie\EventSourcing\StoredEvents\StoredEvent

### Community 119 - "PaymentProjectionConsumer"
Cohesion: 0.23
Nodes (4): Illuminate\Contracts\Queue\ShouldQueueAfterCommit, PaymentProjectionConsumer, ProjectPaymentEvent, ProjectionOutcome

### Community 124 - "PaymentInitiatedEnvelope"
Cohesion: 0.05
Nodes (23): Carbon\CarbonImmutable, repair(), RepairMutationState, RepairReason, self, RepairOutcome, PaymentInitiatedEnvelope, CarbonImmutable (+15 more)

### Community 125 - "ProjectionGate"
Cohesion: 0.12
Nodes (14): Illuminate\Database\ConnectionInterface, Illuminate\Database\DeadlockException, DatabaseIdempotencyReceipts, RetryingAtomicTransaction, ProjectionGateState, ProjectionGate, Modules\Payments\Application\Initiation\AtomicTransaction, Modules\Payments\Application\Initiation\IdempotencyReceipts (+6 more)

### Community 126 - "Modules\Payments\Application\Query\PaymentQuery"
Cohesion: 0.27
Nodes (5): ProblemType, ProblemDetails, Illuminate\Http\JsonResponse, QueryPaymentController, Modules\Payments\Application\Query\PaymentQuery

### Community 127 - "TestCase"
Cohesion: 0.40
Nodes (3): Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase, TestCase

### Community 128 - "PaymentProjectionConsumerTest.php"
Cohesion: 0.17
Nodes (5): Modules\Payments\Infrastructure\Projection\ProjectionOutcome, ArrayObject, projectionCapturedLogs(), projectionEnvelope(), projectionLogContexts()

### Community 129 - "0006 - Authenticate merchants with Passport OAuth 2.0 behind a Next.js BFF"
Cohesion: 0.22
Nodes (9): 0006 - Authenticate merchants with Passport OAuth 2.0 behind a Next.js BFF, Alternatives considered, Consequences, Context, Decision, Related, Why a confidential client with PKCE rather than a public one, Why a dedicated `merchant_web` guard, and what it does not buy (+1 more)

### Community 132 - "0007 - Hold merchant OAuth tokens in a server-side BFF session behind an opaque cookie"
Cohesion: 0.22
Nodes (9): 0007 - Hold merchant OAuth tokens in a server-side BFF session behind an opaque cookie, Alternatives considered, Consequences, Context, Decision, Related, What of ADR 0006 is superseded, and what stands, Why a crash after rotation is session loss, not self-healing (+1 more)

### Community 133 - "ProjectionFailureRecorderTest.php"
Cohesion: 0.36
Nodes (5): ArrayObject, recorderCapturedLogs(), recorderEnvelope(), recorderLogContexts(), recorderLogsOfCategory()

### Community 134 - "PassportFoundationTest.php"
Cohesion: 0.29
Nodes (5): Carbon\CarbonInterval, Illuminate\Auth\SessionGuard, Laravel\Passport\Guards\TokenGuard, Laravel\Passport\PassportServiceProvider, preflightFromBrowserOrigin()

### Community 135 - "oauth.php"
Cohesion: 0.33
Nodes (3): Laravel\Passport\Exceptions\OAuthServerException, Laravel\Passport\Http\Controllers\AccessTokenController, RequireProofKeyForCodeExchange

### Community 137 - "BFF state in Redis"
Cohesion: 0.50
Nodes (4): BFF state in Redis, Identifiers are bearer secrets, OAuth transaction record, Session record

### Community 139 - "Origins"
Cohesion: 0.67
Nodes (3): Origins, What production requires, What the local origins mean

## Knowledge Gaps
- **198 isolated node(s):** `name`, `description`, `Modules\\Merchant\\`, `Modules\\Merchant\\Database\\Factories\\`, `Modules\\Merchant\\Database\\Seeders\\` (+193 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **20 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Merchant` connect `Merchant` to `PaymentProjectionConsumerTest.php`, `Laravel\Passport\Passport`, `User`, `Illuminate\Support\Facades\DB`, `ProjectionFailureRecorderTest.php`, `PaymentInitiationPersistenceTest.php`, `PaymentQueryTest.php`, `RebuildConfiguration`, `Authorization`, `Illuminate\Support\Facades\Artisan`, `PaymentsSchemaTest.php`, `Modules\Merchant\Application\Identity\MerchantContext`, `PaymentProjectionVerifierTest.php`, `PaymentOutboxWriter`, `ProjectionRebuildTest.php`, `Illuminate\Support\Str`, `OpenApiContractTest.php`?**
  _High betweenness centrality (0.033) - this node is a cross-community bridge._
- **Why does `ProjectionGate` connect `ProjectionGate` to `PaymentProjectionConsumerTest.php`, `Illuminate\Support\Facades\DB`, `OutboxRelayTest.php`, `DatabasePaymentQuery.php`, `PaymentQueryTest.php`, `IntegrationDatabase`, `RelayConfiguration`, `RelayPaymentOutboxCommandTest.php`, `PaymentProjectionVerifierTest.php`, `Psr\Log\LoggerInterface`, `PaymentProjectionConsumer`, `ProjectionRebuildTest.php`, `Throwable`?**
  _High betweenness centrality (0.030) - this node is a cross-community bridge._
- **Why does `PaymentInitiated` connect `PaymentInitiated` to `ModuleBoundaryTest.php`, `Illuminate\Support\Facades\DB`, `PaymentInitiationContext`, `PaymentInitiationPersistenceTest.php`, `InvalidPaymentInitiation`, `PaymentProjectionVerifierTest.php`, `PaymentOutboxWriter`, `Psr\Log\LoggerInterface`, `ProjectionRebuildTest.php`, `PaymentInitiatedEnvelope`, `ProjectionGate`, `Spatie\EventSourcing\EventHandlers\Projectors\Projector`?**
  _High betweenness centrality (0.024) - this node is a cross-community bridge._
- **What connects `name`, `description`, `Modules\\Merchant\\` to the rest of the system?**
  _198 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Illuminate\Support\Facades\Schema` be split into smaller, more focused modules?**
  _Cohesion score 0.05023796932839767 - nodes in this community are weakly interconnected._
- **Should `RecordingConnection` be split into smaller, more focused modules?**
  _Cohesion score 0.14 - nodes in this community are weakly interconnected._
- **Should `RelayTransport` be split into smaller, more focused modules?**
  _Cohesion score 0.07057057057057058 - nodes in this community are weakly interconnected._