<?php

declare(strict_types=1);

namespace Modules\Payments\Providers;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Modules\Payments\Application\Initiation\AtomicTransaction;
use Modules\Payments\Application\Initiation\IdempotencyReceipts;
use Modules\Payments\Application\Query\PaymentQuery;
use Modules\Payments\Application\Rebuild\ProjectionRebuild;
use Modules\Payments\Application\Repair\OutboxRepair;
use Modules\Payments\Application\Verification\ProjectionVerifier;
use Modules\Payments\Infrastructure\Console\PaymentOutboxRetryCommand;
use Modules\Payments\Infrastructure\Console\PaymentOutboxStatusCommand;
use Modules\Payments\Infrastructure\Console\RebuildPaymentProjectionsCommand;
use Modules\Payments\Infrastructure\Console\RelayPaymentOutboxCommand;
use Modules\Payments\Infrastructure\Console\RepairPaymentOutboxCommand;
use Modules\Payments\Infrastructure\Console\VerifyPaymentProjectionsCommand;
use Modules\Payments\Infrastructure\Persistence\DatabaseIdempotencyReceipts;
use Modules\Payments\Infrastructure\Persistence\DatabasePaymentQuery;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Infrastructure\Projection\PaymentProjectionConsumer;
use Modules\Payments\Infrastructure\Projection\ProjectionConfiguration;
use Modules\Payments\Infrastructure\Projection\ProjectionFailureRecorder;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Modules\Payments\Infrastructure\Projectors\PaymentOutboxWriter;
use Modules\Payments\Infrastructure\Rebuild\DatabaseProjectionRebuild;
use Modules\Payments\Infrastructure\Rebuild\RebuildConfiguration;
use Modules\Payments\Infrastructure\Relay\OutboxRelay;
use Modules\Payments\Infrastructure\Relay\RelayConfiguration;
use Modules\Payments\Infrastructure\Relay\RelayHeartbeat;
use Modules\Payments\Infrastructure\Repair\DatabaseOutboxRepair;
use Modules\Payments\Infrastructure\Verification\DatabaseProjectionVerifier;
use Modules\Payments\Infrastructure\Verification\VerificationConfiguration;
use Override;
use Psr\Log\LoggerInterface;
use Spatie\EventSourcing\Projectionist;

class PaymentsServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        // Every binding resolves the same default Connection object, so locks, the gate and writes share one backend.
        $this->app->scoped(
            ProjectionGate::class,
            fn (Application $app): ProjectionGate => new ProjectionGate(
                $app->make(ConnectionInterface::class),
            ),
        );

        $this->app->scoped(
            AtomicTransaction::class,
            fn (Application $app): RetryingAtomicTransaction => new RetryingAtomicTransaction(
                $app->make(ConnectionInterface::class),
                $app->make(ProjectionGate::class),
            ),
        );

        $this->app->scoped(
            IdempotencyReceipts::class,
            fn (Application $app): DatabaseIdempotencyReceipts => new DatabaseIdempotencyReceipts(
                $app->make(ConnectionInterface::class),
            ),
        );

        $this->app->scoped(
            PaymentQuery::class,
            fn (Application $app): DatabasePaymentQuery => new DatabasePaymentQuery(
                $app->make(ConnectionInterface::class),
                $app->make(ProjectionGate::class),
            ),
        );

        $this->app->scoped(
            RelayConfiguration::class,
            fn (): RelayConfiguration => RelayConfiguration::fromArray(
                (array) config('payments.relay'),
                (array) config('database.redis.payments'),
            ),
        );

        $this->app->scoped(
            OutboxRelay::class,
            fn (Application $app): OutboxRelay => new OutboxRelay(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(QueueFactory::class),
                $app->make(RelayConfiguration::class),
                $app->make(LoggerInterface::class),
                $app->make(ProjectionGate::class),
            ),
        );

        $this->app->scoped(
            ProjectionFailureRecorder::class,
            fn (Application $app): ProjectionFailureRecorder => new ProjectionFailureRecorder(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(LoggerInterface::class),
            ),
        );

        $this->app->scoped(
            ProjectionConfiguration::class,
            fn (): ProjectionConfiguration => ProjectionConfiguration::fromArray(
                (array) config('payments.projection'),
                (array) config('queue.connections.payments-projection'),
            ),
        );

        $this->app->scoped(
            PaymentProjectionConsumer::class,
            fn (Application $app): PaymentProjectionConsumer => new PaymentProjectionConsumer(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(ProjectionConfiguration::class),
                $app->make(ProjectionFailureRecorder::class),
                $app->make(LoggerInterface::class),
                $app->make(ProjectionGate::class),
            ),
        );

        $this->app->scoped(
            RelayHeartbeat::class,
            fn (Application $app): RelayHeartbeat => new RelayHeartbeat(
                $app->make(CacheFactory::class),
                $app->make(RelayConfiguration::class),
                $app->make(LoggerInterface::class),
            ),
        );

        $this->app->scoped(
            RelayPaymentOutboxCommand::class,
            fn (Application $app): RelayPaymentOutboxCommand => new RelayPaymentOutboxCommand(
                $app->make(OutboxRelay::class),
                $app->make(RelayConfiguration::class),
                $app->make(RelayHeartbeat::class),
                $app->make(LoggerInterface::class),
                static fn (): int => (int) hrtime(true),
            ),
        );

        $this->app->scoped(
            PaymentOutboxRetryCommand::class,
            fn (Application $app): PaymentOutboxRetryCommand => new PaymentOutboxRetryCommand(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(LoggerInterface::class),
            ),
        );

        $this->app->scoped(
            PaymentOutboxStatusCommand::class,
            fn (Application $app): PaymentOutboxStatusCommand => new PaymentOutboxStatusCommand(
                $app->make(RelayHeartbeat::class),
                $app->make(DatabaseManager::class)->connection(),
                $app->make(RelayConfiguration::class),
                $app->make(ProjectionGate::class),
            ),
        );

        $this->app->scoped(
            VerificationConfiguration::class,
            fn (): VerificationConfiguration => VerificationConfiguration::fromArray(
                (array) config('payments.verification'),
            ),
        );

        $this->app->scoped(
            ProjectionVerifier::class,
            fn (Application $app): DatabaseProjectionVerifier => new DatabaseProjectionVerifier(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(VerificationConfiguration::class),
                static fn (): int => (int) hrtime(true),
            ),
        );

        $this->app->scoped(
            VerifyPaymentProjectionsCommand::class,
            fn (Application $app): VerifyPaymentProjectionsCommand => new VerifyPaymentProjectionsCommand(
                static fn (): ProjectionVerifier => $app->make(ProjectionVerifier::class),
                static fn (): int => (int) hrtime(true),
            ),
        );

        $this->app->scoped(
            OutboxRepair::class,
            fn (Application $app): DatabaseOutboxRepair => new DatabaseOutboxRepair(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(LoggerInterface::class),
            ),
        );

        $this->app->scoped(
            RepairPaymentOutboxCommand::class,
            fn (Application $app): RepairPaymentOutboxCommand => new RepairPaymentOutboxCommand(
                $app->make(OutboxRepair::class),
                static fn (): int => (int) hrtime(true),
            ),
        );

        $this->app->scoped(
            RebuildConfiguration::class,
            fn (): RebuildConfiguration => RebuildConfiguration::fromArray(
                (array) config('payments.rebuild'),
            ),
        );

        // The rebuild disables this connection's reconnector for its run; safe only in a dedicated console process.
        $this->app->scoped(
            ProjectionRebuild::class,
            fn (Application $app): DatabaseProjectionRebuild => new DatabaseProjectionRebuild(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(RebuildConfiguration::class),
                $app->make(LoggerInterface::class),
            ),
        );

        $this->app->scoped(
            RebuildPaymentProjectionsCommand::class,
            fn (Application $app): RebuildPaymentProjectionsCommand => new RebuildPaymentProjectionsCommand(
                $app->make(ProjectionRebuild::class),
                static fn (): int => (int) hrtime(true),
            ),
        );
    }

    public function boot(): void
    {
        // Spatie auto-discovery scans app/ only; without this the outbox is silently never written.
        $this->app->make(Projectionist::class)->addProjector(PaymentOutboxWriter::class);

        // nwidart/laravel-modules discovers providers, not commands.
        if ($this->app->runningInConsole()) {
            $this->commands([
                RelayPaymentOutboxCommand::class,
                PaymentOutboxStatusCommand::class,
                PaymentOutboxRetryCommand::class,
                VerifyPaymentProjectionsCommand::class,
                RepairPaymentOutboxCommand::class,
                RebuildPaymentProjectionsCommand::class,
            ]);
        }
    }
}
