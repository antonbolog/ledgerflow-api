<?php

declare(strict_types=1);

use Illuminate\Database\Console\WipeCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// Refuses SQL before it executes, so a broken guard fails the test instead of dropping ledgerflow_test.
function refuseSqlOnTestDatabase(string ...$connections): void
{
    foreach (['pgsql', ...$connections] as $connection) {
        DB::connection($connection)->beforeExecuting(function (string $query): never {
            throw new RuntimeException('Destructive command reached SQL: '.$query);
        });
    }
}

dataset('destructive commands', [
    'migrate:fresh',
    'migrate:refresh',
    'migrate:reset',
    'migrate:rollback',
    'db:wipe',
]);

it('lets a destructive command reach execution against the recognized test database', function (string $command): void {
    refuseSqlOnTestDatabase();

    expect(fn () => Artisan::call($command, ['--force' => true]))
        ->toThrow(RuntimeException::class, 'Destructive command reached SQL');
})->with('destructive commands');

it('prohibits destructive commands again after a recognized invocation finishes', function (string $outcome): void {
    if ($outcome === 'succeeded') {
        expect(Artisan::call('migrate:rollback', ['--batch' => '999999', '--force' => true]))->toBe(0);
        refuseSqlOnTestDatabase();
    } else {
        refuseSqlOnTestDatabase();
        expect(fn () => Artisan::call('db:wipe', ['--force' => true]))
            ->toThrow(RuntimeException::class, 'Destructive command reached SQL');
    }

    // Run directly, bypassing the console events, so only the flag left behind decides.
    $command = $this->app->make(WipeCommand::class);
    $command->setLaravel($this->app);
    $output = new BufferedOutput;

    expect($command->run(new ArrayInput(['--force' => true]), $output))->toBe(1)
        ->and($output->fetch())->toContain('This command is prohibited from running in this environment.');
})->with(['succeeded', 'failed']);

it('prohibits a destructive command outside the recognized test database', function (string $command, string $environment, array $config, array $options): void {
    $original = array_combine(array_keys($config), array_map(config(...), array_keys($config)));

    // The clone reaches ledgerflow_test under another name, and the application resolves the
    // default connection before any command starts, so an unknown name cannot be probed.
    config(['database.connections.pgsql_guard_clone' => config('database.connections.pgsql')]);
    $this->app['env'] = $environment;
    config($config);
    refuseSqlOnTestDatabase('pgsql_guard_clone');

    try {
        $exitCode = Artisan::call($command, ['--force' => true, ...$options]);
    } finally {
        config($original);
    }

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('This command is prohibited from running in this environment.');
})->with('destructive commands')->with([
    'a local environment' => ['local', [], []],
    'a production environment' => ['production', [], []],
    'another default connection' => ['testing', ['database.default' => 'pgsql_guard_clone'], []],
    'a database URL' => ['testing', ['database.connections.pgsql.url' => 'pgsql://ledgerflow@postgres:5432/ledgerflow_test'], []],
    'another host' => ['testing', ['database.connections.pgsql.host' => 'db.example.test'], []],
    'a separate write host' => ['testing', ['database.connections.pgsql.write' => ['host' => 'db.example.test']], []],
    'another database' => ['testing', ['database.connections.pgsql.database' => 'ledgerflow'], []],
    'another requested connection' => ['testing', [], ['--database' => 'ledgerflow_guard_unknown']],
]);

it('prohibits a destructive command when the resolved connection is not the recognized test database', function (): void {
    $configured = config('database.connections.pgsql');

    DB::connectUsing('pgsql', [...(is_array($configured) ? $configured : []), 'database' => 'ledgerflow'], force: true);
    refuseSqlOnTestDatabase();

    expect(Artisan::call('db:wipe', ['--force' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('This command is prohibited from running in this environment.');
});

it('bootstraps the suite against the recognized test target', function (): void {
    $result = Process::path(base_path())->run([PHP_BINARY, 'tests/bootstrap.php']);

    expect($result->exitCode())->toBe(0)
        ->and($result->errorOutput())->toBe('');
});

it('refuses to bootstrap the suite against an unsafe target', function (array $environment): void {
    $result = Process::path(base_path())->env($environment)->run([PHP_BINARY, 'tests/bootstrap.php']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('REFUSING TO RUN THE TEST SUITE')
        ->and($result->errorOutput())->not->toContain('secret');
})->with([
    'a non-testing environment' => [['APP_ENV' => 'local']],
    'a database URL' => [['DB_URL' => 'pgsql://ledgerflow:secret@postgres:5432/ledgerflow_test']],
    'a loopback host' => [['DB_HOST' => '127.0.0.1']],
    'another connection' => [['DB_CONNECTION' => 'sqlite']],
    'another database' => [['DB_DATABASE' => 'ledgerflow']],
]);
