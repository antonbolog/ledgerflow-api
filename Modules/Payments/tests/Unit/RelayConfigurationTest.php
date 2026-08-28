<?php

declare(strict_types=1);

use Modules\Payments\Infrastructure\Relay\RelayConfiguration;
use Modules\Payments\Infrastructure\Relay\TransportBackoff;

/*
|--------------------------------------------------------------------------
| Relay configuration
|--------------------------------------------------------------------------
|
| config() returns mixed, and every relay value is either a duration used in an
| interval or a limit used in a loop. Converting once, here, with validation, is
| what keeps a mistyped .env from arriving as a zero-second backoff or a negative
| PostgreSQL interval — a shape PostgreSQL accepts without complaint and which
| would make a row eligible in the past forever.
|
| Every invalid case below fails at construction, which in the running
| application means at container resolution, before any row is touched.
|
*/

/** @return array<string, mixed> */
function relayConfigArray(array $overrides = []): array
{
    return array_merge([
        'queue_connection' => 'payments-projection',
        'queue' => 'payments-projection',
        'cache_store' => 'payments-relay',
        'batch_size' => 50,
        'start_budget_ms' => 750,
        'statement_timeout_ms' => 500,
        'reconciliation_grace_seconds' => 30,
        'backoff_base_seconds' => 1,
        'backoff_max_seconds' => 300,
        'heartbeat_freshness_seconds' => 3,
        'heartbeat_ttl_seconds' => 300,
    ], $overrides);
}

/**
 * `database.redis.payments`, reduced to the two keys RelayConfiguration validates.
 *
 * @return array<string, mixed>
 */
function relayTransportArray(array $overrides = []): array
{
    return array_merge([
        'timeout' => 0.25,
        'read_timeout' => 0.25,
    ], $overrides);
}

it('exposes every relay value as the typed shape the relay consumes', function (): void {
    $config = RelayConfiguration::fromArray(relayConfigArray(), relayTransportArray());

    expect($config->queueConnection)->toBe('payments-projection')
        ->and($config->queue)->toBe('payments-projection')
        ->and($config->cacheStore)->toBe('payments-relay')
        ->and($config->batchSize)->toBe(50)
        ->and($config->startBudgetMs)->toBe(750)
        ->and($config->statementTimeoutMs)->toBe(500)
        ->and($config->reconciliationGraceSeconds)->toBe(30)
        ->and($config->heartbeatFreshnessSeconds)->toBe(3)
        ->and($config->heartbeatTtlSeconds)->toBe(300)
        ->and($config->redisTimeout)->toBe(0.25)
        ->and($config->redisReadTimeout)->toBe(0.25);

    // One owner for the backoff rule: the configuration hands out the calculator
    // rather than two loose integers a caller could recombine differently.
    expect($config->backoff())->toBeInstanceOf(TransportBackoff::class)
        ->and($config->backoff()->secondsAfter(1))->toBe(1)
        ->and($config->backoff()->secondsAfter(99))->toBe(300);
});

it('refuses a missing key rather than substituting a default', function (string $key): void {
    // A silent default here would mean the config file and the running relay could
    // disagree about a timeout with nothing reporting it.
    $incomplete = relayConfigArray();
    unset($incomplete[$key]);

    expect(fn () => RelayConfiguration::fromArray($incomplete, relayTransportArray()))
        ->toThrow(InvalidArgumentException::class, $key);
})->with([
    'queue_connection', 'queue', 'cache_store', 'batch_size', 'start_budget_ms',
    'statement_timeout_ms', 'reconciliation_grace_seconds', 'backoff_base_seconds',
    'backoff_max_seconds', 'heartbeat_freshness_seconds', 'heartbeat_ttl_seconds',
]);

