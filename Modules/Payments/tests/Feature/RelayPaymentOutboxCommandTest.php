<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Payments\Infrastructure\Console\RelayPaymentOutboxCommand;
use Modules\Payments\Infrastructure\Relay\OutboxRelay;
use Modules\Payments\Infrastructure\Relay\RelayConfiguration;
use Modules\Payments\Infrastructure\Relay\RelayHeartbeat;
use Modules\Payments\Tests\Integration\RejectingQueue;
use Modules\Payments\Tests\Support\RelayTransport;
use Psr\Log\LoggerInterface;

/*
|--------------------------------------------------------------------------
| payments:outbox:relay — the bounded pass
|--------------------------------------------------------------------------
|
| The command owns three things the relay itself does not: how many rows one
| invocation may start, when it stops starting them, and whether the pass counts
| as healthy progress. Everything below is about those three.
|
| REAL REDIS, ON LOGICAL DATABASE 15, REACHED BY NAME. The default queue stays
| `sync`; only the payments connection is moved off the developer's keyspace.
|
| THE COMMIT BOUNDARY IS NOT TESTED HERE. RefreshDatabase wraps each test in a
| transaction, so the relay's commits are savepoints; that evidence belongs to the
| unwrapped Integration suite and is already proved there.
|
*/

/** One immediately eligible envelope. Named apart from the Integration helper on purpose. */
function passEnvelope(string $availableAt = 'now()'): string
{
    $eventId = (string) Str::uuid7();

    DB::statement(
        <<<SQL
        insert into payment_outbox (
            event_id, event_type, schema_version, aggregate_uuid, aggregate_version,
            payload, occurred_at, available_at, created_at, updated_at
        ) values (?, 'payments.payment_initiated', 1, ?, 1, '{}'::jsonb, now(), {$availableAt}, now(), now())
        SQL,
        [$eventId, (string) Str::uuid7()],
    );

    return $eventId;
}

/**
 * A monotonic source that advances by a fixed step on every reading.
 *
 * INJECTED, NOT SLEPT. Proving the start-budget with real time would need a pass
 * that genuinely takes 750 ms, which is both slow and racy. The command reads the
 * clock once at the start and once before each next row, so a step of N ms makes
 * the budget arrive after a known number of rows.
 *
 * @return Closure(): int
 */
function steppingClock(int $stepMs): Closure
{
    $readings = 0;

    return static function () use (&$readings, $stepMs): int {
        return ($readings++) * $stepMs * 1_000_000;
    };
}

/** Bind the command with a chosen clock, before Artisan resolves it. */
function bindRelayCommand(?Closure $monotonic = null): void
{
    app()->forgetInstance(RelayConfiguration::class);
    app()->forgetInstance(OutboxRelay::class);

    app()->bind(
        RelayPaymentOutboxCommand::class,
        fn (Application $app): RelayPaymentOutboxCommand => new RelayPaymentOutboxCommand(
            $app->make(OutboxRelay::class),
            $app->make(RelayConfiguration::class),
            $app->make(RelayHeartbeat::class),
            $app->make(LoggerInterface::class),
            $monotonic ?? static fn (): int => (int) hrtime(true),
        ),
    );
}

/** @return ArrayObject<int, array{level: string, message: string, context: array<string, mixed>}> */
function passLogs(): ArrayObject
{
    $records = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $logged) use ($records): void {
        $records[] = ['level' => $logged->level, 'message' => $logged->message, 'context' => $logged->context];
    });

    return $records;
}

/** @return list<array<string, mixed>> */
function passContexts(ArrayObject $records, string $category): array
{
    return array_values(array_filter(
        array_column($records->getArrayCopy(), 'context'),
        fn (array $context): bool => ($context['category'] ?? null) === $category,
    ));
}

function storedHeartbeat(): ?string
{
    $value = Cache::store('payments-relay')->get(RelayHeartbeat::KEY);

    return is_string($value) ? $value : null;
}

beforeEach(function (): void {
    RelayTransport::configure();

    expect(RelayTransport::databaseNumber())->toBe(RelayTransport::DATABASE);
    expect(RelayTransport::existingKeys(RelayTransport::clientPrefix()))->toBe([]);

    Cache::store('payments-relay')->forget(RelayHeartbeat::KEY);

    bindRelayCommand();
});

