<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorOptions;
use Modules\Payments\Infrastructure\Projection\ProjectionConfiguration;
use Modules\Payments\Infrastructure\Relay\ProjectPaymentEvent;
use Modules\Payments\Infrastructure\Relay\RelayConfiguration;

/*
|--------------------------------------------------------------------------
| Horizon supervision, read from the real configuration
|--------------------------------------------------------------------------
|
| RESOLVED, NOT RE-READ. Where a claim can be proved by asking Horizon's own
| ProvisioningPlan — the same class `horizon` uses to decide what to supervise —
| it is, rather than by asserting the shape of the config array. A config file
| that says the right thing while Horizon parses it differently is exactly the
| drift these tests exist to catch.
|
| THE LITERAL NAMES APPEAR HERE ON PURPOSE. `payments-projection` is named by the
| runtime contract, by the relay that publishes, and by the supervisor that
| consumes; asserting one against the other would pass while both drifted
| together, so the literal is written out.
|
*/

/**
 * Horizon's own parsed plan: [environment => [supervisor => SupervisorOptions]].
 *
 * ProvisioningPlan::convert() renames as it parses — `tries` becomes `maxTries`,
 * `processes` becomes `maxProcesses`, and a queue ARRAY is joined into a
 * comma-separated STRING. Reading the parsed objects rather than the config array
 * is the whole point: it asserts what Horizon will supervise, not what the file
 * appears to say.
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

        // THE LITERALS, resolved through Horizon's own parser.
        expect($options->connection)->toBe('payments-projection', $environment)
            ->and($options->queue)->toBe('payments-projection', $environment);
    }
});

it('gives Horizon no queue the relay does not publish to', function (): void {
    // The publish side and the consume side, compared. The relay writes to exactly
    // one logical queue; a supervisor that also watched `default` would report
    // healthy while consuming nothing, because nothing in this project writes there.
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
            // ONE EXECUTION PER PUBLISHED JOB. Redelivery is the relay's and the
            // poison budget is the recorder's; a second attempt here would be
            // invisible to both. Horizon renames `tries` to maxTries as it parses.
            ->and($options->maxTries)->toBe(1, $environment)
            ->and($options->timeout)->toBe(15, $environment)
            // 'off' is SupervisorOptions' own spelling for no balancing; autoScales()
            // tests in_array($balance, ['simple', 'auto']).
            ->and($options->balance)->toBe('off', $environment)
            // No self-restart budget: a worker that recycled on maxTime or maxJobs
            // would abandon an in-flight projection for a reason unrelated to it.
            ->and($options->maxTime)->toBe(0, $environment)
            ->and($options->maxJobs)->toBe(0, $environment);
    }
});

it('keeps the three timeouts strictly ordered in the real configuration', function (): void {
    // THE CHAIN, resolved from the real files rather than from a fixture. The unit
    // suite proves the rule bites; this proves the shipped values satisfy it.
    $projection = resolve(ProjectionConfiguration::class);

    expect($projection->statementTimeoutMs)->toBe(2_000)
        ->and($projection->workerTimeoutSeconds)->toBe(15)
        ->and($projection->retryAfterSeconds)->toBe(90);

    expect($projection->statementTimeoutMs)->toBeLessThan($projection->workerTimeoutSeconds * 1_000)
        ->and($projection->workerTimeoutSeconds)->toBeLessThan($projection->retryAfterSeconds);

    // The middle link is the value Horizon actually supervises with, not a second
    // copy that happens to agree today.
    $supervisor = horizonPlan()['local']['payments-projection'];

    expect($supervisor->timeout)->toBe($projection->workerTimeoutSeconds);

    // And the outer link is the value the QUEUE uses, read from where RedisQueue
    // reads it.
    expect(config('queue.connections.payments-projection.retry_after'))
        ->toBe($projection->retryAfterSeconds);
});

it('adds no timeout or tries property to the serialised job', function (): void {
    // The ordering chain is enforced by CONFIGURATION, never by properties on the
    // message. A $timeout or $tries on the job would override the supervisor for
    // that message alone, invisibly to every assertion above — and would also
    // change the serialised payload, breaking messages already on the queue.
    $properties = array_map(
        fn (ReflectionProperty $p): string => $p->getName(),
        new ReflectionClass(ProjectPaymentEvent::class)->getProperties(),
    );

    expect($properties)->toBe(['eventId']);

    foreach (['timeout', 'tries', 'backoff', 'maxExceptions', 'retryUntil'] as $property) {
        expect(property_exists(ProjectPaymentEvent::class, $property))
            ->toBeFalse("ProjectPaymentEvent must not declare \${$property}");
    }
});

/*
|--------------------------------------------------------------------------
| The metadata connection
|--------------------------------------------------------------------------
*/

it('stores its own bookkeeping away from the Payments transport', function (): void {
    expect(config('horizon.use'))->toBe('horizon');

    $horizon = (array) config('database.redis.horizon');
    $payments = (array) config('database.redis.payments');

    expect($horizon)->not->toBeEmpty();

    // A DIFFERENT LOGICAL DATABASE, so Horizon's own trimming and expiry never
    // share a keyspace with undelivered messages.
    expect($horizon['database'])->not->toBe($payments['database']);

    // AND WITHOUT THE RELAY'S BOUNDS. Those exist because the relay publishes
    // inside an open PostgreSQL transaction holding a row lock; Horizon's
    // long-lived reads would fail against them, and widening them to suit Horizon
    // would silently delete the relay's database-occupancy control.
    expect($payments['max_retries'])->toBe(0)
        ->and($horizon)->not->toHaveKey('max_retries')
        ->and($horizon)->not->toHaveKey('read_timeout');
});

it('leaves the relay transport bounds untouched', function (): void {
    // A REGRESSION GUARD, not a restatement: the obvious way to make Horizon work
    // against a failing connection is to relax `payments`, and that would remove
    // the bound on how long a row lock is held.
    $relay = resolve(RelayConfiguration::class);

    expect($relay->redisTimeout)->toBe(0.25)
        ->and($relay->redisReadTimeout)->toBe(0.25);
});

/*
|--------------------------------------------------------------------------
| The dashboard gate
|--------------------------------------------------------------------------
*/

it('registers the dashboard route but refuses it by default', function (): void {
    // THE ROUTE EXISTS: laravel/horizon has no `enabled` switch and registers
    // unconditionally, which is precisely why the gate below is the control. A test
    // asserting the route was absent would be asserting something the package does
    // not offer.
    $paths = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'horizon'))
        ->values();

    expect($paths)->not->toBeEmpty();

    // AND IT IS CLOSED. .env.example ships HORIZON_DASHBOARD_ENABLED=false, and the
    // gate requires both that flag and the local environment.
    expect(config('horizon.dashboard.enabled'))->toBeFalse()
        ->and(Horizon::check(request()))->toBeFalse();
});

it('opens only when the flag and the environment both agree', function (
    bool $enabled,
    string $environment,
    bool $expected,
): void {
    // BOTH CONDITIONS, EACH PROVED TO MATTER. A gate that checked only the flag
    // would open a staging deploy whose .env was copied from a developer's; one
    // that checked only the environment would inherit the package's own fallback,
    // which is the default this provider exists to replace.
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
