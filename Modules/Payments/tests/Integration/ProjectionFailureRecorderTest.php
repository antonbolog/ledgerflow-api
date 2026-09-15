<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Infrastructure\Projection\PaymentProjectionConsumer;
use Modules\Payments\Infrastructure\Projection\ProjectionEnvelopeRejected;
use Modules\Payments\Infrastructure\Projection\ProjectionFailureClassification;
use Modules\Payments\Infrastructure\Projection\ProjectionFailureRecorder;
use Modules\Payments\Tests\Integration\IntegrationDatabase;

function recorderEnvelope(
    ?int $merchantId = null,
    ?string $eventId = null,
    ?string $paymentId = null,
    int $failureCount = 0,
    int $generation = 0,
    ?string $projectionCompletedAt = null,
    ?string $exhaustedAt = null,
    ?string $lastProjectionError = null,
): string {
    $eventId ??= (string) Str::uuid7();
    $paymentId ??= (string) Str::uuid7();
    $merchantId ??= Merchant::factory()->create()->getKey();

    DB::table('payment_outbox')->insert([
        'event_id' => $eventId,
        'event_type' => 'payments.payment_initiated',
        'schema_version' => 1,
        'aggregate_uuid' => $paymentId,
        'aggregate_version' => 1,
        'payload' => json_encode([
            'eventId' => $eventId,
            'paymentId' => $paymentId,
            'merchantId' => $merchantId,
            'amountMinor' => 1_250,
            'currency' => 'USD',
            'occurredAt' => '2026-08-21T10:11:12.123456+00:00',
        ]),
        'occurred_at' => '2026-08-21T10:11:12.123456+00:00',
        'available_at' => now(),
        'projection_completed_at' => $projectionCompletedAt,
        'exhausted_at' => $exhaustedAt,
        'projection_failure_count' => $failureCount,
        'projection_failure_generation' => $generation,
        'last_projection_error' => $lastProjectionError,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $eventId;
}

function recorderRow(string $eventId): array
{
    $row = DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_outbox')->where('event_id', $eventId)->first();

    expect($row)->not->toBeNull("no committed payment_outbox row for {$eventId}");

    return (array) $row;
}

function recorderTransportColumns(array $row): array
{
    return [
        'publish_count' => (int) $row['publish_count'],
        'consecutive_transport_failures' => (int) $row['consecutive_transport_failures'],
        'last_transport_error' => $row['last_transport_error'],
        'last_published_at' => $row['last_published_at'],
        'available_at' => $row['available_at'],
    ];
}

function recorder(): ProjectionFailureRecorder
{
    return resolve(ProjectionFailureRecorder::class);
}

function recorderCapturedLogs(): ArrayObject
{
    $records = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $logged) use ($records): void {
        $records[] = ['level' => $logged->level, 'message' => $logged->message, 'context' => $logged->context];
    });

    return $records;
}

/** @return list<array<string, mixed>> */
function recorderLogContexts(ArrayObject $records): array
{
    return array_values(array_column($records->getArrayCopy(), 'context'));
}

/** @return list<array<string, mixed>> */
function recorderLogsOfCategory(ArrayObject $records, string $category): array
{
    return array_values(array_filter(
        recorderLogContexts($records),
        fn (array $context): bool => ($context['category'] ?? null) === $category,
    ));
}

beforeEach(function (): void {
    IntegrationDatabase::ensureSchema();
    IntegrationDatabase::register();
    IntegrationDatabase::refuseIfWrapped();
    IntegrationDatabase::truncate();
});

afterEach(function (): void {
    DB::statement('set lock_timeout = default');

    IntegrationDatabase::rollbackOpenTransactions();
    IntegrationDatabase::releaseLocks();
    IntegrationDatabase::truncate();
    IntegrationDatabase::disconnectAll();
});

