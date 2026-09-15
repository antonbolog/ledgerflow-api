<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Modules\Payments\Infrastructure\Relay\ProjectPaymentEvent;
use Modules\Payments\Infrastructure\Relay\RelayConfiguration;
use Modules\Payments\Tests\Support\RelayTransport;
use Redis as PhpRedis;

beforeEach(function (): void {
    RelayTransport::configure();

    expect(RelayTransport::databaseNumber())->toBe(RelayTransport::DATABASE);

    // Both spellings: this connection stores queue keys prefixed.
    expect(RelayTransport::existingKeys())->toBe([]);
    expect(RelayTransport::existingKeys(RelayTransport::clientPrefix()))->toBe([]);
});

afterEach(function (): void {
    RelayTransport::teardown();
});

it('never defers a relay publication past commit', function (): void {
    expect(config('queue.connections.payments-projection.after_commit'))->toBeFalse();

    expect(config('queue.connections.payments-projection.queue'))->toBe('payments-projection')
        ->and(config('queue.connections.payments-projection.connection'))->toBe('payments');
});

it('bounds the live relay client rather than inheriting the shared connection settings', function (): void {
    $client = RelayTransport::client();

    expect((float) $client->getTimeout())->toBe(0.25)
        ->and((float) $client->getReadTimeout())->toBe(0.25);

    expect((int) $client->getOption(PhpRedis::OPT_MAX_RETRIES))->toBe(0);
});

it('addresses one physical key from both read paths, and records the prefix phpredis applies', function (): void {
    // The push writes through Lua eval while pendingSize() uses llen; both must address the prefixed key.
    $prefix = RelayTransport::clientPrefix();

    expect($prefix)->not->toBe('');

    Queue::connection(RelayTransport::QUEUE_CONNECTION)->push(
        new ProjectPaymentEvent('01984f6f-6a20-7000-8000-000000000001'),
        '',
        RelayTransport::QUEUE,
    );

    expect(RelayTransport::size())->toBe(1)
        ->and(RelayTransport::pendingSize())->toBe(1);

    expect(RelayTransport::existingKeys($prefix))->toBe([
        'queues:payments-projection',
        'queues:payments-projection:notify',
    ]);

    expect(RelayTransport::existingKeys())->toBe([]);
});

it('pins the heartbeat store to the same bounded connection and leaves the default cache alone', function (): void {
    $store = Cache::store('payments-relay')->getStore();

    expect($store)->toBeInstanceOf(RedisStore::class)
        ->and($store->connection()->getName())->toBe(RelayTransport::REDIS_CONNECTION)
        // Only this segment is stable: the physical key still starts with the env-derived Redis client prefix.
        ->and($store->getPrefix())->toBe('payments-relay:');

    expect(config('cache.default'))->toBe('array')
        ->and(Cache::store()->getStore())->toBeInstanceOf(ArrayStore::class);
});

it('reads the shipped relay and transport configuration into a valid typed instance', function (): void {
    $config = RelayConfiguration::fromArray(
        (array) config('payments.relay'),
        (array) config('database.redis.payments'),
    );

    expect($config->queueConnection)->toBe(RelayTransport::QUEUE_CONNECTION)
        ->and($config->queue)->toBe(RelayTransport::QUEUE)
        ->and($config->cacheStore)->toBe('payments-relay')
        ->and($config->reconciliationGraceSeconds)->toBe(30)
        ->and($config->backoff()->secondsAfter(1))->toBe(1);

    $client = RelayTransport::client();

    expect((float) $client->getTimeout())->toBe($config->redisTimeout)
        ->and((float) $client->getReadTimeout())->toBe($config->redisReadTimeout);
});

it('keeps a shared REDIS_URL from reaching past the relay connection literals', function (): void {
    $shipped = (array) config('database.redis.payments');

    expect($shipped)->not->toHaveKey('url');

    $defeated = new ConfigurationUrlParser()->parseConfiguration(array_merge($shipped, [
        'url' => 'redis://cache-host:6379/0?max_retries=3&timeout=30&read_timeout=30',
    ]));

    expect($defeated['database'])->toBe('0')
        ->and($defeated['max_retries'])->toBe(3)
        ->and($defeated['timeout'])->toBe(30)
        ->and($defeated['read_timeout'])->toBe(30)
        ->and($defeated['host'])->toBe('cache-host');

    expect($shipped['max_retries'])->toBe(0)
        ->and((float) $shipped['timeout'])->toBe(0.25)
        ->and((float) $shipped['read_timeout'])->toBe(0.25);
});

it('schedules the relay command every second, with no scheduler-uniqueness dependency', function (): void {
    $events = Collection::make(resolve(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'payments:outbox:relay'));

    expect($events)->toHaveCount(1);

    $event = $events->first();

    // everySecond() is an every-minute cron expression plus a one-second repeat.
    expect($event->getExpression())->toBe('* * * * *')
        ->and($event->isRepeatable())->toBeTrue()
        ->and($event->repeatSeconds)->toBe(1)
        ->and($event->withoutOverlapping)->toBeFalse();
});
