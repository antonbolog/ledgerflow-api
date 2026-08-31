<?php

declare(strict_types=1);

use Modules\Payments\Infrastructure\Projection\ProjectionConfiguration;

/*
|--------------------------------------------------------------------------
| The ordering chain, as a rule rather than as three numbers
|--------------------------------------------------------------------------
|
| Framework-free: fromArray() takes two plain arrays, so every rejection below is
| provable without booting Laravel or reading a config file. The Feature suite
| asserts that the REAL configuration satisfies these rules; this file asserts
| that the rules exist and bite.
|
*/

/** @return array<string, mixed> */
function projectionConfigArray(array $overrides = []): array
{
    return array_merge([
        'queue_connection' => 'payments-projection',
        'queue' => 'payments-projection',
        'statement_timeout_ms' => 2_000,
        'worker_timeout_seconds' => 15,
        'tries' => 1,
        'processes' => 1,
    ], $overrides);
}

/** @return array<string, mixed> */
function projectionQueueArray(array $overrides = []): array
{
    return array_merge(['retry_after' => 90], $overrides);
}

it('accepts the accepted starting policy', function (): void {
    $config = ProjectionConfiguration::fromArray(projectionConfigArray(), projectionQueueArray());

    expect($config->queueConnection)->toBe('payments-projection')
        ->and($config->queue)->toBe('payments-projection')
        ->and($config->statementTimeoutMs)->toBe(2_000)
        ->and($config->workerTimeoutSeconds)->toBe(15)
        ->and($config->retryAfterSeconds)->toBe(90)
        ->and($config->tries)->toBe(1)
        ->and($config->processes)->toBe(1);
});

it('renders the statement timeout as PostgreSQL SET LOCAL', function (): void {
    // SET LOCAL, not SET: the bound must die with the consumer's transaction rather
    // than leaking into whatever the long-lived worker connection does next.
    expect(ProjectionConfiguration::fromArray(projectionConfigArray(), projectionQueueArray())
        ->statementTimeoutStatement())->toBe('set local statement_timeout = 2000');
});

it('refuses a statement timeout at or above the worker timeout', function (int $statementTimeoutMs): void {
    // Above: the worker's alarm fires first and kills the process mid-transaction,
    // which the failure classification treats as non-countable worker termination —
    // so a slow deterministic failure would retry forever without ever exhausting.
    //
    // EQUAL IS REFUSED AS FIRMLY AS INVERTED. Two timers due at the same instant
    // race, and which fires first is decided by scheduling rather than by this
    // configuration.
    expect(fn () => ProjectionConfiguration::fromArray(
        projectionConfigArray(['statement_timeout_ms' => $statementTimeoutMs]),
        projectionQueueArray(),
    ))->toThrow(InvalidArgumentException::class, 'strictly below the worker timeout');
})->with([
    'equal to the worker timeout' => [15_000],
    'above the worker timeout' => [15_001],
    'far above the worker timeout' => [120_000],
]);

it('refuses a worker timeout at or above the queue reservation', function (int $retryAfter): void {
    // Above: Redis releases the reservation while the first worker still holds the
    // envelope row lock, a second worker takes the same message, and "one Horizon
    // execution per published job" stops being true.
    expect(fn () => ProjectionConfiguration::fromArray(
        projectionConfigArray(),
        projectionQueueArray(['retry_after' => $retryAfter]),
    ))->toThrow(InvalidArgumentException::class, 'must be strictly below queue.connections');
})->with([
    'equal to the worker timeout' => [15],
    'below the worker timeout' => [10],
]);

it('refuses any retry budget of its own', function (int $tries): void {
    // Horizon owns no retry budget: redelivery is the relay's, durable in
    // available_at, and the poison budget is the recorder's. A second attempt here
    // would be invisible to both, so the fifth confirmed failure would no longer be
    // the fifth delivery.
    expect(fn () => ProjectionConfiguration::fromArray(
        projectionConfigArray(['tries' => $tries]),
        projectionQueueArray(),
    ))->toThrow(InvalidArgumentException::class, 'must be exactly 1');
})->with([[2], [3], [10]]);

it('refuses a value that is missing, null, or not a positive integer', function (
    array $config,
    array $queue,
    string $expected,
): void {
    expect(fn () => ProjectionConfiguration::fromArray($config, $queue))
        ->toThrow(InvalidArgumentException::class, $expected);
})->with([
    // EAGER, NOT LAZY. These builders are pure array constructors defined in this
    // file, so there is nothing to defer — and a closure here would be rewritten to
    // a first-class callable that Pest resolves differently, which is how this
    // dataset silently stopped passing arrays at all.
    //
    // array_key_exists, not isset: a null is the wrong TYPE for its key, not a
    // missing key, and neither may be quietly defaulted.
    'a missing statement timeout' => [
        array_diff_key(projectionConfigArray(), ['statement_timeout_ms' => null]),
        projectionQueueArray(),
        'is missing',
    ],
    'a null worker timeout' => [
        projectionConfigArray(['worker_timeout_seconds' => null]),
        projectionQueueArray(),
        'must be an integer greater than zero',
    ],
    // A STRING MEANS AN env() CREPT IN, which is exactly how a link of the ordering
    // chain becomes tunable without anyone deciding that it should be.
    'a stringified statement timeout' => [
        projectionConfigArray(['statement_timeout_ms' => '2000']),
        projectionQueueArray(),
        'must be an integer greater than zero',
    ],
    // Zero is not a small bound, it is no bound at all.
    'a zero statement timeout' => [
        projectionConfigArray(['statement_timeout_ms' => 0]),
        projectionQueueArray(),
        'must be an integer greater than zero',
    ],
    'a zero process count' => [
        projectionConfigArray(['processes' => 0]),
        projectionQueueArray(),
        'must be an integer greater than zero',
    ],
    'a missing queue reservation' => [
        projectionConfigArray(),
        [],
        'queue.connections.payments-projection.retry_after is missing',
    ],
    'an empty queue name' => [
        projectionConfigArray(['queue' => '  ']),
        projectionQueueArray(),
        'must be a non-empty string',
    ],
    'a queue name that is not a string' => [
        projectionConfigArray(['queue_connection' => 42]),
        projectionQueueArray(),
        'must be a non-empty string',
    ],
]);
