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
use Modules\Payments\Infrastructure\Console\PaymentOutboxStatusCommand;
use Modules\Payments\Infrastructure\Console\RelayPaymentOutboxCommand;
use Modules\Payments\Infrastructure\Persistence\DatabaseIdempotencyReceipts;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Infrastructure\Projection\PaymentProjectionConsumer;
use Modules\Payments\Infrastructure\Projection\ProjectionFailureRecorder;
use Modules\Payments\Infrastructure\Projectors\PaymentOutboxWriter;
use Modules\Payments\Infrastructure\Relay\OutboxRelay;
use Modules\Payments\Infrastructure\Relay\RelayConfiguration;
use Modules\Payments\Infrastructure\Relay\RelayHeartbeat;
use Override;
use Psr\Log\LoggerInterface;
use Spatie\EventSourcing\Projectionist;

/**
 * The Payments module's registration point, named by module.json.
 *
 * It carries exactly two things: the container bindings that let the framework-free
 * Application layer reach PostgreSQL, and the projector registration without which
 * the outbox is silently never written.
 *
 * There is no loadMigrationsFrom() call. `modules.auto-discover.migrations` is true,
 * and LaravelModulesServiceProvider::registerMigrations() already walks every
 * enabled module and registers its migration path on the Migrator.
 */
class PaymentsServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        // scoped, not singleton: a request-lifetime binding the container clears
        // between requests and queued jobs. Both adapters hold a Connection, and a
        // singleton would outlive a connection swap.
        //
        // ConnectionInterface is a container alias of db.connection, so both resolve
        // the SAME default Connection object that the transaction is opened on.
        $this->app->scoped(
            AtomicTransaction::class,
            fn (Application $app): RetryingAtomicTransaction => new RetryingAtomicTransaction(
                $app->make(ConnectionInterface::class),
            ),
        );

        $this->app->scoped(
            IdempotencyReceipts::class,
            fn (Application $app): DatabaseIdempotencyReceipts => new DatabaseIdempotencyReceipts(
                $app->make(ConnectionInterface::class),
            ),
        );

        // VALIDATED AT RESOLUTION, WHICH IS BEFORE ANY ROW IS TOUCHED. Every relay
        // value is either a duration that ends up inside a PostgreSQL interval or a
        // limit that bounds a loop, and PostgreSQL accepts a negative interval
        // without complaint. The transport bounds come from database.redis.payments
        // because that is the array Laravel's PhpRedisConnector actually reads.
        $this->app->scoped(
            RelayConfiguration::class,
            fn (): RelayConfiguration => RelayConfiguration::fromArray(
                (array) config('payments.relay'),
                (array) config('database.redis.payments'),
            ),
        );

        // THE CONCRETE Connection, NOT ConnectionInterface. The relay owns its
        // transaction lifecycle explicitly — beginTransaction, commit, rollBack —
        // and inspects transactionLevel(), none of which ConnectionInterface
        // promises in the shape needed here.
        //
        // DatabaseManager::connection() with no name returns the DEFAULT connection,
        // cached per name, so this is the SAME object DatabaseIdempotencyReceipts
        // and PaymentOutboxWriter already hold. That identity is the relay's whole
        // safety story: the row lock, the publication and the update must run on one
        // backend, or FOR UPDATE protects nothing the UPDATE then changes.
        $this->app->scoped(
            OutboxRelay::class,
            fn (Application $app): OutboxRelay => new OutboxRelay(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(QueueFactory::class),
                $app->make(RelayConfiguration::class),
                $app->make(LoggerInterface::class),
            ),
        );

        // THE SAME Connection OBJECT AS THE CONSUMER, and that is required rather
        // than convenient: the recorder's transaction opens only after the
        // consumer's has rolled back, on the connection that rolled it back. A
        // separate connection would be a second backend whose metadata transaction
        // could overlap the projection transaction being discarded.
        $this->app->scoped(
            ProjectionFailureRecorder::class,
            fn (Application $app): ProjectionFailureRecorder => new ProjectionFailureRecorder(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(LoggerInterface::class),
            ),
        );

        // THE CONCRETE Connection, for the same reason the relay takes it: the
        // consumer owns its transaction lifecycle explicitly and inspects
        // transactionLevel(), neither of which ConnectionInterface promises in the
        // shape needed here.
        //
        // THE SAME DEFAULT CONNECTION OBJECT the relay and the write path hold.
        // That identity is the consumer's safety story exactly as it is the relay's:
        // the envelope lock, the receipt insert, the read-row insert and the marker
        // update must run on ONE backend, or FOR UPDATE protects nothing the later
        // statements then change.
        $this->app->scoped(
            PaymentProjectionConsumer::class,
            fn (Application $app): PaymentProjectionConsumer => new PaymentProjectionConsumer(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(ProjectionFailureRecorder::class),
                $app->make(LoggerInterface::class),
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

        // BOUND EXPLICITLY BECAUSE OF THE CLOSURE. The command takes a monotonic
        // clock so the start budget can be proved without a pass that genuinely
        // takes 750 ms, and a Closure is the one constructor parameter the
        // container cannot autowire.
        //
        // hrtime(), not microtime(): the budget must survive an NTP step or a
        // daylight-saving jump. A wall clock going backwards mid-pass would make the
        // elapsed time negative and the budget effectively infinite.
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

        // THE CONCRETE Connection, for the same reason OutboxRelay takes it: the
        // status query's own read-only transaction inspects transactionLevel(),
        // which ConnectionInterface does not promise in the shape needed here.
        $this->app->scoped(
            PaymentOutboxStatusCommand::class,
            fn (Application $app): PaymentOutboxStatusCommand => new PaymentOutboxStatusCommand(
                $app->make(RelayHeartbeat::class),
                $app->make(DatabaseManager::class)->connection(),
                $app->make(RelayConfiguration::class),
            ),
        );
    }

    public function boot(): void
    {
        // EXPLICIT, AND NOT OPTIONAL. Spatie's
        // auto_discover_projectors_and_reactors defaults to [app()->path()], which is
        // app/ and never Modules/, so a projector in this module is invisible to
        // discovery. The only symptom of forgetting this line is an empty
        // payment_outbox — no error, no warning.
        //
        // Registered BY CLASS NAME so Projectionist resolves it through the container
        // and its ConnectionInterface is injected. EventHandlerCollection keys
        // handlers by class name, so registering twice cannot make it run twice.
        $this->app->make(Projectionist::class)->addProjector(PaymentOutboxWriter::class);

        // Registered by CLASS NAME, so Artisan resolves each command through the
        // container and the bindings above apply. nwidart/laravel-modules discovers
        // providers, not commands, so without this line `payments:outbox:relay`
        // simply does not exist and the schedule entry silently resolves nothing.
        if ($this->app->runningInConsole()) {
            $this->commands([
                RelayPaymentOutboxCommand::class,
                PaymentOutboxStatusCommand::class,
            ]);
        }
    }
}