it('increments the count and records the category in its own committed transaction', function (): void {
    $eventId = recorderEnvelope();

    recorder()->record($eventId, 0, ProjectionEnvelopeRejected::MALFORMED_PAYLOAD);

    $row = recorderRow($eventId);

    expect((int) $row['projection_failure_count'])->toBe(1)
        ->and($row['last_projection_error'])->toBe(ProjectionEnvelopeRejected::MALFORMED_PAYLOAD)
        ->and($row['exhausted_at'])->toBeNull()
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});

it('exhausts the envelope on exactly the fifth confirmed failure', function (): void {
    $eventId = recorderEnvelope();

    foreach ([1, 2, 3, 4] as $expected) {
        recorder()->record($eventId, 0, ProjectionFailureClassification::HANDLER_FAILED);

        $row = recorderRow($eventId);

        expect((int) $row['projection_failure_count'])->toBe($expected)
            ->and($row['exhausted_at'])->toBeNull("count {$expected} must not exhaust");
    }

    recorder()->record($eventId, 0, ProjectionFailureClassification::HANDLER_FAILED);

    $exhausted = recorderRow($eventId);

    expect((int) $exhausted['projection_failure_count'])->toBe(5)
        ->and($exhausted['exhausted_at'])->not->toBeNull();

    expect(DB::connection(IntegrationDatabase::OBSERVER)->selectOne(
        'select projection_failure_count = 5 and exhausted_at is not null as together
           from payment_outbox where event_id = ?',
        [$eventId],
    )?->together)->toBeTrue();
});

it('refuses a sixth failure rather than overwriting the record of the fifth', function (): void {
    $eventId = recorderEnvelope(failureCount: 5, exhaustedAt: '2026-08-21T10:00:00+00:00',
        lastProjectionError: ProjectionEnvelopeRejected::MALFORMED_PAYLOAD);

    $before = recorderRow($eventId);

    recorder()->record($eventId, 0, ProjectionFailureClassification::HANDLER_FAILED);

    expect(recorderRow($eventId))->toBe($before);
});

it('never writes a column the relay owns', function (): void {
    $eventId = recorderEnvelope();

    DB::table('payment_outbox')->where('event_id', $eventId)->update([
        'publish_count' => 3,
        'consecutive_transport_failures' => 2,
        'last_transport_error' => 'redis_transport_rejected',
        'last_published_at' => now(),
    ]);

    $before = recorderRow($eventId);

    recorder()->record($eventId, 0, ProjectionFailureClassification::HANDLER_FAILED);

    expect(recorderTransportColumns(recorderRow($eventId)))->toBe(recorderTransportColumns($before));
});

it('declines to poison a row a concurrent duplicate has already completed', function (
    array $envelope,
    string $reason,
): void {
    // A late recorder, reproduced by ordering: the row has moved on before record() runs with generation 0.
    $records = recorderCapturedLogs();
    $eventId = recorderEnvelope(...$envelope);

    $before = recorderRow($eventId);

    recorder()->record($eventId, 0, ProjectionFailureClassification::HANDLER_FAILED);

    expect(recorderRow($eventId))->toBe($before);

    $stale = recorderLogsOfCategory($records, 'projection_failure_stale');

    expect($stale)->toHaveCount(1)
        ->and($stale[0]['reason'])->toBe($reason)
        ->and($stale[0]['event_id'])->toBe($eventId);
})->with([
    'a completed projection' => [
        ['projectionCompletedAt' => '2026-08-21T10:00:00+00:00'],
        'already_projected',
    ],
    'an exhausted envelope' => [
        ['exhaustedAt' => '2026-08-21T10:00:00+00:00'],
        'already_exhausted',
    ],
    'a generation that has moved on' => [
        ['generation' => 1],
        'generation_advanced',
    ],
]);

