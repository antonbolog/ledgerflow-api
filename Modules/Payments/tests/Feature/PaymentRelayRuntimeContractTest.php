<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Modules\Payments\Infrastructure\Relay\ProjectPaymentEvent;
use Modules\Payments\Infrastructure\Relay\RelayConfiguration;
use Modules\Payments\Tests\Support\RelayTransport;
use Redis as PhpRedis;

/*
|--------------------------------------------------------------------------
| The resolved Payments relay transport
|--------------------------------------------------------------------------
|
| ASSERTED ON THE LIVE CLIENT, NOT ON config(). config('database.redis.payments')
| can say one thing while the resolved connection selected another database,
| applied another prefix, or kept the inherited retries — through a cached client,
| a merge order, or an env value nobody expected. Every question below is answered
| by the phpredis object or by the Redis server itself, and the file finishes with
| a real push() so a configuration that reads correctly but cannot enqueue still
| fails.
|
| The suite reaches this connection BY NAME. tests/bootstrap.php keeps the default
| queue on `sync` and says so explicitly: "A suite that reaches Redis explicitly and
| by name is fine. A suite that reaches it through a framework default nobody chose
| is not." Only the logical database is overridden, to 15.
|
*/

beforeEach(function (): void {
    RelayTransport::configure();

    // The override took, read from the server. Every deletion in teardown is gated
    // on this same answer.
    expect(RelayTransport::databaseNumber())->toBe(RelayTransport::DATABASE);

    // BOTH SPELLINGS. Guarding only the unprefixed names was vacuous: on this
    // connection nothing is ever stored under them, so the guard passed while a
    // leaked prefixed key would have seeded the next test's count.
    expect(RelayTransport::existingKeys())->toBe([]);
    expect(RelayTransport::existingKeys(RelayTransport::clientPrefix()))->toBe([]);
});

afterEach(function (): void {
    RelayTransport::teardown();
});

it('keeps the default queue on sync while the relay connection is a named Redis queue', function (): void {
    // The isolation contract T4 repaired must survive T7 adding a Redis queue.
    expect(config('queue.default'))->toBe('sync')
        ->and(Queue::connection())->toBeInstanceOf(SyncQueue::class);

    $relay = Queue::connection(RelayTransport::QUEUE_CONNECTION);

    expect($relay)->toBeInstanceOf(RedisQueue::class)
        ->and($relay->getConnectionName())->toBe(RelayTransport::QUEUE_CONNECTION);
});

it('never defers a relay publication past commit', function (): void {
    // Necessary but NOT sufficient: Queue::shouldDispatchAfterCommit() reads the job
    // before this value (Queue/Queue.php:396-408). ProjectPaymentEventTest and the
    // source rule in tests/Architecture/ModuleBoundaryTest.php own the other half.
    expect(config('queue.connections.payments-projection.after_commit'))->toBeFalse();

    // The literal identity the runtime contract and T8's Horizon supervision both
    // name. A drift here publishes where nothing will consume.
    expect(config('queue.connections.payments-projection.queue'))->toBe('payments-projection')
        ->and(config('queue.connections.payments-projection.connection'))->toBe('payments');
});

it('bounds the live relay client rather than inheriting the shared connection settings', function (): void {
    $client = RelayTransport::client();

    // Publication happens inside an open PostgreSQL transaction holding a row lock,
    // so each of these is a database-occupancy bound, read from the client that
    // will actually perform the push.
    expect((float) $client->getTimeout())->toBe(0.25)
        ->and((float) $client->getReadTimeout())->toBe(0.25);

    // ZERO RETRIES, read from the client. phpredis would otherwise retry inside the
    // locked transaction, multiplying the hold time by a factor the relay cannot
    // see. The single retry owner is the durable backoff written to available_at.
    expect((int) $client->getOption(PhpRedis::OPT_MAX_RETRIES))->toBe(0);
});

it('addresses one physical key from both read paths, and records the prefix phpredis applies', function (): void {
    // M-1, MEASURED HERE AND NOT ASSUMED. RedisQueue::pushRaw() writes its two keys
    // as arguments to a Lua eval (RedisQueue.php:323-335) and size() reads through
    // another eval, while pendingSize() reads with an ordinary llen
    // (RedisQueue.php:110-131). phpredis applies OPT_PREFIX to ordinary commands, so
    // if it did NOT apply it to eval key arguments the writer and the reader would
    // address different keys — and an operator running `redis-cli llen` would see an
    // empty queue while messages piled up.
    //
    // RESULT: they agree. phpredis prefixes eval key arguments too, so the inherited
    // database.redis.options.prefix is safe and this connection pins no prefix of
    // its own.
    //
    // WHAT T8 CONFIGURES IS THE LOGICAL PAIR, NOT ANY OF THIS. Horizon supervises
    // the logical queue connection `payments-projection` and the logical queue name
    // `payments-projection` — the same two names asserted above. A physical Redis
    // key is never Horizon configuration; it is the storage detail Laravel derives
    // from those names plus whatever prefix the connection carries.
    //
    // The physical assertions below are therefore EVIDENCE ABOUT PHPREDIS PREFIX
    // BEHAVIOUR — they record that the prefix is applied, and that T4's bare
    // `queues:<name>` reading came from a spike connection pinning an empty prefix.
    // They exist so a `redis-cli` investigation starts from the right key, and so
    // this suite's own targeted cleanup deletes what actually exists.
    $prefix = RelayTransport::clientPrefix();

    expect($prefix)->not->toBe('');

    Queue::connection(RelayTransport::QUEUE_CONNECTION)->push(
        new ProjectPaymentEvent('01984f6f-6a20-7000-8000-000000000001'),
        '',
        RelayTransport::QUEUE,
    );

    expect(RelayTransport::size())->toBe(1)
        ->and(RelayTransport::pendingSize())->toBe(1);

    // Read with the prefix cleared on the client, so these are the bytes Redis
    // actually holds rather than a second application of the same assumption.
    expect(RelayTransport::existingKeys($prefix))->toBe([
        'queues:payments-projection',
        'queues:payments-projection:notify',
    ]);

    // And nothing under the bare names, which is the reading that would mislead.
    expect(RelayTransport::existingKeys())->toBe([]);
});

