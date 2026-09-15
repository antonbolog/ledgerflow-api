<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Infrastructure\Projection\PaymentProjectionConsumer;
use Modules\Payments\Infrastructure\Projection\ProjectionConfiguration;
use Modules\Payments\Infrastructure\Projection\ProjectionEnvelopeMissing;
use Modules\Payments\Infrastructure\Projection\ProjectionEnvelopeRejected;
use Modules\Payments\Infrastructure\Projection\ProjectionFailureRecorder;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Modules\Payments\Infrastructure\Projection\ProjectionOutcome;
use Modules\Payments\Tests\Integration\IntegrationDatabase;
use Modules\Payments\Tests\Integration\ProjectionGateLocks;

function projectionEnvelopeRow(string $eventId): array
{
    $row = DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_outbox')
        ->where('event_id', $eventId)
        ->first();

    expect($row)->not->toBeNull("no committed payment_outbox row for {$eventId}");

    return (array) $row;
}

function projectionRecorderColumns(array $row): array
{
    return [
        'projection_failure_count' => (int) $row['projection_failure_count'],
        'projection_failure_generation' => (int) $row['projection_failure_generation'],
        'last_projection_error' => $row['last_projection_error'],
        'exhausted_at' => $row['exhausted_at'],
    ];
}

function projectionTransportColumns(array $row): array
{
    return [
        'publish_count' => (int) $row['publish_count'],
        'consecutive_transport_failures' => (int) $row['consecutive_transport_failures'],
        'last_transport_error' => $row['last_transport_error'],
        'last_published_at' => $row['last_published_at'],
        'available_at' => $row['available_at'],
    ];
}

function projectionReadRow(string $eventId): ?array
{
    $row = DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_details')
        ->where('source_event_id', $eventId)
        ->first();

    return $row === null ? null : (array) $row;
}

function projectionReceiptCount(string $eventId): int
{
    return DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_projector_receipts')
        ->where('event_id', $eventId)
        ->count();
}

/**
 * @param  array<string, mixed>  $payloadOverrides
 */
function projectionEnvelope(
    ?int $merchantId = null,
    ?string $eventId = null,
    ?string $paymentId = null,
    string $eventType = 'payments.payment_initiated',
    int $schemaVersion = 1,
    int $aggregateVersion = 1,
    string $occurredAt = '2026-08-21T10:11:12.123456+00:00',
    ?string $projectionCompletedAt = null,
    ?string $exhaustedAt = null,
    array $payloadOverrides = [],
    ?string $rawPayload = null,
): string {
    $eventId ??= (string) Str::uuid7();
    $paymentId ??= (string) Str::uuid7();
    $merchantId ??= Merchant::factory()->create()->getKey();

    $payload = $rawPayload ?? json_encode(array_merge([
        'eventId' => $eventId,
        'paymentId' => $paymentId,
        'merchantId' => $merchantId,
        'amountMinor' => 1_250,
        'currency' => 'USD',
        'occurredAt' => $occurredAt,
    ], $payloadOverrides));

    DB::table('payment_outbox')->insert([
        'event_id' => $eventId,
        'event_type' => $eventType,
        'schema_version' => $schemaVersion,
        'aggregate_uuid' => $paymentId,
        'aggregate_version' => $aggregateVersion,
        'payload' => $payload,
        'occurred_at' => $occurredAt,
        'available_at' => now(),
        'projection_completed_at' => $projectionCompletedAt,
        'exhausted_at' => $exhaustedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $eventId;
}

function projectionConsumer(): PaymentProjectionConsumer
{
    return resolve(PaymentProjectionConsumer::class);
}

/** An ArrayObject, so later assertions read the live collection. */
function projectionCapturedLogs(): ArrayObject
{
    $records = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $logged) use ($records): void {
        $records[] = ['level' => $logged->level, 'message' => $logged->message, 'context' => $logged->context];
    });

    return $records;
}

/** @return list<array<string, mixed>> */
function projectionLogContexts(ArrayObject $records): array
{
    return array_values(array_column($records->getArrayCopy(), 'context'));
}

beforeEach(function (): void {
    IntegrationDatabase::ensureSchema();
    IntegrationDatabase::register();
    IntegrationDatabase::refuseIfWrapped();
    IntegrationDatabase::truncate();

    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse()
        ->and(DB::table('payment_outbox')->count())->toBe(0)
        ->and(DB::table('payment_projector_receipts')->count())->toBe(0)
        ->and(DB::table('payment_details')->count())->toBe(0);
});

