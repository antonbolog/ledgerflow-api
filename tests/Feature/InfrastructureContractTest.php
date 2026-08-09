<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/*
|--------------------------------------------------------------------------
| Infrastructure contract
|--------------------------------------------------------------------------
|
| Layer 3 of the test-database guard, and the only place the runtime contract
| established by T1 is asserted rather than assumed.
|
| These check the LIVE connection, not configuration. config('database.*') can
| report one thing while the connection resolves somewhere else entirely — via
| DB_URL, a cached config, or a driver default — so every assertion here is
| made against what the database and cache servers actually answer.
|
*/

it('runs against the allowlisted PostgreSQL test database', function (): void {
    // current_database() is answered by the server, so it cannot be faked by
    // configuration. getDatabaseName() would only echo back what Laravel was
    // told, which is the thing under suspicion.
    $database = DB::selectOne('select current_database() as name')->name;

    expect($database)->toBe('ledgerflow_test');
});

it('uses the PostgreSQL driver and not SQLite', function (): void {
    // docs/testing/payments-resilience.md: SQLite cannot reproduce the
    // concurrency and advisory-lock behaviour the Payments slice depends on.
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('reaches PostgreSQL on a supported server version', function (): void {
    $version = DB::selectOne('show server_version')->server_version;

    expect((int) $version)->toBeGreaterThanOrEqual(18);
});

it('reaches Redis through the application configuration', function (): void {
    // The compose health check proves the Redis server is alive. This proves
    // the phpredis extension, host and credentials work from inside the app,
    // which is a different claim and the one that breaks silently.
    expect(Redis::connection()->ping())->not->toBeFalse();
});

it('serves the health endpoint through the application', function (): void {
    $this->get('/up')->assertOk();
});