it('pins the heartbeat store to the same bounded connection and leaves the default cache alone', function (): void {
    $store = Cache::store('payments-relay')->getStore();

    expect($store)->toBeInstanceOf(RedisStore::class)
        // The bounded `payments` connection, not the env-driven default: a heartbeat
        // write that retried or blocked would delay the pass it describes.
        ->and($store->connection()->getName())->toBe(RelayTransport::REDIS_CONNECTION)
        // A STABLE STORE NAMESPACE, and that is the whole claim. Pinning the store's
        // own prefix keeps relay keys in one addressable group that does not move
        // when the env-derived cache.prefix changes.
        //
        // IT IS NOT A CLAIM THAT THE PHYSICAL KEY IS INDEPENDENT OF THE
        // ENVIRONMENT. The phpredis connection still applies
        // database.redis.options.prefix — itself derived from REDIS_PREFIX, which
        // defaults to a slug of APP_NAME — so the physical key is
        // <redis client prefix> . 'payments-relay:' . <key> and DOES move with those
        // two variables. Only the `payments-relay:` segment is stable. Slice 3 owns
        // the operator-facing key and must quote the full physical string, prefix
        // included.
        ->and($store->getPrefix())->toBe('payments-relay:');

    // The suite's default cache is untouched by any of this.
    expect(config('cache.default'))->toBe('array')
        ->and(Cache::store()->getStore())->toBeInstanceOf(ArrayStore::class);
});

it('reads the shipped relay and transport configuration into a valid typed instance', function (): void {
    // The shipped defaults must satisfy their own validation. Without this, an
    // invalid config/payments.php or an unusable transport timeout would only be
    // discovered by the first command that resolved it — which, until Slice 3, is
    // nothing.
    $config = RelayConfiguration::fromArray(
        (array) config('payments.relay'),
        (array) config('database.redis.payments'),
    );

    expect($config->queueConnection)->toBe(RelayTransport::QUEUE_CONNECTION)
        ->and($config->queue)->toBe(RelayTransport::QUEUE)
        ->and($config->cacheStore)->toBe('payments-relay')
        ->and($config->reconciliationGraceSeconds)->toBe(30)
        ->and($config->backoff()->secondsAfter(1))->toBe(1);

    // The validated values and the live client agree. Either alone would be a
    // half-proof: config can describe a client that was built from something else.
    $client = RelayTransport::client();

    expect((float) $client->getTimeout())->toBe($config->redisTimeout)
        ->and((float) $client->getReadTimeout())->toBe($config->redisReadTimeout);
});

it('keeps a shared REDIS_URL from reaching past the relay connection literals', function (): void {
    // RedisManager::parseConnectionConfiguration() runs every connection through
    // ConfigurationUrlParser::parseConfiguration(), which returns
    // array_merge($config, $primaryOptions, $queryOptions): the URL's path overrides
    // `database`, and ANY query-string key overrides ANY configured value.
    //
    // THE PROTECTION IS THE ABSENCE OF THE KEY. With no `url`, parseConfiguration()
    // returns the configuration untouched, so no single environment variable can
    // reach the four values this connection exists to pin.
    $shipped = (array) config('database.redis.payments');

    expect($shipped)->not->toHaveKey('url');

    // THE CONTROL, so the assertion above is not a statement about a hazard that
    // does not exist. The real parser, the real merge order: one shared URL defeats
    // the logical database, the retry literal and both timeouts at once.
    $defeated = new ConfigurationUrlParser()->parseConfiguration(array_merge($shipped, [
        'url' => 'redis://cache-host:6379/0?max_retries=3&timeout=30&read_timeout=30',
    ]));

    expect($defeated['database'])->toBe('0')
        ->and($defeated['max_retries'])->toBe(3)
        ->and($defeated['timeout'])->toBe(30)
        ->and($defeated['read_timeout'])->toBe(30)
        ->and($defeated['host'])->toBe('cache-host');

    // And the shipped values it would have replaced.
    expect($shipped['max_retries'])->toBe(0)
        ->and((float) $shipped['timeout'])->toBe(0.25)
        ->and((float) $shipped['read_timeout'])->toBe(0.25);
});

it('schedules the relay command every second, with no scheduler-uniqueness dependency', function (): void {
    // routes/console.php is loaded during normal application boot, so this
    // reads the SAME registration `schedule:work` would act on — not a copy
    // built for the test.
    $events = Collection::make(resolve(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'payments:outbox:relay'));

    expect($events)->toHaveCount(1);

    $event = $events->first();

    // everySecond() compiles to an ordinary every-minute cron expression plus a
    // one-second repeat flag; schedule:work is what actually re-checks it every
    // second, not the cron expression by itself.
    expect($event->getExpression())->toBe('* * * * *')
        ->and($event->isRepeatable())->toBeTrue()
        ->and($event->repeatSeconds)->toBe(1)
        // NO withoutOverlapping()/onOneServer(). SKIP LOCKED is the relay's own
        // concurrency safety — proved against real PostgreSQL in
        // Integration/OutboxRelayTest.php — and a scheduler-level mutex must
        // never become an unstated second dependency for the same guarantee.
        ->and($event->withoutOverlapping)->toBeFalse();
});
