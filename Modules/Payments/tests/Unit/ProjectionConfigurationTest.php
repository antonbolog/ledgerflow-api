<?php

declare(strict_types=1);

use Modules\Payments\Infrastructure\Projection\ProjectionConfiguration;

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
    expect(ProjectionConfiguration::fromArray(projectionConfigArray(), projectionQueueArray())
        ->statementTimeoutStatement())->toBe('set local statement_timeout = 2000');
});

it('refuses a statement timeout at or above the worker timeout', function (int $statementTimeoutMs): void {
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
    expect(fn () => ProjectionConfiguration::fromArray(
        projectionConfigArray(),
        projectionQueueArray(['retry_after' => $retryAfter]),
    ))->toThrow(InvalidArgumentException::class, 'must be strictly below queue.connections');
})->with([
    'equal to the worker timeout' => [15],
    'below the worker timeout' => [10],
]);

it('refuses any retry budget of its own', function (int $tries): void {
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
    // Eager arrays, never closures: a closure rewritten to a first-class callable is resolved differently by Pest.
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
    'a stringified statement timeout' => [
        projectionConfigArray(['statement_timeout_ms' => '2000']),
        projectionQueueArray(),
        'must be an integer greater than zero',
    ],
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
