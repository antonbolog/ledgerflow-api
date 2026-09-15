<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;

it('runs against the allowlisted PostgreSQL test database', function (): void {
    // Answered by the server: getDatabaseName() would only echo the configuration under suspicion.
    $database = DB::selectOne('select current_database() as name')->name;

    expect($database)->toBe('ledgerflow_test');
});

it('boots the suite in the testing environment', function (): void {
    expect(app()->environment())->toBe('testing');
});

it('reaches PostgreSQL on a supported server version', function (): void {
    $version = DB::selectOne('show server_version')->server_version;

    expect((int) $version)->toBeGreaterThanOrEqual(18);
});

it('reaches Redis through the application configuration', function (): void {
    expect(Redis::connection()->ping())->not->toBeFalse();
});

it('serves the health endpoint through the application', function (): void {
    $this->get('/up')->assertOk();
});

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
