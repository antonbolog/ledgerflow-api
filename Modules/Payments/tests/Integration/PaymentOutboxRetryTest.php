<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Infrastructure\Console\PaymentOutboxRetryCommand;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Infrastructure\Projection\ProjectionFailureClassification;
use Modules\Payments\Infrastructure\Projection\ProjectionFailureRecorder;
use Modules\Payments\Infrastructure\Relay\ProjectPaymentEvent;
use Modules\Payments\Tests\Integration\IntegrationDatabase;

function operatorEnvelope(
    ?int $merchantId = null,
    ?string $eventId = null,
    ?string $paymentId = null,
    int $failureCount = 0,
    int $generation = 0,
    ?string $projectionCompletedAt = null,
    ?string $exhaustedAt = null,
    ?string $lastProjectionError = null,
    int $publishCount = 3,
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
        'available_at' => '2099-01-01T00:00:00+00:00',
        'projection_completed_at' => $projectionCompletedAt,
        'exhausted_at' => $exhaustedAt,
        'projection_failure_count' => $failureCount,
        'projection_failure_generation' => $generation,
        'last_projection_error' => $lastProjectionError,
        'publish_count' => $publishCount,
        'last_published_at' => '2026-08-21T10:00:00+00:00',
        'consecutive_transport_failures' => 2,
        'last_transport_error' => 'redis_transport_rejected',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $eventId;
}

function operatorRow(string $eventId): array
{
    $row = DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_outbox')->where('event_id', $eventId)->first();

    expect($row)->not->toBeNull("no committed payment_outbox row for {$eventId}");

    return (array) $row;
}

function operatorPublicationHistory(array $row): array
{
    return [
        'publish_count' => (int) $row['publish_count'],
        'last_published_at' => $row['last_published_at'],
        'consecutive_transport_failures' => (int) $row['consecutive_transport_failures'],
        'last_transport_error' => $row['last_transport_error'],
    ];
}

function operatorCapturedLogs(): ArrayObject
{
    $records = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $logged) use ($records): void {
        $records[] = ['level' => $logged->level, 'context' => $logged->context];
    });

    return $records;
}

/** @return list<array<string, mixed>> */
function operatorLogsOfCategory(ArrayObject $records, string $category): array
{
    return array_values(array_filter(
        array_column($records->getArrayCopy(), 'context'),
        fn (array $context): bool => ($context['category'] ?? null) === $category,
    ));
}