it('declines when the envelope no longer exists at all', function (): void {
    $records = recorderCapturedLogs();
    $eventId = (string) Str::uuid7();

    recorder()->record($eventId, 0, ProjectionFailureClassification::HANDLER_FAILED);

    $stale = recorderLogsOfCategory($records, 'projection_failure_stale');

    expect($stale)->toHaveCount(1)
        ->and($stale[0]['reason'])->toBe('envelope_absent')
        ->and(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_outbox')->count())->toBe(0);
});

it('cannot charge a reset cycle with a failure from the cycle before it', function (): void {
    // Count 0 after a retry looks like count 0 before any failure; only the generation separates the cycles.
    $records = recorderCapturedLogs();
    $eventId = recorderEnvelope(failureCount: 4, generation: 0);

    recorder()->record($eventId, 0, ProjectionFailureClassification::HANDLER_FAILED);

    expect((int) recorderRow($eventId)['projection_failure_count'])->toBe(5)
        ->and(recorderRow($eventId)['exhausted_at'])->not->toBeNull();

    DB::table('payment_outbox')->where('event_id', $eventId)->update([
        'exhausted_at' => null,
        'projection_failure_count' => 0,
        'last_projection_error' => null,
        'projection_failure_generation' => DB::raw('projection_failure_generation + 1'),
        'available_at' => now(),
    ]);

    $afterRetry = recorderRow($eventId);

    recorder()->record($eventId, 0, ProjectionFailureClassification::HANDLER_FAILED);

    expect(recorderRow($eventId))->toBe($afterRetry)
        ->and((int) recorderRow($eventId)['projection_failure_count'])->toBe(0);

    $stale = recorderLogsOfCategory($records, 'projection_failure_stale');

    expect($stale)->toHaveCount(1)
        ->and($stale[0]['reason'])->toBe('generation_advanced');

    recorder()->record($eventId, 1, ProjectionFailureClassification::HANDLER_FAILED);

    expect((int) recorderRow($eventId)['projection_failure_count'])->toBe(1);
});

it('leaves the envelope retryable when it cannot persist the failure', function (): void {
    $records = recorderCapturedLogs();
    $eventId = recorderEnvelope();

    $before = recorderRow($eventId);

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->select('select event_id from payment_outbox where event_id = ? for update', [$eventId]);

    DB::statement("set lock_timeout = '250ms'");

    recorder()->record($eventId, 0, ProjectionFailureClassification::HANDLER_FAILED);

    DB::statement('set lock_timeout = default');
    $lock->rollBack();

    expect(recorderRow($eventId))->toBe($before)
        ->and((int) recorderRow($eventId)['projection_failure_count'])->toBe(0)
        ->and(recorderRow($eventId)['exhausted_at'])->toBeNull();

    $failed = recorderLogsOfCategory($records, 'projection_failure_not_recorded');

    expect($failed)->toHaveCount(1)
        ->and($failed[0]['event_id'])->toBe($eventId);

    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});

it('persists only a fixed category, never server text or a payload value', function (): void {
    $eventId = recorderEnvelope();

    recorder()->record($eventId, 0, ProjectionEnvelopeRejected::CONTRADICTORY_IDENTITY);

    $stored = recorderRow($eventId)['last_projection_error'];

    expect($stored)->toBe('projection_identity_contradictory')
        ->and($stored)->not->toContain(' ')
        ->and($stored)->toStartWith('projection_');
});

it('keeps every recorder log context to safe fields only', function (): void {
    $records = recorderCapturedLogs();
    $eventId = recorderEnvelope();

    recorder()->record($eventId, 0, ProjectionEnvelopeRejected::MALFORMED_PAYLOAD);

    $recorded = recorderLogsOfCategory($records, 'projection_failure_recorded');

    expect($recorded)->toHaveCount(1)
        ->and(array_keys($recorded[0]))->toEqualCanonicalizing([
            'category', 'failure_category', 'event_id',
            'projection_failure_count', 'projection_failure_generation',
        ]);

    $rendered = (string) json_encode($recorded[0]);

    expect($rendered)->toContain($eventId)
        ->and($rendered)->not->toContain('USD')
        ->and($rendered)->not->toContain('1250')
        ->and($rendered)->not->toContain('idempotency');
});

it('names the exhaustion in its own log category', function (): void {
    $records = recorderCapturedLogs();
    $eventId = recorderEnvelope(failureCount: 4);

    recorder()->record($eventId, 0, ProjectionFailureClassification::HANDLER_FAILED);

    expect(recorderLogsOfCategory($records, 'projection_exhausted'))->toHaveCount(1)
        ->and(recorderLogsOfCategory($records, 'projection_failure_recorded'))->toHaveCount(0);
});

it('charges five confirmed deliveries and then stops delivering', function (): void {
    $eventId = recorderEnvelope(merchantId: 987_654_321);

    foreach ([1, 2, 3, 4] as $expected) {
        try {
            resolve(PaymentProjectionConsumer::class)->project($eventId);
        } catch (QueryException) {
        }

        expect((int) recorderRow($eventId)['projection_failure_count'])->toBe($expected)
            ->and(recorderRow($eventId)['exhausted_at'])->toBeNull();
    }

    try {
        resolve(PaymentProjectionConsumer::class)->project($eventId);
    } catch (QueryException) {
    }

    expect((int) recorderRow($eventId)['projection_failure_count'])->toBe(5)
        ->and(recorderRow($eventId)['exhausted_at'])->not->toBeNull();

    $exhausted = recorderRow($eventId);

    expect(resolve(PaymentProjectionConsumer::class)->project($eventId)->value)
        ->toBe('skipped_exhausted')
        ->and(recorderRow($eventId))->toBe($exhausted);
});

it('does not charge a delivery that lost a race to a successful duplicate', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    $eventId = recorderEnvelope(merchantId: $merchantId);

    resolve(PaymentProjectionConsumer::class)->project($eventId);
    resolve(PaymentProjectionConsumer::class)->project($eventId);

    $row = recorderRow($eventId);

    expect((int) $row['projection_failure_count'])->toBe(0)
        ->and($row['last_projection_error'])->toBeNull()
        ->and($row['exhausted_at'])->toBeNull();
});

