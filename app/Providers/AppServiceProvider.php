<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonInterval;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Override;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    private const array DESTRUCTIVE_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
        'db:wipe',
    ];

    #[Override]
    public function register(): void
    {
        // register(), not boot(): package providers boot first, and Passport registers its routes while booting.
        Passport::ignoreRoutes();
        Passport::$deviceCodeGrantEnabled = false;

        // Laravel merges its own auth.guards defaults into config/auth.php, so its `web` guard
        // survives deleting it there. Merchant sessions belong to merchant_web alone (ADR 0006, D-9).
        /** @var array<string, array<string, string>> $guards */
        $guards = config('auth.guards', []);

        config(['auth.guards' => Arr::only($guards, ['api', 'merchant_web'])]);

        Passport::tokensExpireIn(CarbonInterval::minutes(15));
        Passport::refreshTokensExpireIn(CarbonInterval::days(14));
        Passport::personalAccessTokensExpireIn(CarbonInterval::hours(1));
    }

    public function boot(): void
    {
        $this->prohibitDestructiveCommandsOutsideTestDatabase();
    }

    private function prohibitDestructiveCommandsOutsideTestDatabase(): void
    {
        // Laravel's prohibition is one global flag checked before --database is read, so it
        // stays on by default and is lifted only for one invocation whose bound target is known.
        DB::prohibitDestructiveCommands();

        Event::listen(function (CommandStarting $event): void {
            DB::prohibitDestructiveCommands(! $this->targetsTestDatabase($event));
        });

        Event::listen(function (CommandFinished $event): void {
            DB::prohibitDestructiveCommands();
        });

        // Laravel does not bridge command events while running unit tests; without the
        // bridge no invocation could be recognised and RefreshDatabase would be refused.
        if ($this->app->runningInConsole()) {
            $this->app->make(ConsoleKernel::class)->rerouteSymfonyCommandEvents();
        }
    }

    private function targetsTestDatabase(CommandStarting $event): bool
    {
        if (! in_array($event->command, self::DESTRUCTIVE_COMMANDS, true)
            || ! $this->app->environment('testing')
            || config('database.default') !== 'pgsql') {
            return false;
        }

        try {
            $requested = $event->input->getOption('database');
            $resolved = DB::connection('pgsql');
        } catch (Throwable) {
            return false;
        }

        $configured = config('database.connections.pgsql');

        return ($requested === null || $requested === 'pgsql')
            && is_array($configured)
            && in_array($configured['url'] ?? null, [null, ''], true)
            && ! isset($configured['read'])
            && ! isset($configured['write'])
            && ! $resolved->hasDirectConnection()
            && self::isTestDatabase($configured)
            && self::isTestDatabase($resolved->getConfig());
    }

    private static function isTestDatabase(mixed $config): bool
    {
        return is_array($config)
            && ($config['driver'] ?? null) === 'pgsql'
            && ($config['host'] ?? null) === 'postgres'
            && ($config['database'] ?? null) === 'ledgerflow_test';
    }
}