function operatorRetry(string $eventId): int
{
    return Artisan::call('payments:outbox:retry', ['eventId' => $eventId]);
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

it('projects the envelope when the queued job runs through the container', function (): void {
    // Container::call() resolves the type-hinted handle() parameter, as a worker does.
    $merchantId = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();
    $eventId = operatorEnvelope(merchantId: $merchantId, paymentId: $paymentId);

    app()->call([new ProjectPaymentEvent($eventId), 'handle']);

    expect(DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_details')->where('source_event_id', $eventId)->value('payment_id'))
        ->toBe($paymentId)
        ->and(operatorRow($eventId)['projection_completed_at'])->not->toBeNull();
});

it('lets a consumer failure escape the job rather than acknowledging it', function (): void {
    $eventId = operatorEnvelope(merchantId: 987_654_321);

    expect(fn () => app()->call([new ProjectPaymentEvent($eventId), 'handle']))
        ->toThrow(QueryException::class);

    expect((int) operatorRow($eventId)['projection_failure_count'])->toBe(1);
});

it('still deserialises a message published before the body changed', function (): void {
    $eventId = '01984f6f-6a20-7000-8000-000000000001';

    // A literal, never ::class, so renaming or moving the class fails here.
    $class = 'Modules\Payments\Infrastructure\Relay\ProjectPaymentEvent';

    $legacy = 'O:'.strlen($class).':"'.$class.'":1:{s:7:"eventId";s:36:"'.$eventId.'";}';

    $restored = unserialize($legacy);

    expect($restored)->toBeInstanceOf(ProjectPaymentEvent::class)
        ->and($restored->eventId)->toBe($eventId);
});

it('refuses an unknown event without mutating anything', function (): void {
    $records = operatorCapturedLogs();
    $eventId = (string) Str::uuid7();

    expect(operatorRetry($eventId))->toBe(1);

    expect(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_outbox')->count())->toBe(0);

    $refused = operatorLogsOfCategory($records, 'projection_operator_retry_refused');

    expect($refused)->toHaveCount(1)
        ->and($refused[0]['reason'])->toBe(PaymentOutboxRetryCommand::UNKNOWN);
});

it('refuses a completed or non-exhausted envelope without mutating anything', function (
    array $envelope,
    string $reason,
): void {
    $records = operatorCapturedLogs();
    $eventId = operatorEnvelope(...$envelope);

    $before = operatorRow($eventId);

    expect(operatorRetry($eventId))->toBe(1);

    expect(operatorRow($eventId))->toBe($before);

    $refused = operatorLogsOfCategory($records, 'projection_operator_retry_refused');

    expect($refused)->toHaveCount(1)
        ->and($refused[0]['reason'])->toBe($reason);
})->with([
    'a completed projection' => [
        ['projectionCompletedAt' => '2026-08-21T10:00:00+00:00'],
        PaymentOutboxRetryCommand::ALREADY_PROJECTED,
    ],
    'an envelope that has never failed' => [
        [],
        PaymentOutboxRetryCommand::NOT_EXHAUSTED,
    ],
    'an envelope part way through its budget' => [
        ['failureCount' => 4, 'lastProjectionError' => 'projection_handler_failed'],
        PaymentOutboxRetryCommand::NOT_EXHAUSTED,
    ],
    'a completed envelope that had also exhausted' => [
        [
            'projectionCompletedAt' => '2026-08-21T10:00:00+00:00',
            'exhaustedAt' => '2026-08-21T09:00:00+00:00',
            'failureCount' => 5,
        ],
        PaymentOutboxRetryCommand::ALREADY_PROJECTED,
    ],
]);

it('clears the poison state, advances the fence and preserves publication history', function (): void {
    $records = operatorCapturedLogs();
    $eventId = operatorEnvelope(
        failureCount: 5,
        generation: 2,
        exhaustedAt: '2026-08-21T09:00:00+00:00',
        lastProjectionError: 'projection_handler_failed',
    );

    $before = operatorRow($eventId);

    expect(operatorRetry($eventId))->toBe(0);

    $after = operatorRow($eventId);

    expect($after['exhausted_at'])->toBeNull()
        ->and((int) $after['projection_failure_count'])->toBe(0)
        ->and($after['last_projection_error'])->toBeNull()
        ->and((int) $after['projection_failure_generation'])->toBe(3);

    expect(DB::connection(IntegrationDatabase::OBSERVER)->selectOne(
        'select available_at <= now() as eligible from payment_outbox where event_id = ?',
        [$eventId],
    )?->eligible)->toBeTrue();

    expect(operatorPublicationHistory($after))->toBe(operatorPublicationHistory($before));

    $retried = operatorLogsOfCategory($records, 'projection_operator_retry');

    expect($retried)->toHaveCount(1)
        ->and($retried[0]['event_id'])->toBe($eventId)
        ->and($retried[0]['projection_failure_generation'])->toBe(3);
});

it('makes the reset fence refuse a recorder from the cycle it ended', function (): void {
    $eventId = operatorEnvelope(
        failureCount: 5,
        generation: 0,
        exhaustedAt: '2026-08-21T09:00:00+00:00',
        lastProjectionError: 'projection_handler_failed',
    );

    expect(operatorRetry($eventId))->toBe(0);

    $afterRetry = operatorRow($eventId);

    resolve(ProjectionFailureRecorder::class)
        ->record($eventId, 0, ProjectionFailureClassification::HANDLER_FAILED);

    expect(operatorRow($eventId))->toBe($afterRetry)
        ->and((int) operatorRow($eventId)['projection_failure_count'])->toBe(0);

    resolve(ProjectionFailureRecorder::class)
        ->record($eventId, 1, ProjectionFailureClassification::HANDLER_FAILED);

    expect((int) operatorRow($eventId)['projection_failure_count'])->toBe(1);
});

it('reactivates an exhausted envelope so the next delivery projects it', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    $eventId = operatorEnvelope(
        merchantId: $merchantId,
        failureCount: 5,
        exhaustedAt: '2026-08-21T09:00:00+00:00',
        lastProjectionError: 'projection_handler_failed',
    );

    expect(app()->call([new ProjectPaymentEvent($eventId), 'handle']))->toBeNull();
    expect(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_details')->count())->toBe(0);

    expect(operatorRetry($eventId))->toBe(0);

    app()->call([new ProjectPaymentEvent($eventId), 'handle']);

    expect(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_details')->count())->toBe(1)
        ->and(operatorRow($eventId)['projection_completed_at'])->not->toBeNull();
});

it('waits for a delivery in flight rather than reading state about to change', function (): void {
    $eventId = operatorEnvelope(
        failureCount: 5,
        exhaustedAt: '2026-08-21T09:00:00+00:00',
    );

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->select('select event_id from payment_outbox where event_id = ? for update', [$eventId]);

    DB::statement("set lock_timeout = '250ms'");

    // The UPDATE would also raise 55P03, so only the absence of a successful SELECT proves the FOR UPDATE.
    // Queries are logged only after they succeed.
    $executed = [];
    DB::listen(function ($query) use (&$executed): void {
        $executed[] = $query->sql;
    });

    $sqlState = null;

    try {
        operatorRetry($eventId);
    } catch (QueryException $exception) {
        $sqlState = RetryingAtomicTransaction::sqlStateOf($exception);
    }

    DB::statement('set lock_timeout = default');

    $reads = array_values(array_filter(
        $executed,
        fn (string $sql): bool => str_contains($sql, 'payment_outbox')
            && str_starts_with(trim(strtolower($sql)), 'select'),
    ));

    expect($sqlState)->toBe('55P03')
        ->and($reads)->toBe([])
        ->and(operatorRow($eventId)['exhausted_at'])->not->toBeNull();

    $lock->rollBack();

    expect(operatorRetry($eventId))->toBe(0)
        ->and(operatorRow($eventId)['exhausted_at'])->toBeNull();
});

it('leaves both transaction bookkeepings clean after a refusal and after a reset', function (): void {
    $refused = operatorEnvelope();
    $resettable = operatorEnvelope(failureCount: 5, exhaustedAt: '2026-08-21T09:00:00+00:00');

    operatorRetry($refused);

    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    operatorRetry($resettable);

    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});
