<?php

declare(strict_types=1);

namespace Modules\Payments\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Deliberately minimal, and currently empty of behaviour.
 *
 * Payments has no domain code in this task. This provider exists because
 * module.json names it as the module's provider, so it is where bindings land
 * when the event-sourced slice arrives.
 *
 * There is no loadMigrationsFrom() call. `modules.auto-discover.migrations` is
 * true, and LaravelModulesServiceProvider::registerMigrations() already walks
 * every enabled module and registers its migration path on the Migrator.
 */
class PaymentsServiceProvider extends ServiceProvider
{
    //
}
