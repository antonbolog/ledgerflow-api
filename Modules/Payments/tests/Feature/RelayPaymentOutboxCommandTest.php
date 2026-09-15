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
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Modules\Payments\Infrastructure\Relay\OutboxRelay;
use Modules\Payments\Infrastructure\Relay\RelayConfiguration;
use Modules\Payments\Infrastructure\Relay\RelayHeartbeat;
use Modules\Payments\Tests\Integration\RejectingQueue;
use Modules\Payments\Tests\Support\RelayTransport;
use Psr\Log\LoggerInterface;

// Named apart from the Integration helper: Pest helper functions share one global namespace.
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

/** @return Closure(): int */
function steppingClock(int $stepMs): Closure
{
    $readings = 0;

    return static function () use (&$readings, $stepMs): int {
        return ($readings++) * $stepMs * 1_000_000;
    };
}

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

    expect(RelayTransport::size())->toBe(2)
        ->and(DB::table('payment_outbox')->where('publish_count', 0)->count())->toBe(1);

    $summary = passContexts($records, 'relay_pass_completed')[0];

    expect($summary['attempted'])->toBe(2)
        ->and($summary['stop_reason'])->toBe('batch');
});

it('starts no new row once the start budget is spent', function (): void {
    bindRelayCommand(steppingClock(stepMs: 400));

    $records = passLogs();

    passEnvelope();
    passEnvelope();
    passEnvelope();

    $this->artisan('payments:outbox:relay')->assertExitCode(0);

    expect(RelayTransport::size())->toBe(2);

    $summary = passContexts($records, 'relay_pass_completed')[0];

    expect($summary['attempted'])->toBe(2)
        ->and($summary['stop_reason'])->toBe('budget');
});

it('continues after a transport rejection but never after any other failure', function (): void {
    app()->bind(OutboxRelay::class, fn (Application $app): OutboxRelay => new OutboxRelay(
        DB::connection(),
        RejectingQueue::redis(),
        $app->make(RelayConfiguration::class),
        $app->make(LoggerInterface::class),
        $app->make(ProjectionGate::class),
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
        $app->make(ProjectionGate::class),
    ));

    $records = passLogs();

    passEnvelope();
    passEnvelope();

    $this->artisan('payments:outbox:relay')->assertExitCode(1);

    $aborted = passContexts($records, 'relay_pass_aborted');

    expect($aborted)->toHaveCount(1)
        ->and($aborted[0]['attempted'])->toBe(1)
        ->and($aborted[0]['published'])->toBe(0);

    expect(passContexts($records, 'relay_pass_completed'))->toBeEmpty();
});

it('records the heartbeat once, in application UTC, only after a handled pass', function (): void {
    Date::setTestNow(Date::parse('2026-08-21T09:08:07.654321Z'));

    passEnvelope();

    expect(storedHeartbeat())->toBeNull();

    $this->artisan('payments:outbox:relay')->assertExitCode(0);

    expect(storedHeartbeat())->toBe('2026-08-21T09:08:07.654321+00:00');
});

it('writes no heartbeat when the pass aborts', function (): void {
    app()->bind(OutboxRelay::class, fn (Application $app): OutboxRelay => new OutboxRelay(
        DB::connection(),
        RejectingQueue::programming(),
        $app->make(RelayConfiguration::class),
        $app->make(LoggerInterface::class),
        $app->make(ProjectionGate::class),
    ));

    passEnvelope();

    $this->artisan('payments:outbox:relay')->assertExitCode(1);

    expect(storedHeartbeat())->toBeNull();
});

it('writes the heartbeat on an empty pass', function (): void {
    $this->artisan('payments:outbox:relay')->assertExitCode(0);

    expect(storedHeartbeat())->not->toBeNull();
});

it('does not undo completed delivery when the heartbeat cache is unavailable', function (): void {
    $records = passLogs();

    $eventId = passEnvelope();

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

it('keeps payloads and raw transport detail out of the pass log', function (): void {
    $sentinel = 'S3CRET-sentinel-do-not-log';

    app()->bind(OutboxRelay::class, fn (Application $app): OutboxRelay => new OutboxRelay(
        DB::connection(),
        RejectingQueue::redis("connection refused {$sentinel}"),
        $app->make(RelayConfiguration::class),
        $app->make(LoggerInterface::class),
        $app->make(ProjectionGate::class),
    ));

    $records = passLogs();

    passEnvelope();

    $this->artisan('payments:outbox:relay')->assertExitCode(0);

    expect($records->getArrayCopy())->not->toBeEmpty();

    foreach ($records->getArrayCopy() as $record) {
        $rendered = $record['message'].' '.json_encode($record['context']);

        expect($rendered)->not->toContain($sentinel)
            ->and($rendered)->not->toContain('connection refused')
            ->and($rendered)->not->toContain('amountMinor')
            ->and($rendered)->not->toContain('merchantId');
    }

    $summary = passContexts($records, 'relay_pass_completed')[0];

    expect(array_keys($summary))->toEqualCanonicalizing([
        'category', 'attempted', 'published', 'rejected', 'duration_ms', 'stop_reason',
    ]);
});
