<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorOptions;
use Modules\Payments\Infrastructure\Projection\ProjectionConfiguration;
use Modules\Payments\Infrastructure\Relay\RelayConfiguration;

/**
 * Horizon's parsed plan, not the config array: ProvisioningPlan renames keys and joins queue arrays into a string.
 *
 * @return array<string, array<string, SupervisorOptions>>
 */
function horizonPlan(): array
{
    return collect(ProvisioningPlan::get('master')->parsed)
        ->map(fn ($supervisors): array => $supervisors->all())
        ->all();
}

it('supervises exactly one queue, and it is the one the relay publishes to', function (): void {
    $plan = horizonPlan();

    expect(array_keys($plan))->toEqualCanonicalizing(['local', 'production', 'testing']);

    foreach ($plan as $environment => $supervisors) {
        expect(array_keys($supervisors))->toBe(['payments-projection'], $environment);

        $options = $supervisors['payments-projection'];

        expect($options->connection)->toBe('payments-projection', $environment)
            ->and($options->queue)->toBe('payments-projection', $environment);
    }
});

it('gives Horizon no queue the relay does not publish to', function (): void {
    $relay = resolve(RelayConfiguration::class);
    $projection = resolve(ProjectionConfiguration::class);

    expect($projection->queueConnection)->toBe($relay->queueConnection)
        ->and($projection->queue)->toBe($relay->queue);

    $queues = collect(horizonPlan())
        ->flatMap(fn (array $supervisors): array => collect($supervisors)
            ->flatMap(fn ($options): array => explode(',', (string) $options->queue))->all())
        ->unique()
        ->values()
        ->all();

    expect($queues)->toBe([$relay->queue]);
});

it('runs one process with one attempt and the accepted worker timeout', function (): void {
    foreach (horizonPlan() as $environment => $supervisors) {
        $options = $supervisors['payments-projection'];

        expect($options->maxProcesses)->toBe(1, $environment)
            ->and($options->maxTries)->toBe(1, $environment)
            ->and($options->timeout)->toBe(15, $environment)
            ->and($options->balance)->toBe('off', $environment)
            // Recycling on maxTime or maxJobs would abandon an in-flight projection.
            ->and($options->maxTime)->toBe(0, $environment)
            ->and($options->maxJobs)->toBe(0, $environment);
    }
});

it('keeps the three timeouts strictly ordered in the real configuration', function (): void {
    $projection = resolve(ProjectionConfiguration::class);

    expect($projection->statementTimeoutMs)->toBe(2_000)
        ->and($projection->workerTimeoutSeconds)->toBe(15)
        ->and($projection->retryAfterSeconds)->toBe(90);

    expect($projection->statementTimeoutMs)->toBeLessThan($projection->workerTimeoutSeconds * 1_000)
        ->and($projection->workerTimeoutSeconds)->toBeLessThan($projection->retryAfterSeconds);

    $supervisor = horizonPlan()['local']['payments-projection'];

    expect($supervisor->timeout)->toBe($projection->workerTimeoutSeconds);

    expect(config('queue.connections.payments-projection.retry_after'))
        ->toBe($projection->retryAfterSeconds);
});

it('stores its own bookkeeping away from the Payments transport', function (): void {
    expect(config('horizon.use'))->toBe('horizon');

    $horizon = (array) config('database.redis.horizon');
    $payments = (array) config('database.redis.payments');

    expect($horizon)->not->toBeEmpty();

    expect($horizon['database'])->not->toBe($payments['database']);

    expect($payments['max_retries'])->toBe(0)
        ->and($horizon)->not->toHaveKey('max_retries')
        ->and($horizon)->not->toHaveKey('read_timeout');
});

it('registers the dashboard route but refuses it by default', function (): void {
    // Horizon always registers its routes, so the gate is the only control.
    $paths = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'horizon'))
        ->values();

    expect($paths)->not->toBeEmpty();

    expect(config('horizon.dashboard.enabled'))->toBeFalse()
        ->and(Horizon::check(request()))->toBeFalse();
});

it('opens only when the flag and the environment both agree', function (
    bool $enabled,
    string $environment,
    bool $expected,
): void {
    config(['horizon.dashboard.enabled' => $enabled]);
    app()->detectEnvironment(fn (): string => $environment);

    expect(Horizon::check(request()))->toBe($expected);
})->with([
    'enabled in local' => [true, 'local', true],
    'enabled in production' => [true, 'production', false],
    'enabled in testing' => [true, 'testing', false],
    'disabled in local' => [false, 'local', false],
    'disabled in production' => [false, 'production', false],
]);