afterEach(function (): void {
    DB::statement('set lock_timeout = default');

    IntegrationDatabase::rollbackOpenTransactions();
    IntegrationDatabase::releaseLocks();
    IntegrationDatabase::truncate();
    IntegrationDatabase::disconnectAll();
});

it('commits receipt, read row and completion marker together on a first delivery', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();
    $eventId = projectionEnvelope(merchantId: $merchantId, paymentId: $paymentId);

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::Projected);

    // Equal xmin values prove one transaction wrote the receipt, the read row and the marker.
    $xmin = fn (string $sql): ?string => DB::connection(IntegrationDatabase::OBSERVER)
        ->selectOne($sql, [$eventId])?->x;

    $receiptXmin = $xmin('select xmin::text as x from payment_projector_receipts where event_id = ?');
    $detailsXmin = $xmin('select xmin::text as x from payment_details where source_event_id = ?');
    $markerXmin = $xmin('select xmin::text as x from payment_outbox where event_id = ?');

    expect($receiptXmin)->not->toBeNull()
        ->and($detailsXmin)->toBe($receiptXmin)
        ->and($markerXmin)->toBe($receiptXmin);

    $readRow = projectionReadRow($eventId);

    expect($readRow)->not->toBeNull()
        ->and($readRow['payment_id'])->toBe($paymentId)
        ->and((int) $readRow['merchant_id'])->toBe($merchantId)
        ->and((int) $readRow['amount_minor'])->toBe(1_250)
        ->and(trim((string) $readRow['currency']))->toBe('USD')
        ->and($readRow['status'])->toBe('initiated')
        ->and((int) $readRow['aggregate_version'])->toBe(1);

    // Business time from the payload, to the microsecond, never processing time.
    expect(DB::connection(IntegrationDatabase::OBSERVER)->selectOne(
        'select initiated_at = ?::timestamptz as matches from payment_details where source_event_id = ?',
        ['2026-08-21T10:11:12.123456+00:00', $eventId],
    )?->matches)->toBeTrue();

    $envelope = projectionEnvelopeRow($eventId);

    expect($envelope['projection_completed_at'])->not->toBeNull()
        ->and(projectionReceiptCount($eventId))->toBe(1);

    expect(projectionRecorderColumns($envelope))->toBe([
        'projection_failure_count' => 0,
        'projection_failure_generation' => 0,
        'last_projection_error' => null,
        'exhausted_at' => null,
    ]);

    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});

it('writes the receipt under its own projector name', function (): void {
    // A literal, not the constant: the projector name is part of the durable receipt key.
    $eventId = projectionEnvelope();

    projectionConsumer()->project($eventId);

    expect(DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_projector_receipts')
        ->where('event_id', $eventId)
        ->value('projector_name'))->toBe('payment_details')
        ->and(PaymentProjectionConsumer::PROJECTOR_NAME)->toBe('payment_details');
});

it('leaves an exact duplicate delivery entirely alone', function (): void {
    $eventId = projectionEnvelope();

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::Projected);

    $afterFirst = projectionEnvelopeRow($eventId);
    $readRowAfterFirst = projectionReadRow($eventId);

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::AlreadyProjected);

    expect(projectionReadRow($eventId))->toBe($readRowAfterFirst)
        ->and(projectionEnvelopeRow($eventId))->toBe($afterFirst)
        ->and(projectionReceiptCount($eventId))->toBe(1)
        ->and(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_details')->count())->toBe(1);
});

it('repairs only a missing completion marker on a duplicate delivery', function (): void {
    $eventId = projectionEnvelope();

    projectionConsumer()->project($eventId);

    DB::table('payment_outbox')->where('event_id', $eventId)->update(['projection_completed_at' => null]);

    $readRowBefore = projectionReadRow($eventId);

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::MarkerRepaired);

    $envelope = projectionEnvelopeRow($eventId);

    expect($envelope['projection_completed_at'])->not->toBeNull()
        ->and(projectionReadRow($eventId))->toBe($readRowBefore)
        ->and(projectionReceiptCount($eventId))->toBe(1)
        ->and(projectionRecorderColumns($envelope))->toBe([
            'projection_failure_count' => 0,
            'projection_failure_generation' => 0,
            'last_projection_error' => null,
            'exhausted_at' => null,
        ]);
});

it('does not move a completion marker that is already set', function (): void {
    $eventId = projectionEnvelope();

    projectionConsumer()->project($eventId);

    $firstMarker = projectionEnvelopeRow($eventId)['projection_completed_at'];

    projectionConsumer()->project($eventId);

    expect(projectionEnvelopeRow($eventId)['projection_completed_at'])->toBe($firstMarker);
});

