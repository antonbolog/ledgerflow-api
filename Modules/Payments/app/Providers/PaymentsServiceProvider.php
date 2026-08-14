<?php

declare(strict_types=1);

namespace Modules\Payments\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use Modules\Payments\Application\Initiation\AtomicTransaction;
use Modules\Payments\Application\Initiation\IdempotencyReceipts;
use Modules\Payments\Infrastructure\Persistence\DatabaseIdempotencyReceipts;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Infrastructure\Projectors\PaymentOutboxWriter;
use Override;
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
    }
}