it('does not charge a non-countable failure raised after the envelope was locked', function (): void {
    // The failure must land after the envelope lock: a merchant row lock blocks only the read row's foreign-key check.
    $merchantId = Merchant::factory()->create()->getKey();
    $eventId = recorderEnvelope(merchantId: $merchantId);

    $before = recorderRow($eventId);

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->select('select id from merchants where id = ? for update', [$merchantId]);

    DB::statement("set lock_timeout = '250ms'");

    $sqlState = null;

    try {
        resolve(PaymentProjectionConsumer::class)->project($eventId);
    } catch (QueryException $exception) {
        $sqlState = RetryingAtomicTransaction::sqlStateOf($exception);
    }

    DB::statement('set lock_timeout = default');
    $lock->rollBack();

    expect($sqlState)->toBe('55P03');

    expect(recorderRow($eventId))->toBe($before)
        ->and((int) recorderRow($eventId)['projection_failure_count'])->toBe(0);
});

it('does not charge a lock timeout, however many times it happens', function (): void {
    $eventId = recorderEnvelope();

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->select('select event_id from payment_outbox where event_id = ? for update', [$eventId]);

    DB::statement("set lock_timeout = '100ms'");

    foreach (range(1, 5) as $ignored) {
        try {
            resolve(PaymentProjectionConsumer::class)->project($eventId);
        } catch (QueryException) {
        }
    }

    DB::statement('set lock_timeout = default');
    $lock->rollBack();

    $row = recorderRow($eventId);

    expect((int) $row['projection_failure_count'])->toBe(0)
        ->and($row['exhausted_at'])->toBeNull()
        ->and($row['last_projection_error'])->toBeNull();
});