it('refuses a duplicate whose read row is missing rather than recreating it', function (): void {
    $eventId = projectionEnvelope();

    projectionConsumer()->project($eventId);

    DB::table('payment_details')->where('source_event_id', $eventId)->delete();
    DB::table('payment_outbox')->where('event_id', $eventId)->update(['projection_completed_at' => null]);

    try {
        projectionConsumer()->project($eventId);
        $category = null;
    } catch (ProjectionEnvelopeRejected $rejected) {
        $category = $rejected->category;
    }

    expect($category)->toBe(ProjectionEnvelopeRejected::READ_ROW_MISSING)
        ->and(projectionReadRow($eventId))->toBeNull()
        ->and(projectionEnvelopeRow($eventId)['projection_completed_at'])->toBeNull()
        ->and(projectionReceiptCount($eventId))->toBe(1);
});

it('treats an exhausted envelope as an observability-only no-op', function (): void {
    $records = projectionCapturedLogs();
    $eventId = projectionEnvelope(exhaustedAt: '2026-08-21T10:00:00+00:00');

    $before = projectionEnvelopeRow($eventId);

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::SkippedExhausted);

    expect(projectionReceiptCount($eventId))->toBe(0)
        ->and(projectionReadRow($eventId))->toBeNull()
        ->and(projectionEnvelopeRow($eventId))->toBe($before);

    $skipped = array_values(array_filter(
        projectionLogContexts($records),
        fn (array $context): bool => ($context['category'] ?? null) === 'projection_skipped_exhausted',
    ));

    expect($skipped)->toHaveCount(1)
        ->and($skipped[0]['event_id'])->toBe($eventId)
        ->and($skipped[0]['projection_failure_generation'])->toBe(0);

    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->statement("set lock_timeout = '250ms'");
    $lock->beginTransaction();
    $lock->select('select event_id from payment_outbox where event_id = ? for update', [$eventId]);
    $lock->rollBack();
});

