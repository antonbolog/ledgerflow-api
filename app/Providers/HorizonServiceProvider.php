<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

final class HorizonServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Evaluated per request: Horizon::auth() stores this closure statically.
        Horizon::auth(fn (Request $request): bool => $this->dashboardIsOpen());
    }

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
