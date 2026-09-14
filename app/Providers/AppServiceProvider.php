<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
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

    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
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
