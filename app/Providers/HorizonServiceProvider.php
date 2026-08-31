<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

/**
 * The Horizon dashboard gate, written explicitly rather than inherited.
 *
 * IT EXISTS BECAUSE THE PACKAGE HAS NO OFF SWITCH. laravel/horizon v5.48.3
 * registers its routes unconditionally in HorizonServiceProvider::boot(), and the
 * only access control it offers is `Horizon::check()`, which falls back to
 * `app()->environment('local')` when no gate has been set.
 *
 * THAT FALLBACK IS NOT GOOD ENOUGH TO INHERIT SILENTLY. It would leave the
 * dashboard open to anything that could reach the application in local — and the
 * dashboard lists job payloads, which here are payment envelopes, and its controls
 * retry and delete jobs, which for `payments-projection` means replaying a
 * projection outside the outbox's own delivery accounting. An operator's tool, not
 * an ambient one.
 *
 * SO THE GATE REQUIRES BOTH CONDITIONS, and neither is implicit:
 *
 * - the local environment, so a staging or production deploy is closed even if the
 *   flag were set by accident;
 * - an explicit opt-in, so a local checkout is closed until an operator decides
 *   otherwise. `.env.example` ships it false.
 *
 * A `false` from here becomes Horizon's own ForbiddenException, which is a 403 —
 * the dashboard is unreachable rather than merely unlinked.
 *
 * IT DOES NOT REGISTER ROUTES, PUBLISH ASSETS OR NAME QUEUES. Everything else
 * about Horizon is configuration, and config/horizon.php owns it.
 */
final class HorizonServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // READ THROUGH config(), NEVER env(). The architecture baseline forbids
        // env() outside configuration, and for the usual reason: a cached config
        // makes a direct env() read return null, which here would silently close
        // the dashboard for an operator who had enabled it — or, with the boolean
        // the other way round, silently open it.
        Horizon::auth(fn (Request $request): bool => $this->dashboardIsOpen());
    }

    /**
     * Both conditions, evaluated per request rather than cached at boot.
     *
     * Per request because `Horizon::auth()` stores the closure statically and the
     * environment can differ between a test that fakes it and the process that
     * booted; evaluating inside keeps the answer honest in both.
     */
    private function dashboardIsOpen(): bool
    {
        if (config('horizon.dashboard.enabled') !== true) {
            return false;
        }

        $environments = config('horizon.dashboard.environments');

        if (! is_array($environments)) {
            return false;
        }

        $names = [];

        foreach ($environments as $environment) {
            if (is_string($environment)) {
                $names[] = $environment;
            }
        }

        return $names !== [] && $this->app->environment(...$names);
    }
}