afterEach(function (): void {
    Cache::store('payments-relay')->forget(RelayHeartbeat::KEY);
    RelayTransport::teardown();
    Date::setTestNow();
});

/*
|--------------------------------------------------------------------------
| Bounds
|--------------------------------------------------------------------------
*/

it('drains the backlog and reports why it stopped', function (): void {
    $records = passLogs();

    passEnvelope();
    passEnvelope();
    passEnvelope();

    $this->artisan('payments:outbox:relay')->assertExitCode(0);

    expect(RelayTransport::size())->toBe(3);

    $summary = passContexts($records, 'relay_pass_completed');

    expect($summary)->toHaveCount(1)
        ->and($summary[0]['attempted'])->toBe(3)
        ->and($summary[0]['published'])->toBe(3)
        ->and($summary[0]['rejected'])->toBe(0)
        ->and($summary[0]['stop_reason'])->toBe('drained')
        ->and($summary[0]['duration_ms'])->toBeGreaterThanOrEqual(0);
});

it('starts no more rows once the batch cap is reached', function (): void {
    config(['payments.relay.batch_size' => 2]);
    bindRelayCommand();

    $records = passLogs();

    passEnvelope();
    passEnvelope();
    passEnvelope();

    $this->artisan('payments:outbox:relay')->assertExitCode(0);

    // The third row is untouched and stays eligible for the next pass — a bounded
    // pass, not a lost row.
    expect(RelayTransport::size())->toBe(2)
        ->and(DB::table('payment_outbox')->where('publish_count', 0)->count())->toBe(1);

    $summary = passContexts($records, 'relay_pass_completed')[0];

    expect($summary['attempted'])->toBe(2)
        ->and($summary['stop_reason'])->toBe('batch');
});

it('starts no new row once the start budget is spent', function (): void {
    // 750 ms budget, 400 ms per reading: the clock is read once at the start and
    // once before each next row, so the budget arrives before the third.
    bindRelayCommand(steppingClock(stepMs: 400));

    $records = passLogs();

    passEnvelope();
    passEnvelope();
    passEnvelope();

    $this->artisan('payments:outbox:relay')->assertExitCode(0);

    expect(RelayTransport::size())->toBe(2);

    $summary = passContexts($records, 'relay_pass_completed')[0];

    expect($summary['attempted'])->toBe(2)
        // A START budget, not a kill deadline: the second row was already begun and
        // committed in full.
        ->and($summary['stop_reason'])->toBe('budget');
});

it('continues after a transport rejection but never after any other failure', function (): void {
    // Rejection is retryable and durable, so a second row may still be attempted
    // while budget remains. Anything else leaves the connection in a state nobody
    // can describe, and the pass must end.
    app()->bind(OutboxRelay::class, fn (Application $app): OutboxRelay => new OutboxRelay(
        DB::connection(),
        RejectingQueue::redis(),
        $app->make(RelayConfiguration::class),
        $app->make(LoggerInterface::class),
    ));

    $records = passLogs();

    passEnvelope();
    passEnvelope();

    $this->artisan('payments:outbox:relay')->assertExitCode(0);

    $summary = passContexts($records, 'relay_pass_completed')[0];

    expect($summary['attempted'])->toBe(2)
        ->and($summary['published'])->toBe(0)
        ->and($summary['rejected'])->toBe(2)
        ->and($summary['stop_reason'])->toBe('drained');

    expect(DB::table('payment_outbox')->where('consecutive_transport_failures', 1)->count())->toBe(2);
});