it('refuses a value that is the wrong type, or outside its usable range', function (array $overrides, string $key): void {
    // ONE arrangement, one assertion shape: construction throws and names the key.
    // Type and range are different faults, so both datasets are kept whole.
    expect(fn () => RelayConfiguration::fromArray(relayConfigArray($overrides), relayTransportArray()))
        ->toThrow(InvalidArgumentException::class, $key);
})->with([
    // --- wrong type ---------------------------------------------------------
    // '500' is what an uncast env() would produce. Accepting it would hide the
    // missing cast until some other value arrived as a non-numeric string.
    'string duration' => [['statement_timeout_ms' => '500'], 'statement_timeout_ms'],
    'float limit' => [['batch_size' => 50.0], 'batch_size'],
    'null name' => [['queue' => null], 'queue'],
    'empty name' => [['cache_store' => ''], 'cache_store'],
    'non-string connection' => [['queue_connection' => 7], 'queue_connection'],

    // --- outside range ------------------------------------------------------
    // An empty PAYMENTS_RELAY_BATCH_SIZE= in .env casts to 0, which would drain
    // nothing while the command still reported a healthy pass.
    'zero batch' => [['batch_size' => 0], 'batch_size'],
    'negative batch' => [['batch_size' => -1], 'batch_size'],
    'zero start budget' => [['start_budget_ms' => 0], 'start_budget_ms'],
    'zero statement timeout' => [['statement_timeout_ms' => 0], 'statement_timeout_ms'],
    'negative grace' => [['reconciliation_grace_seconds' => -1], 'reconciliation_grace_seconds'],
    // Zero grace is arithmetically valid and operationally catastrophic: with no
    // consumer to complete the row, available_at = now() republishes every
    // accepted row on the very next pass, forever.
    'zero grace' => [['reconciliation_grace_seconds' => 0], 'reconciliation_grace_seconds'],
    'zero freshness' => [['heartbeat_freshness_seconds' => 0], 'heartbeat_freshness_seconds'],
]);

it('refuses a heartbeat TTL that would erase a stale heartbeat before it can be reported', function (int $ttl): void {
    // The status contract distinguishes `stale` from `missing`. With a TTL at or
    // below the freshness window, a heartbeat can only ever be fresh or expired, so
    // a stopped scheduler would report `missing` — indistinguishable from a
    // scheduler that has never run since the cache was cleared.
    expect(fn () => RelayConfiguration::fromArray(relayConfigArray([
        'heartbeat_freshness_seconds' => 3,
        'heartbeat_ttl_seconds' => $ttl,
    ]), relayTransportArray()))->toThrow(InvalidArgumentException::class, 'heartbeat_ttl_seconds');
})->with([3, 2, 1]);

it('refuses a transport timeout that is not a finite number of seconds above zero', function (array $overrides, string $key): void {
    // EVERY CASE IS REACHABLE FROM .env. `PAYMENTS_RELAY_REDIS_TIMEOUT=` and
    // `=fast` both cast to 0.0, and phpredis reads 0.0 as NO timeout — an
    // unreachable Redis would then hold the relay's open transaction, and the row
    // lock inside it, until the process died. '1e999' and 'nan' cast to INF and NAN
    // and behave the same way or worse.
    expect(fn () => RelayConfiguration::fromArray(relayConfigArray(), relayTransportArray($overrides)))
        ->toThrow(InvalidArgumentException::class, $key);
})->with([
    'empty env casts to zero' => [['timeout' => (float) ''], 'timeout'],
    'non-numeric env casts to zero' => [['read_timeout' => (float) 'fast'], 'read_timeout'],
    'explicit zero' => [['timeout' => 0.0], 'timeout'],
    'negative' => [['read_timeout' => -1.0], 'read_timeout'],
    'infinite' => [['timeout' => INF], 'timeout'],
    'not a number' => [['read_timeout' => NAN], 'read_timeout'],
    'string that never got cast' => [['timeout' => '0.25'], 'timeout'],
    'missing entirely' => [['read_timeout' => null], 'read_timeout'],
]);

it('accepts an integer number of seconds for a transport timeout', function (): void {
    // config/database.php casts to float, but an integer is a valid duration and
    // rejecting it would be a type complaint dressed as a safety rule.
    $config = RelayConfiguration::fromArray(relayConfigArray(), relayTransportArray(['timeout' => 1]));

    expect($config->redisTimeout)->toBe(1.0);
});

it('refuses a transport key that is absent rather than defaulting it', function (string $key): void {
    $incomplete = relayTransportArray();
    unset($incomplete[$key]);

    expect(fn () => RelayConfiguration::fromArray(relayConfigArray(), $incomplete))
        ->toThrow(InvalidArgumentException::class, $key);
})->with(['timeout', 'read_timeout']);
