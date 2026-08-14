<?php

declare(strict_types=1);

namespace Modules\Payments\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Deliberately minimal, and still empty of behaviour.
 *
 * The module now has domain code and schema, but neither needs wiring here:
 * PaymentAggregate and PaymentInitiated are resolved by Spatie through the
 * container's default rules, and the event class map lives in
 * config/event-sourcing.php.
 *
 * This is where bindings land when they are needed. The first is likely T6's
 * synchronous outbox writer, which MUST be registered explicitly —
 * `auto_discover_projectors_and_reactors` defaults to `[app()->path()]`, which
 * is `app/` and never `Modules/`, so a projector living in this module is
 * invisible to discovery and the only symptom is an empty outbox.
 *
 * There is no loadMigrationsFrom() call. `modules.auto-discover.migrations` is
 * true, and LaravelModulesServiceProvider::registerMigrations() already walks
 * every enabled module and registers its migration path on the Migrator.
 */
class PaymentsServiceProvider extends ServiceProvider
{
    //
}