it('aborts the pass with a non-zero exit when a row fails for any other reason', function (): void {
    app()->bind(OutboxRelay::class, fn (Application $app): OutboxRelay => new OutboxRelay(
        DB::connection(),
        RejectingQueue::programming(),
        $app->make(RelayConfiguration::class),
        $app->make(LoggerInterface::class),
    ));

    $records = passLogs();

    passEnvelope();
    passEnvelope();

    $this->artisan('payments:outbox:relay')->assertExitCode(1);

    // ONE attempt, then the pass ends. A second row on a connection whose state is
    // unknown is precisely what the classification exists to prevent.
    $aborted = passContexts($records, 'relay_pass_aborted');

    expect($aborted)->toHaveCount(1)
        ->and($aborted[0]['attempted'])->toBe(1)
        ->and($aborted[0]['published'])->toBe(0);

    expect(passContexts($records, 'relay_pass_completed'))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Heartbeat
|--------------------------------------------------------------------------
*/

it('records the heartbeat once, in application UTC, only after a handled pass', function (): void {
    Date::setTestNow(Date::parse('2026-08-21T09:08:07.654321Z'));

    passEnvelope();

    expect(storedHeartbeat())->toBeNull();

    $this->artisan('payments:outbox:relay')->assertExitCode(0);

    // Application UTC, not PostgreSQL now(): this records that the PROCESS made
    // progress, which is a different fact from when a payment occurred.
    expect(storedHeartbeat())->toBe('2026-08-21T09:08:07.654321+00:00');
});

it('writes no heartbeat when the pass aborts', function (): void {
    // The whole value of the signal. A heartbeat refreshed on a failing pass would
    // report a healthy scheduler for as long as the failure lasted.
    app()->bind(OutboxRelay::class, fn (Application $app): OutboxRelay => new OutboxRelay(
        DB::connection(),
        RejectingQueue::programming(),
        $app->make(RelayConfiguration::class),
        $app->make(LoggerInterface::class),
    ));

    passEnvelope();

    $this->artisan('payments:outbox:relay')->assertExitCode(1);

    expect(storedHeartbeat())->toBeNull();
});

it('writes the heartbeat on an empty pass', function (): void {
    // An empty backlog and a dead scheduler are indistinguishable unless liveness
    // is recorded independently of work done.
    $this->artisan('payments:outbox:relay')->assertExitCode(0);

    expect(storedHeartbeat())->not->toBeNull();
});

it('does not undo completed delivery when the heartbeat cache is unavailable', function (): void {
    $records = passLogs();

    $eventId = passEnvelope();

    // The cache is gone; the message is already in Redis and the row is already
    // committed. Failing the pass here would report a delivery problem that did not
    // happen, and would republish on the next pass for no reason.
    app()->bind(CacheFactory::class, fn (): CacheFactory => new class implements CacheFactory
    {
        public function store($name = null): never
        {
            throw new RuntimeException('cache backend unreachable');
        }
    });

    $this->artisan('payments:outbox:relay')->assertExitCode(0);

    expect(RelayTransport::size())->toBe(1)
        ->and((int) DB::table('payment_outbox')->where('event_id', $eventId)->value('publish_count'))->toBe(1);

    expect(passContexts($records, 'relay_heartbeat_write_failed'))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Log safety
|--------------------------------------------------------------------------
*/

it('keeps payloads and raw transport detail out of the pass log', function (): void {
    $sentinel = 'S3CRET-sentinel-do-not-log';

    app()->bind(OutboxRelay::class, fn (Application $app): OutboxRelay => new OutboxRelay(
        DB::connection(),
        RejectingQueue::redis("connection refused {$sentinel}"),
        $app->make(RelayConfiguration::class),
        $app->make(LoggerInterface::class),
    ));

    $records = passLogs();

    passEnvelope();

    $this->artisan('payments:outbox:relay')->assertExitCode(0);

    expect($records->getArrayCopy())->not->toBeEmpty();

    foreach ($records->getArrayCopy() as $record) {
        $rendered = $record['message'].' '.json_encode($record['context']);

        expect($rendered)->not->toContain($sentinel)
            ->and($rendered)->not->toContain('connection refused')
            // The envelope payload never leaves PostgreSQL.
            ->and($rendered)->not->toContain('amountMinor')
            ->and($rendered)->not->toContain('merchantId');
    }

    // The summary carries counters and a stop reason, and nothing else that could
    // grow into business data.
    $summary = passContexts($records, 'relay_pass_completed')[0];

    expect(array_keys($summary))->toEqualCanonicalizing([
        'category', 'attempted', 'published', 'rejected', 'duration_ms', 'stop_reason',
    ]);
});
