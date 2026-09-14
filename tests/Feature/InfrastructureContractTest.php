<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;

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

/*
|--------------------------------------------------------------------------
| Test isolation contract
|--------------------------------------------------------------------------
|
| phpunit.xml declares sync/array/array for queue, cache and session so the
| suite cannot couple runs to each other through shared infrastructure. That
| declaration is necessary but was NOT sufficient: PHPUnit writes putenv() and
| $_ENV but never $_SERVER, Compose's env_file puts the Redis-backed
| development values there, and Laravel's env resolver reads $_SERVER first.
|
| Measured during T4: config('queue.default') was 'redis' and the default queue
| resolved to a RedisQueue on logical database 0 — the developer's keyspace.
| An unnamed dispatch would have written straight into it.
|
| tests/bootstrap.php now forces these three the same way it already forced the
| database keys. These tests assert the LIVE resolved objects rather than the
| configuration, for the same reason the database assertions above do: config
| can report one thing while the container resolves something else entirely.
|
*/

it('keeps the default queue connection off shared infrastructure', function (): void {
    expect(config('queue.default'))->toBe('sync');
    expect(Queue::connection())->toBeInstanceOf(SyncQueue::class);
});

it('keeps the default cache store in memory', function (): void {
    expect(config('cache.default'))->toBe('array');
    expect(Cache::store()->getStore())->toBeInstanceOf(ArrayStore::class);
});

it('keeps the session driver in memory', function (): void {
    expect(config('session.driver'))->toBe('array');
    expect(Session::getDefaultDriver())->toBe('array');
});