it('fails visibly when the delivered event id addresses no envelope', function (): void {
    $records = projectionCapturedLogs();
    $eventId = (string) Str::uuid7();

    expect(fn () => projectionConsumer()->project($eventId))
        ->toThrow(ProjectionEnvelopeMissing::class);

    expect(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_outbox')->count())->toBe(0)
        ->and(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_projector_receipts')->count())->toBe(0)
        ->and(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_details')->count())->toBe(0);

    $aborted = array_values(array_filter(
        projectionLogContexts($records),
        fn (array $context): bool => ($context['category'] ?? null) === 'projection_failed_before_commit',
    ));

    expect($aborted)->toHaveCount(1)
        ->and($aborted[0]['exception_class'])->toBe(ProjectionEnvelopeMissing::class)
        ->and($aborted[0]['commit_attempted'])->toBeFalse()
        ->and($aborted[0]['projection_failure_generation'])->toBeNull();
});

it('refuses a malformed or unsupported envelope and writes nothing', function (
    array $envelope,
    string $category,
): void {
    $eventId = projectionEnvelope(...$envelope);

    $before = projectionEnvelopeRow($eventId);

    try {
        projectionConsumer()->project($eventId);
        $actual = null;
    } catch (ProjectionEnvelopeRejected $rejected) {
        $actual = $rejected->category;
    }

    expect($actual)->toBe($category)
        ->and(projectionReceiptCount($eventId))->toBe(0)
        ->and(projectionReadRow($eventId))->toBeNull()
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    $after = projectionEnvelopeRow($eventId);

    expect((int) $after['projection_failure_count'])->toBe(1)
        ->and($after['last_projection_error'])->toBe($category)
        ->and($after['exhausted_at'])->toBeNull()
        ->and($after['projection_completed_at'])->toBeNull()
        ->and(projectionTransportColumns($after))->toBe(projectionTransportColumns($before));
})->with([
    'another event alias' => [
        ['eventType' => 'payments.payment_authorized'],
        ProjectionEnvelopeRejected::UNSUPPORTED_ALIAS,
    ],
    'a future schema version' => [
        ['schemaVersion' => 2],
        ProjectionEnvelopeRejected::UNSUPPORTED_SCHEMA_VERSION,
    ],
    'a later aggregate version' => [
        ['aggregateVersion' => 2],
        ProjectionEnvelopeRejected::UNSUPPORTED_AGGREGATE_VERSION,
    ],
    'a payload that is not an object' => [
        ['rawPayload' => '"not an object"'],
        ProjectionEnvelopeRejected::MALFORMED_PAYLOAD,
    ],
    'a payload missing a durable key' => [
        ['rawPayload' => '{"eventId":"x","paymentId":"y","merchantId":1,"amountMinor":1,"currency":"USD"}'],
        ProjectionEnvelopeRejected::MALFORMED_PAYLOAD,
    ],
    'a payload carrying an extra key' => [
        ['payloadOverrides' => ['refundedAt' => null]],
        ProjectionEnvelopeRejected::MALFORMED_PAYLOAD,
    ],
    'a stringified amount' => [
        ['payloadOverrides' => ['amountMinor' => '1250']],
        ProjectionEnvelopeRejected::MALFORMED_PAYLOAD,
    ],
    'a non-positive amount' => [
        ['payloadOverrides' => ['amountMinor' => 0]],
        ProjectionEnvelopeRejected::MALFORMED_PAYLOAD,
    ],
    'a non-positive merchant' => [
        ['payloadOverrides' => ['merchantId' => 0]],
        ProjectionEnvelopeRejected::MALFORMED_PAYLOAD,
    ],
    'a currency that is not three uppercase letters' => [
        ['payloadOverrides' => ['currency' => 'usd']],
        ProjectionEnvelopeRejected::MALFORMED_PAYLOAD,
    ],
    'an occurredAt outside the durable format' => [
        ['payloadOverrides' => ['occurredAt' => '2026-08-21 10:11:12']],
        ProjectionEnvelopeRejected::MALFORMED_PAYLOAD,
    ],
    'an occurredAt that disagrees with its column' => [
        ['payloadOverrides' => ['occurredAt' => '2026-08-21T10:11:12.999999+00:00']],
        ProjectionEnvelopeRejected::MALFORMED_PAYLOAD,
    ],
    'a payload event id that is not the row event id' => [
        ['payloadOverrides' => ['eventId' => '0197c9b0-1111-7000-9000-00000000aaaa']],
        ProjectionEnvelopeRejected::CONTRADICTORY_IDENTITY,
    ],
    'a payload payment id that is not the aggregate' => [
        ['payloadOverrides' => ['paymentId' => '0197c9b0-2222-7000-9000-00000000bbbb']],
        ProjectionEnvelopeRejected::CONTRADICTORY_IDENTITY,
    ],
]);

it('never lets a rejection message carry a payload value', function (): void {
    $eventId = projectionEnvelope(payloadOverrides: ['currency' => 'zzz']);

    try {
        projectionConsumer()->project($eventId);
        $message = '';
    } catch (ProjectionEnvelopeRejected $rejected) {
        $message = $rejected->getMessage();
    }

    expect($message)->toContain('currency')
        ->and($message)->not->toContain('zzz');
});

it('refuses to overwrite a read row another writer already claimed', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();

    DB::table('payment_details')->insert([
        'payment_id' => $paymentId,
        'source_event_id' => (string) Str::uuid7(),
        'merchant_id' => $merchantId,
        'amount_minor' => 9_999,
        'currency' => 'USD',
        'status' => 'initiated',
        'aggregate_version' => 1,
        'initiated_at' => '2026-08-20T09:00:00.000000+00:00',
    ]);

    $foreignRow = (array) DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_details')->where('payment_id', $paymentId)->first();

    $eventId = projectionEnvelope(merchantId: $merchantId, paymentId: $paymentId);

    expect(fn () => projectionConsumer()->project($eventId))
        ->toThrow(UniqueConstraintViolationException::class, 'payment_details_pkey');

    expect((array) DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_details')->where('payment_id', $paymentId)->first())->toBe($foreignRow)
        ->and(projectionReceiptCount($eventId))->toBe(0)
        ->and(projectionEnvelopeRow($eventId)['projection_completed_at'])->toBeNull()
        ->and(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_details')->count())->toBe(1);
});

it('cannot be handed two envelopes for one payment in the first place', function (): void {
    $paymentId = (string) Str::uuid7();

    projectionEnvelope(paymentId: $paymentId);

    expect(fn () => projectionEnvelope(paymentId: $paymentId))
        ->toThrow(UniqueConstraintViolationException::class, 'payment_outbox_aggregate_version_unique');
});

it('rolls the whole transaction back when the read row insert fails', function (): void {
    $eventId = projectionEnvelope(merchantId: 987_654_321);

    $before = projectionEnvelopeRow($eventId);

    expect(fn () => projectionConsumer()->project($eventId))->toThrow(QueryException::class);

    expect(projectionReceiptCount($eventId))->toBe(0)
        ->and(projectionReadRow($eventId))->toBeNull()
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    $after = projectionEnvelopeRow($eventId);

    expect((int) $after['projection_failure_count'])->toBe(1)
        ->and(projectionTransportColumns($after))->toBe(projectionTransportColumns($before));
});

it('reports a foreign key violation as 23503 rather than by matching its message', function (): void {
    $eventId = projectionEnvelope(merchantId: 987_654_321);

    try {
        projectionConsumer()->project($eventId);
        $sqlState = null;
    } catch (QueryException $exception) {
        $sqlState = RetryingAtomicTransaction::sqlStateOf($exception);
    }

    expect($sqlState)->toBe('23503');
});

it('refuses a duplicate whose read row describes a different payment', function (): void {
    $eventId = projectionEnvelope();

    projectionConsumer()->project($eventId);

    DB::table('payment_outbox')->where('event_id', $eventId)->update(['projection_completed_at' => null]);
    DB::table('payment_details')
        ->where('source_event_id', $eventId)
        ->update(['payment_id' => (string) Str::uuid7()]);

    try {
        projectionConsumer()->project($eventId);
        $category = null;
    } catch (ProjectionEnvelopeRejected $rejected) {
        $category = $rejected->category;
    }

    expect($category)->toBe(ProjectionEnvelopeRejected::READ_ROW_MISMATCH)
        ->and(projectionEnvelopeRow($eventId)['projection_completed_at'])->toBeNull();
});

it('classifies a failure raised before the commit as a definite rollback', function (): void {
    // `committing` fires before the PDO commit, so nothing can have reached disk.
    $records = projectionCapturedLogs();
    $eventId = projectionEnvelope();

    Event::listen(TransactionCommitting::class, function (): void {
        throw new RuntimeException('committing listener refused');
    });

    expect(fn () => projectionConsumer()->project($eventId))->toThrow(RuntimeException::class);

    $aborted = array_values(array_filter(
        projectionLogContexts($records),
        fn (array $context): bool => in_array(
            $context['category'] ?? null,
            ['projection_failed_before_commit', 'projection_commit_outcome_unknown'],
            true,
        ),
    ));

    expect($aborted)->toHaveCount(1)
        ->and($aborted[0]['category'])->toBe('projection_failed_before_commit')
        ->and($aborted[0]['commit_attempted'])->toBeTrue();

    expect(projectionReceiptCount($eventId))->toBe(0)
        ->and(projectionReadRow($eventId))->toBeNull()
        ->and(projectionEnvelopeRow($eventId)['projection_completed_at'])->toBeNull();
});

it('classifies a failure after a landed commit as an unknown commit outcome', function (): void {
    // `committed` fires after the PDO commit: the work is durable and must not be charged.
    $records = projectionCapturedLogs();
    $eventId = projectionEnvelope();

    Event::listen(TransactionCommitted::class, function (): void {
        throw new RuntimeException('committed listener refused');
    });

    expect(fn () => projectionConsumer()->project($eventId))->toThrow(RuntimeException::class);

    $aborted = array_values(array_filter(
        projectionLogContexts($records),
        fn (array $context): bool => in_array(
            $context['category'] ?? null,
            ['projection_failed_before_commit', 'projection_commit_outcome_unknown'],
            true,
        ),
    ));

    expect($aborted)->toHaveCount(1)
        ->and($aborted[0]['category'])->toBe('projection_commit_outcome_unknown')
        ->and($aborted[0]['commit_attempted'])->toBeTrue();

    expect(projectionReceiptCount($eventId))->toBe(1)
        ->and(projectionReadRow($eventId))->not->toBeNull()
        ->and(projectionEnvelopeRow($eventId)['projection_completed_at'])->not->toBeNull();

    // The recorder's own guard would also refuse this row, so only absent recorder log lines prove it was never called.
    $recorderLines = array_values(array_filter(
        projectionLogContexts($records),
        fn (array $context): bool => in_array(
            $context['category'] ?? null,
            ['projection_failure_recorded', 'projection_exhausted', 'projection_failure_stale',
                'projection_failure_not_recorded'],
            true,
        ),
    ));

    expect($recorderLines)->toBe([]);

    $row = projectionEnvelopeRow($eventId);

    expect((int) $row['projection_failure_count'])->toBe(0)
        ->and($row['last_projection_error'])->toBeNull()
        ->and($row['exhausted_at'])->toBeNull();
});

it('classifies a COMMIT that itself throws as an unknown outcome, not as a rollback', function (): void {
    // transactionLevel() still reports 1 when COMMIT itself throws, so it cannot identify a rollback.
    // A deferred constraint trigger fails the COMMIT deterministically; terminating the backend races the commit.
    $records = projectionCapturedLogs();
    $eventId = projectionEnvelope();

    DB::unprepared(<<<'SQL'
        create function projection_commit_probe() returns trigger language plpgsql as $$
        begin
            raise exception 'commit probe refused the deferred check';
        end;
        $$;

        create constraint trigger projection_commit_probe_trigger
            after insert on payment_details
            deferrable initially deferred
            for each row execute function projection_commit_probe();
    SQL);

    $thrown = null;

    try {
        projectionConsumer()->project($eventId);
    } catch (Throwable $failure) {
        $thrown = $failure;
    } finally {
        // Left behind, the trigger would fail every later projection.
        DB::unprepared(
            'drop trigger if exists projection_commit_probe_trigger on payment_details;'
            .'drop function if exists projection_commit_probe();'
        );
    }

    expect($thrown)->not->toBeNull('the deferred commit probe did not fail the COMMIT');

    $aborted = array_values(array_filter(
        projectionLogContexts($records),
        fn (array $context): bool => in_array(
            $context['category'] ?? null,
            ['projection_failed_before_commit', 'projection_commit_outcome_unknown'],
            true,
        ),
    ));

    expect($aborted)->toHaveCount(1)
        ->and($aborted[0]['category'])->toBe('projection_commit_outcome_unknown')
        ->and($aborted[0]['commit_attempted'])->toBeTrue();

    expect(projectionReceiptCount($eventId))->toBe(0)
        ->and(projectionReadRow($eventId))->toBeNull();
});

it('carries the SQLSTATE of a database failure into the abort log', function (): void {
    $records = projectionCapturedLogs();
    $eventId = projectionEnvelope(merchantId: 987_654_321);

    try {
        projectionConsumer()->project($eventId);
    } catch (QueryException) {
    }

    $aborted = array_values(array_filter(
        projectionLogContexts($records),
        fn (array $context): bool => ($context['category'] ?? null) === 'projection_failed_before_commit',
    ));

    expect($aborted)->toHaveCount(1)
        ->and($aborted[0]['sql_state'])->toBe('23503');

    expect(array_keys($aborted[0]))->toContain('sql_state');
});

it('applies its statement timeout inside the transaction and lets it die with it', function (): void {
    $eventId = projectionEnvelope();

    $inside = null;

    Event::listen(TransactionCommitting::class, function () use (&$inside): void {
        $inside = DB::selectOne('show statement_timeout')?->statement_timeout;
    });

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::Projected);

    expect($inside)->toBe('2s');

    // Gone afterwards: SET LOCAL, never a session-level SET on a long-lived worker connection.
    expect(DB::selectOne('show statement_timeout')?->statement_timeout)->toBe('0');
});

it('cancels a statement that outlives the bound rather than waiting for the worker', function (): void {
    // A tiny bound against a real row lock: 57014 must fire before the worker's own timeout could kill the process.
    $eventId = projectionEnvelope();

    app()->bind(PaymentProjectionConsumer::class, fn (): PaymentProjectionConsumer => new PaymentProjectionConsumer(
        DB::connection(),
        ProjectionConfiguration::fromArray(
            array_merge((array) config('payments.projection'), ['statement_timeout_ms' => 150]),
            (array) config('queue.connections.payments-projection'),
        ),
        resolve(ProjectionFailureRecorder::class),
        Log::getFacadeRoot(),
        new ProjectionGate(DB::connection()),
    ));

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->select('select event_id from payment_outbox where event_id = ? for update', [$eventId]);

    // A session-level floor bounds the wait if the consumer's own bound stops applying; elapsed time tells the two apart.
    DB::statement("set statement_timeout = '5s'");

    $sqlState = null;
    $startedAt = hrtime(true);

    try {
        resolve(PaymentProjectionConsumer::class)->project($eventId);
    } catch (QueryException $exception) {
        $sqlState = RetryingAtomicTransaction::sqlStateOf($exception);
    }

    $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

    DB::statement('set statement_timeout = default');
    $lock->rollBack();

    expect($elapsedMs)->toBeLessThan(2_000)
        ->and($sqlState)->toBe('57014')
        ->and(projectionReceiptCount($eventId))->toBe(0)
        ->and((int) projectionEnvelopeRow($eventId)['projection_failure_count'])->toBe(0);
});

it('serialises a concurrent duplicate on the envelope row lock', function (): void {
    $eventId = projectionEnvelope();

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->select('select event_id from payment_outbox where event_id = ? for update', [$eventId]);

    DB::statement("set lock_timeout = '250ms'");

    // The marker UPDATE would also raise 55P03, so only an empty write log proves the envelope lock serialised first.
    // Queries are logged only after they succeed.
    $executed = [];
    DB::listen(function ($query) use (&$executed): void {
        $executed[] = $query->sql;
    });

    try {
        projectionConsumer()->project($eventId);
        $sqlState = null;
    } catch (QueryException $exception) {
        $sqlState = RetryingAtomicTransaction::sqlStateOf($exception);
    }

    DB::statement('set lock_timeout = default');

    $writes = array_values(array_filter(
        $executed,
        fn (string $sql): bool => str_contains($sql, 'payment_projector_receipts')
            || str_contains($sql, 'payment_details')
            || str_contains($sql, 'update payment_outbox'),
    ));

    expect($sqlState)->toBe('55P03')
        ->and($writes)->toBe([])
        ->and(projectionReceiptCount($eventId))->toBe(0)
        ->and(projectionReadRow($eventId))->toBeNull();

    $lock->rollBack();

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::Projected);

    expect(projectionReceiptCount($eventId))->toBe(1)
        ->and(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_details')->count())->toBe(1);
});

it('converges to one receipt, one read row and one marker when a duplicate arrives after the winner', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();
    $eventId = projectionEnvelope(merchantId: $merchantId, paymentId: $paymentId);

    $winner = DB::connection(IntegrationDatabase::LOCK);
    $winner->beginTransaction();
    $winner->select('select event_id from payment_outbox where event_id = ? for update', [$eventId]);
    $winner->insert(
        'insert into payment_projector_receipts (projector_name, event_id, created_at) values (?, ?, now())',
        [PaymentProjectionConsumer::PROJECTOR_NAME, $eventId],
    );
    $winner->insert(
        <<<'SQL'
        insert into payment_details (
            payment_id, source_event_id, merchant_id, amount_minor, currency, status,
            aggregate_version, initiated_at
        ) values (?, ?, ?, ?, ?, ?, ?, ?::timestamptz)
        SQL,
        [$paymentId, $eventId, $merchantId, 1_250, 'USD', 'initiated', 1, '2026-08-21T10:11:12.123456+00:00'],
    );
    $winner->update(
        'update payment_outbox set projection_completed_at = now(), updated_at = now() where event_id = ?',
        [$eventId],
    );
    $winner->commit();

    $afterWinner = projectionReadRow($eventId);

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::AlreadyProjected);

    expect(projectionReceiptCount($eventId))->toBe(1)
        ->and(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_details')->count())->toBe(1)
        ->and(projectionReadRow($eventId))->toBe($afterWinner);

    $envelope = projectionEnvelopeRow($eventId);

    expect(projectionRecorderColumns($envelope))->toBe([
        'projection_failure_count' => 0,
        'projection_failure_generation' => 0,
        'last_projection_error' => null,
        'exhausted_at' => null,
    ]);
});

it('never writes a column the relay or the failure recorder owns', function (): void {
    $eventId = projectionEnvelope();

    $before = projectionEnvelopeRow($eventId);

    projectionConsumer()->project($eventId);

    $afterFirst = projectionEnvelopeRow($eventId);

    DB::table('payment_outbox')->where('event_id', $eventId)->update(['projection_completed_at' => null]);

    projectionConsumer()->project($eventId);

    $afterRepair = projectionEnvelopeRow($eventId);

    foreach ([$afterFirst, $afterRepair] as $snapshot) {
        expect(projectionTransportColumns($snapshot))->toBe(projectionTransportColumns($before))
            ->and(projectionRecorderColumns($snapshot))->toBe(projectionRecorderColumns($before));
    }
});

it('captures the failure generation the row carried under the lock', function (): void {
    $records = projectionCapturedLogs();
    $eventId = projectionEnvelope(merchantId: 987_654_321);

    DB::table('payment_outbox')->where('event_id', $eventId)->update(['projection_failure_generation' => 3]);

    // Exactly one envelope read, the locking SELECT: re-reading the generation after rollback would log 3 too.
    $selects = [];
    DB::listen(function ($query) use (&$selects): void {
        if (str_starts_with(trim(strtolower($query->sql)), 'select') && str_contains($query->sql, 'payment_outbox')) {
            $selects[] = $query->sql;
        }
    });

    try {
        projectionConsumer()->project($eventId);
    } catch (QueryException) {
    }

    $aborted = array_values(array_filter(
        projectionLogContexts($records),
        fn (array $context): bool => ($context['category'] ?? null) === 'projection_failed_before_commit',
    ));

    expect($aborted)->toHaveCount(1)
        ->and($aborted[0]['projection_failure_generation'])->toBe(3)
        ->and($aborted[0]['commit_attempted'])->toBeFalse();

    expect($selects)->toHaveCount(1)
        ->and($selects[0])->toContain('for update');
});

it('keeps raw exception text and payload values out of the abort log', function (): void {
    $records = projectionCapturedLogs();
    $eventId = projectionEnvelope(payloadOverrides: ['currency' => 'zzz']);

    try {
        projectionConsumer()->project($eventId);
    } catch (ProjectionEnvelopeRejected) {
    }

    $aborted = array_values(array_filter(
        projectionLogContexts($records),
        fn (array $context): bool => ($context['category'] ?? null) === 'projection_failed_before_commit',
    ));

    expect($aborted)->toHaveCount(1)
        ->and(array_keys($aborted[0]))->toEqualCanonicalizing([
            'category', 'failure_category', 'exception_class', 'sql_state', 'event_id',
            'projector_name', 'projection_failure_generation',
            'transaction_started', 'commit_attempted',
        ])
        ->and($aborted[0]['failure_category'])->toBe(ProjectionEnvelopeRejected::MALFORMED_PAYLOAD);

    $rendered = json_encode($aborted[0]);

    expect($rendered)->not->toContain('zzz')
        ->and($rendered)->not->toContain('malformed:');
});

function projectionCloseGate(): void
{
    DB::table('payment_projection_controls')->where('projection', 'payment_details')->update([
        'state' => 'rebuilding',
        'owner_token' => (string) Str::uuid7(),
        'owner_pid' => 999_999,
        'started_at' => now(),
        'updated_at' => now(),
    ]);
}

function projectionReopenGate(): void
{
    DB::table('payment_projection_controls')->where('projection', 'payment_details')->update([
        'state' => 'ready',
        'owner_token' => null,
        'owner_pid' => null,
        'started_at' => null,
        'cleared_at' => null,
        'updated_at' => now(),
    ]);
}

it('defers a delivery without locking the envelope, throwing or writing anything', function (): void {
    $eventId = projectionEnvelope();
    $before = projectionEnvelopeRow($eventId);

    projectionCloseGate();

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::GateDeferred);

    expect(projectionReceiptCount($eventId))->toBe(0)
        ->and(projectionReadRow($eventId))->toBeNull()
        ->and(projectionEnvelopeRow($eventId))->toBe($before)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->statement("set lock_timeout = '250ms'");
    $lock->beginTransaction();
    $lock->select('select event_id from payment_outbox where event_id = ? for update', [$eventId]);
    $lock->rollBack();

    projectionReopenGate();
});

it('leaves every column the recorder and the relay own untouched by a deferral', function (): void {
    $eventId = projectionEnvelope();
    $before = projectionEnvelopeRow($eventId);

    projectionCloseGate();
    projectionConsumer()->project($eventId);
    projectionReopenGate();

    $after = projectionEnvelopeRow($eventId);

    expect(projectionRecorderColumns($after))->toBe(projectionRecorderColumns($before))
        ->and(projectionTransportColumns($after))->toBe(projectionTransportColumns($before))
        ->and($after['available_at'])->toBe($before['available_at']);
});

it('is a distinct outcome from every other case, so a deferral cannot be mistaken for progress', function (): void {
    $eventId = projectionEnvelope();

    projectionCloseGate();

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::GateDeferred)
        ->and(ProjectionOutcome::GateDeferred)->not->toBe(ProjectionOutcome::Projected)
        ->and(ProjectionOutcome::GateDeferred)->not->toBe(ProjectionOutcome::SkippedExhausted);

    projectionReopenGate();

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::Projected);
});

it('holds the gate lock on its own connection while a delivery is deferred by the control row', function (): void {
    $eventId = projectionEnvelope();
    projectionCloseGate();

    $lockDuringCommit = [];

    Event::listen(TransactionCommitting::class, function () use (&$lockDuringCommit): void {
        $lockDuringCommit = ProjectionGateLocks::onKey(
            ProjectionGate::CLASS_ID,
            ProjectionGate::PAYMENT_DETAILS_OBJECT_ID,
        );
    });

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::GateDeferred);

    Event::forget(TransactionCommitting::class);

    expect($lockDuringCommit)->toHaveCount(1)
        ->and($lockDuringCommit[0]['mode'])->toBe('ShareLock')
        ->and($lockDuringCommit[0]['granted'])->toBeTrue();

    $consumerConnection = new ReflectionProperty(PaymentProjectionConsumer::class, 'connection')
        ->getValue(projectionConsumer());
    expect($consumerConnection)->toBe(DB::connection());

    projectionReopenGate();
});
