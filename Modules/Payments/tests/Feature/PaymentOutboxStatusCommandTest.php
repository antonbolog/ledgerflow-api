<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Payments\Infrastructure\Relay\RelayHeartbeat;
use Modules\Payments\Tests\Support\RelayTransport;

function statusEnvelope(
    string $availableAt = 'now()',
    ?string $projectionCompletedAt = null,
    ?string $exhaustedAt = null,
): string {
    $eventId = (string) Str::uuid7();

    DB::statement(
        <<<SQL
        insert into payment_outbox (
            event_id, event_type, schema_version, aggregate_uuid, aggregate_version,
            payload, occurred_at, available_at, projection_completed_at, exhausted_at,
            created_at, updated_at
        ) values (?, 'payments.payment_initiated', 1, ?, 1, '{}'::jsonb, now(), {$availableAt}, ?, ?, now(), now())
        SQL,
        [$eventId, (string) Str::uuid7(), $projectionCompletedAt, $exhaustedAt],
    );

    return $eventId;
}

/** @return array{exit: int, body: array<string, mixed>} */
function runStatus(): array
{
    $exit = Artisan::call('payments:outbox:status');

    /** @var array<string, mixed> $body */
    $body = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    return ['exit' => $exit, 'body' => $body];
}

function putHeartbeat(Carbon $at): void
{
    Cache::store('payments-relay')->put(
        RelayHeartbeat::KEY,
        $at->clone()->utc()->format(RelayHeartbeat::FORMAT),
        300,
    );
}

beforeEach(function (): void {
    RelayTransport::configure();
    Cache::store('payments-relay')->forget(RelayHeartbeat::KEY);
});

afterEach(function (): void {
    Cache::store('payments-relay')->forget(RelayHeartbeat::KEY);
    RelayTransport::teardown();
    Date::setTestNow();
});

it('reports the exact healthy schema and exits 0 on an empty backlog', function (): void {
    putHeartbeat(Date::now());

    ['exit' => $exit, 'body' => $body] = runStatus();

    expect($exit)->toBe(0)
        ->and($body)->toBe([
            'healthy' => true,
            'heartbeat' => [
                'state' => 'fresh',
                'timestamp' => $body['heartbeat']['timestamp'],
                'age_seconds' => 0,
            ],
            'database' => [
                'state' => 'ok',
                'eligible_count' => 0,
                'oldest_eligible_age_seconds' => null,
                'active_count' => 0,
                'gate' => 'open',
            ],
        ])
        ->and($body['heartbeat']['timestamp'])->toBeString();
});

it('is unhealthy with a non-zero exit when the heartbeat is stale', function (): void {
    putHeartbeat(Date::now()->subSeconds(10));

    ['exit' => $exit, 'body' => $body] = runStatus();

    expect($exit)->toBe(1)
        ->and($body['healthy'])->toBeFalse()
        ->and($body['heartbeat']['state'])->toBe('stale')
        ->and($body['heartbeat']['timestamp'])->toBeString()
        ->and($body['heartbeat']['age_seconds'])->toBeGreaterThanOrEqual(10)
        ->and($body['database']['state'])->toBe('ok');
});

it('is unhealthy with a non-zero exit when the heartbeat has never been written', function (): void {
    ['exit' => $exit, 'body' => $body] = runStatus();

    expect($exit)->toBe(1)
        ->and($body['healthy'])->toBeFalse()
        ->and($body['heartbeat'])->toBe(['state' => 'missing', 'timestamp' => null, 'age_seconds' => null])
        ->and($body['database']['state'])->toBe('ok');
});

it('is unhealthy with a non-zero exit when the heartbeat value is malformed', function (): void {
    Cache::store('payments-relay')->put(RelayHeartbeat::KEY, 'not-a-timestamp', 300);

    ['exit' => $exit, 'body' => $body] = runStatus();

    expect($exit)->toBe(1)
        ->and($body['healthy'])->toBeFalse()
        ->and($body['heartbeat'])->toBe(['state' => 'invalid', 'timestamp' => null, 'age_seconds' => null])
        ->and($body['database']['state'])->toBe('ok');
});

it('reports the heartbeat as unavailable, distinct from missing, when the cache backend cannot be read', function (): void {
    app()->bind(CacheFactory::class, fn (): CacheFactory => new class implements CacheFactory
    {
        public function store($name = null): never
        {
            throw new RuntimeException('cache backend unreachable');
        }
    });

    ['exit' => $exit, 'body' => $body] = runStatus();

    expect($exit)->toBe(1)
        ->and($body['healthy'])->toBeFalse()
        ->and($body['heartbeat'])->toBe(['state' => 'unavailable', 'timestamp' => null, 'age_seconds' => null])
        ->and($body['database']['state'])->toBe('ok');
});

it('computes eligible, active and oldest-eligible-age from one snapshot', function (): void {
    putHeartbeat(Date::now());

    statusEnvelope(availableAt: "now() - interval '10 seconds'");
    statusEnvelope(availableAt: "now() + interval '1 hour'");
    statusEnvelope(projectionCompletedAt: 'now()');
    statusEnvelope(exhaustedAt: 'now()');

    ['exit' => $exit, 'body' => $body] = runStatus();

    expect($exit)->toBe(0)
        ->and($body['database'])->toMatchArray(['state' => 'ok', 'eligible_count' => 1, 'active_count' => 2])
        // Bounded: the row keeps ageing in real time between insert and query.
        ->and($body['database']['oldest_eligible_age_seconds'])->toBeGreaterThanOrEqual(10)
        ->and($body['database']['oldest_eligible_age_seconds'])->toBeLessThan(30);
});

it('reports the database as unavailable, with null metrics and a non-zero exit, when the aggregate cannot run', function (): void {
    putHeartbeat(Date::now());

    $sentinel = 'S3CRET-status-sentinel';

    // Self-disarming: the callback stays registered on the shared Connection object.
    $armed = true;

    DB::connection()->beforeStartingTransaction(function () use (&$armed, $sentinel): void {
        if (! $armed) {
            return;
        }

        $armed = false;

        throw new RuntimeException("database unavailable: {$sentinel}");
    });

    ['exit' => $exit, 'body' => $body] = runStatus();

    expect($exit)->toBe(1)
        ->and($body['healthy'])->toBeFalse()
        ->and($body['heartbeat']['state'])->toBe('fresh')
        ->and($body['database'])->toBe([
            'state' => 'unavailable',
            'eligible_count' => null,
            'oldest_eligible_age_seconds' => null,
            'active_count' => null,
            'gate' => null,
        ]);

    expect(Artisan::output())->not->toContain($sentinel)
        ->and(Artisan::output())->not->toContain('RuntimeException');
});
