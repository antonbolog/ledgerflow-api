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

/*
|--------------------------------------------------------------------------
| The Payments projection consumer — one deterministic transaction
|--------------------------------------------------------------------------
|
| WHY THIS FILE CANNOT LIVE IN THE FEATURE SUITE, for the reason OutboxRelayTest
| already records: RefreshDatabase wraps every Feature test in a transaction, so
| the consumer's explicit beginTransaction()/commit() becomes a SAVEPOINT. Under a
| wrapper no second connection can witness a COMMIT, so "receipt, read row and
| marker committed together", "rolled back to the exact pre-delivery state" and
| every concurrency claim below would pass against a consumer that opened no
| transaction at all.
|
| EVERY DURABLE CLAIM IS READ FROM THE OBSERVER, a second PostgreSQL backend. A row
| it can see genuinely left the consumer's transaction.
|
| NO SLEEPS. The concurrency evidence is built from a real FOR UPDATE held by the
| LOCK connection and bounded by PostgreSQL's own lock_timeout, so the test is
| synchronised by the database rather than by hoping.
|
| WHAT THIS FILE DELIBERATELY DOES NOT ASSERT: failure counting, last_projection_error
| and exhausted_at are written by ProjectionFailureRecorder, which this file does not exercise.
| Every test below therefore asserts that the four columns it does not own are
| UNCHANGED, which is the strongest claim available in this slice and the one that
| would catch the consumer growing a second owner of them.
|
*/

/** Every column of one envelope, read from the OBSERVER so only committed state counts. */
function projectionEnvelopeRow(string $eventId): array
{
    $row = DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_outbox')
        ->where('event_id', $eventId)
        ->first();

    expect($row)->not->toBeNull("no committed payment_outbox row for {$eventId}");

    return (array) $row;
}

/**
 * The columns the failure recorder owns, compared as a whole.
 *
 * As a group rather than one at a time, for the reason OutboxRelayTest gives about
 * T8's columns: a consumer that learned to write exhausted_at would otherwise be
 * caught only by whichever test happened to mention that column.
 */
function projectionRecorderColumns(array $row): array
{
    return [
        'projection_failure_count' => (int) $row['projection_failure_count'],
        'projection_failure_generation' => (int) $row['projection_failure_generation'],
        'last_projection_error' => $row['last_projection_error'],
        'exhausted_at' => $row['exhausted_at'],
    ];
}

/** The transport columns the relay owns, which the consumer must never touch. */
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

/** The committed read row for one source event, or null. */
function projectionReadRow(string $eventId): ?array
{
    $row = DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_details')
        ->where('source_event_id', $eventId)
        ->first();

    return $row === null ? null : (array) $row;
}

/** Committed projector receipts for one event, across every projector name. */
function projectionReceiptCount(string $eventId): int
{
    return DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_projector_receipts')
        ->where('event_id', $eventId)
        ->count();
}

/**
 * One committed envelope, shaped exactly as PaymentOutboxWriter leaves it.
 *
 * A REAL MERCHANT, unlike the relay suite's fixture: payment_details carries a
 * restrictive foreign key to merchants, so the projection cannot be proved against
 * an invented id the way transport state could.
 *
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

/** The consumer as the application resolves it, so the provider binding is exercised. */
function projectionConsumer(): PaymentProjectionConsumer
{
    return resolve(PaymentProjectionConsumer::class);
}

/** Start capturing log records; an ArrayObject so later assertions read the live collection. */
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
    // The session-level bound the concurrency tests set. Restored unconditionally:
    // the default connection outlives this suite and a leaked lock_timeout would
    // turn an unrelated later test into an intermittent 55P03.
    DB::statement('set lock_timeout = default');

    IntegrationDatabase::rollbackOpenTransactions();
    IntegrationDatabase::releaseLocks();
    IntegrationDatabase::truncate();
    IntegrationDatabase::disconnectAll();
});

/*
|--------------------------------------------------------------------------
| First delivery
|--------------------------------------------------------------------------
*/

it('commits receipt, read row and completion marker together on a first delivery', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();
    $eventId = projectionEnvelope(merchantId: $merchantId, paymentId: $paymentId);

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::Projected);

    // ONE TRANSACTION, PROVED BY PostgreSQL ITSELF rather than restated from the
    // code: xmin is the transaction id that wrote each tuple, so three equal values
    // are the database's own statement that one transaction wrote all three.
    //
    // ALL THREE, INCLUDING THE MARKER. An earlier version compared only the receipt
    // and the read row and asserted the marker merely `not->toBeNull()` — so moving
    // markCompleted() to AFTER the commit would have left this test, whose name is
    // about all three, entirely green.
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

    // BUSINESS TIME, NOT PROCESSING TIME. Asserted against the exact instant the
    // payload carries, to microseconds: a consumer that used now() here would still
    // produce a plausible timestamp and every looser assertion would pass.
    expect(DB::connection(IntegrationDatabase::OBSERVER)->selectOne(
        'select initiated_at = ?::timestamptz as matches from payment_details where source_event_id = ?',
        ['2026-08-21T10:11:12.123456+00:00', $eventId],
    )?->matches)->toBeTrue();

    $envelope = projectionEnvelopeRow($eventId);

    expect($envelope['projection_completed_at'])->not->toBeNull()
        ->and(projectionReceiptCount($eventId))->toBe(1);

    // The recorder's columns and the relay's are untouched, as whole groups.
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
    // The projector name is half the primary key, so it is a durable identifier: a
    // silent change would make every existing receipt invisible and re-project the
    // whole backlog. Asserted literally rather than through the constant, so
    // renaming the constant fails here instead of renaming the contract.
    $eventId = projectionEnvelope();

    projectionConsumer()->project($eventId);

    expect(DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_projector_receipts')
        ->where('event_id', $eventId)
        ->value('projector_name'))->toBe('payment_details')
        ->and(PaymentProjectionConsumer::PROJECTOR_NAME)->toBe('payment_details');
});

/*
|--------------------------------------------------------------------------
| Duplicate delivery
|--------------------------------------------------------------------------
*/

it('leaves an exact duplicate delivery entirely alone', function (): void {
    $eventId = projectionEnvelope();

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::Projected);

    $afterFirst = projectionEnvelopeRow($eventId);
    $readRowAfterFirst = projectionReadRow($eventId);

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::AlreadyProjected);

    // BYTE-FOR-BYTE, THE WHOLE ROW ON BOTH TABLES. Comparing entire rows rather
    // than named columns is what makes this catch a field the duplicate path learns
    // to rewrite later: any column at all that changed fails here.
    expect(projectionReadRow($eventId))->toBe($readRowAfterFirst)
        ->and(projectionEnvelopeRow($eventId))->toBe($afterFirst)
        ->and(projectionReceiptCount($eventId))->toBe(1)
        ->and(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_details')->count())->toBe(1);
});

it('repairs only a missing completion marker on a duplicate delivery', function (): void {
    // THE STATE A CRASH BETWEEN COMMIT AND THE NEXT READ LEAVES. Staged by clearing
    // the marker after a real first delivery, so receipt and read row are genuinely
    // the ones the consumer wrote rather than hand-built fixtures.
    $eventId = projectionEnvelope();

    projectionConsumer()->project($eventId);

    DB::table('payment_outbox')->where('event_id', $eventId)->update(['projection_completed_at' => null]);

    $readRowBefore = projectionReadRow($eventId);

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::MarkerRepaired);

    $envelope = projectionEnvelopeRow($eventId);

    expect($envelope['projection_completed_at'])->not->toBeNull()
        // Business state untouched: only delivery bookkeeping was repaired.
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
    // The `is null` predicate on the marker update, proved rather than assumed: a
    // duplicate that rewrote now() would move the recorded completion time forward
    // and destroy the evidence of when the projection actually happened.
    $eventId = projectionEnvelope();

    projectionConsumer()->project($eventId);

    $firstMarker = projectionEnvelopeRow($eventId)['projection_completed_at'];

    projectionConsumer()->project($eventId);

    expect(projectionEnvelopeRow($eventId)['projection_completed_at'])->toBe($firstMarker);
});

it('refuses a duplicate whose read row is missing rather than recreating it', function (): void {
    // CORRUPTION, NOT AN EMPTY SLOT. Receipt and read row are written by one
    // transaction, so deleting the row is the only way to reach this state — and a
    // consumer that "helpfully" re-inserted it would repair damage nobody had
    // detected and delete the evidence verification and rebuild act on.
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
        // The receipt survives: the refusal rolled back, it did not delete history.
        ->and(projectionReceiptCount($eventId))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Exhausted and missing envelopes
|--------------------------------------------------------------------------
*/

it('treats an exhausted envelope as an observability-only no-op', function (): void {
    $records = projectionCapturedLogs();
    $eventId = projectionEnvelope(exhaustedAt: '2026-08-21T10:00:00+00:00');

    $before = projectionEnvelopeRow($eventId);

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::SkippedExhausted);

    // NOTHING AT ALL: no receipt, no read row, no marker, and the envelope row byte
    // for byte as it was. Operator retry is the sole reactivation path, so a
    // consumer that "helpfully" projected an exhausted row would bypass it.
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

    // THE TRANSACTION IS CLOSED, AND THE ROW LOCK RELEASED WITH IT. The consumer
    // commits on this path rather than returning, specifically so an exhausted
    // envelope does not hold its lock; without this assertion an early `return`
    // that leaked the open transaction would pass every check above and only
    // surface as another test hanging.
    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    // Proved from a SECOND backend: the lock is genuinely gone, not merely
    // forgotten by this session's bookkeeping. A bounded lock_timeout makes a
    // retained lock a reported 55P03 rather than a hang.
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

    // NO ORPHAN METADATA. There is no row to charge and none may be created to hold
    // a failure, so the tables are exactly as empty as before.
    expect(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_outbox')->count())->toBe(0)
        ->and(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_projector_receipts')->count())->toBe(0)
        ->and(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_details')->count())->toBe(0);

    $aborted = array_values(array_filter(
        projectionLogContexts($records),
        fn (array $context): bool => ($context['category'] ?? null) === 'projection_failed_before_commit',
    ));

    // A rollback, never an ambiguous commit: the row was never found, so the
    // transaction could not have reached its commit boundary.
    expect($aborted)->toHaveCount(1)
        ->and($aborted[0]['exception_class'])->toBe(ProjectionEnvelopeMissing::class)
        ->and($aborted[0]['commit_attempted'])->toBeFalse()
        // Never captured, because the lock never returned a row to capture it from.
        ->and($aborted[0]['projection_failure_generation'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Envelope rejection and rollback
|--------------------------------------------------------------------------
*/

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

    // NO PROJECTION STATE AT ALL: the decode happens before the claim precisely so
    // a refusal cannot leave a receipt behind that would make the next delivery
    // report corruption instead of the real problem.
    expect($actual)->toBe($category)
        ->and(projectionReceiptCount($eventId))->toBe(0)
        ->and(projectionReadRow($eventId))->toBeNull()
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    $after = projectionEnvelopeRow($eventId);

    // THE ENVELOPE ROW IS *NOT* UNCHANGED, AND MUST NOT BE. Every case in this
    // dataset is a confirmed deterministic refusal, so the failure recorder charges
    // exactly one against the budget in its own transaction — after this one rolled
    // back. What must be unchanged is everything the recorder does not own.
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
    // THE MESSAGE REACHES A RETAINED FAILED-JOB RECORD, so it is durable output an
    // operator reads. Key NAMES are module-owned schema and may appear; VALUES are
    // caller data and may not. Staged with a value distinctive enough that a
    // substring search is conclusive.
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
    // CONTRADICTORY HISTORY MUST FAIL, NOT UPSERT — and the state has to be staged
    // from OUTSIDE the consumer, because the consumer cannot reach it on its own.
    // Two envelopes naming one payment id are impossible: identity agreement ties
    // the payload's paymentId to aggregate_uuid, and
    // payment_outbox_aggregate_version_unique already refuses a second envelope for
    // (aggregate, version 1). That is a property worth stating rather than a gap.
    //
    // What IS reachable is a payment_details row this consumer did not write — left
    // by a repair, a partial rebuild, or corruption. The consumer must then die on
    // payment_details_pkey rather than upsert over it, because an upsert would
    // silently replace whatever that row records with this event's fields.
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

    // The pre-existing row is untouched and this delivery wrote nothing at all.
    expect((array) DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_details')->where('payment_id', $paymentId)->first())->toBe($foreignRow)
        ->and(projectionReceiptCount($eventId))->toBe(0)
        ->and(projectionEnvelopeRow($eventId)['projection_completed_at'])->toBeNull()
        ->and(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_details')->count())->toBe(1);
});

it('cannot be handed two envelopes for one payment in the first place', function (): void {
    // THE POSITIVE CONTROL for the claim the test above rests on. If this ever
    // stopped throwing, a payment_id collision would become reachable through the
    // consumer and the test above would be testing the wrong boundary.
    $paymentId = (string) Str::uuid7();

    projectionEnvelope(paymentId: $paymentId);

    expect(fn () => projectionEnvelope(paymentId: $paymentId))
        ->toThrow(UniqueConstraintViolationException::class, 'payment_outbox_aggregate_version_unique');
});

it('rolls the whole transaction back when the read row insert fails', function (): void {
    // A REAL CONSTRAINT, NOT AN INJECTED FAULT: an envelope naming a merchant that
    // does not exist fails payment_details' restrictive foreign key inside the
    // transaction, AFTER the receipt has been inserted. That ordering is the point
    // — it is the only way to prove the receipt is rolled back with everything else
    // rather than committed independently.
    $eventId = projectionEnvelope(merchantId: 987_654_321);

    $before = projectionEnvelopeRow($eventId);

    expect(fn () => projectionConsumer()->project($eventId))->toThrow(QueryException::class);

    expect(projectionReceiptCount($eventId))->toBe(0)
        ->and(projectionReadRow($eventId))->toBeNull()
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    // The PROJECTION rolled back whole; the recorder then charged the failure in a
    // transaction of its own, which is the only column group allowed to differ.
    $after = projectionEnvelopeRow($eventId);

    expect((int) $after['projection_failure_count'])->toBe(1)
        ->and(projectionTransportColumns($after))->toBe(projectionTransportColumns($before));
});

it('reports a foreign key violation as 23503 rather than by matching its message', function (): void {
    // The failure recorder is required to classify database failures by SQLSTATE and
    // owned constraint identity. This pins the SQLSTATE the missing-merchant case
    // actually produces, read with the same classifier production uses.
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
    // WORSE THAN A MISSING ROW, AND REACHABLE THE SAME WAY. Without the value check
    // the delivery would report AlreadyProjected and stamp the completion marker
    // over a row for another payment, permanently retiring an envelope whose work
    // was never done. Staged by repointing the read row, which is what a partial
    // rebuild or a bad repair would leave.
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

    // REFUSED, AND THE MARKER LEFT UNSET: the envelope stays eligible so the
    // corruption keeps surfacing rather than being retired by a false completion.
    expect($category)->toBe(ProjectionEnvelopeRejected::READ_ROW_MISMATCH)
        ->and(projectionEnvelopeRow($eventId)['projection_completed_at'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The commit boundary
|--------------------------------------------------------------------------
|
| MEASURED AGAINST THE INSTALLED FRAMEWORK. Connection::commit() calls
| getPdo()->commit() and only THEN decrements $this->transactions, so
| transactionLevel() still reports 1 when the COMMIT itself throws. The two tests
| below stage the two sides of that boundary with real transaction events, which
| is the only way to reach them without killing a backend.
|
*/

it('classifies a failure raised before the commit as a definite rollback', function (): void {
    // TransactionCommitting fires BEFORE getPdo()->commit(), so the server
    // transaction is provably still open and nothing can have reached disk.
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

    // Definitely rolled back, from the second backend.
    expect(projectionReceiptCount($eventId))->toBe(0)
        ->and(projectionReadRow($eventId))->toBeNull()
        ->and(projectionEnvelopeRow($eventId)['projection_completed_at'])->toBeNull();
});

it('classifies a failure after a landed commit as an unknown commit outcome', function (): void {
    // TransactionCommitted fires AFTER getPdo()->commit() has already succeeded, so
    // the projection IS on disk while the delivery still throws. This is the case
    // that must NOT be counted: charging it would poison a row that succeeded, and
    // the fifth such failure would exhaust an envelope whose work is complete.
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

    // THE WORK IS DURABLE, read from the observer. That is what makes counting this
    // failure a defect rather than a mislabelled log line.
    expect(projectionReceiptCount($eventId))->toBe(1)
        ->and(projectionReadRow($eventId))->not->toBeNull()
        ->and(projectionEnvelopeRow($eventId)['projection_completed_at'])->not->toBeNull();

    // AND THE RECORDER WAS NEVER EVEN CALLED. Asserting the count is still zero
    // would not prove that: the recorder's own completed-projection guard would
    // refuse this row anyway, so the assertion would pass with the ambiguity check
    // deleted. Its ABSENCE FROM THE LOG is what separates the two — a recorder that
    // ran would have emitted `projection_failure_stale`.
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
    // THE CASE THE OTHER TWO CANNOT REACH, and the one the classification was wrong
    // about. Found by mutation: with the pre-review `transactionLevel() > 0` test
    // both listener-based tests above still passed, because neither reaches a
    // throwing getPdo()->commit().
    //
    // Connection::commit() calls getPdo()->commit() and only THEN decrements
    // $this->transactions, so at the moment the COMMIT throws transactionLevel()
    // still reports 1. A classification reading it calls this a definite rollback —
    // a COUNTABLE deterministic failure — for the one condition the specification
    // names as non-countable and requires to write no failure metadata.
    //
    // STAGED WITH A DEFERRED CONSTRAINT TRIGGER, which is PostgreSQL's own mechanism
    // for work that runs AT commit time. The consumer's insert into payment_details
    // queues it; the server then raises while executing the COMMIT itself.
    //
    // Terminating the backend was tried first and is NOT deterministic:
    // pg_terminate_backend signals asynchronously, so the COMMIT frequently lands
    // before the backend dies and the delivery simply succeeds. A constraint trigger
    // makes the server fail the COMMIT every time, with no sleeps and no fault
    // injector in production code.
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
        // Dropped whatever happens: left behind, it would fail every later test that
        // projects anything, and the failure would name this test not at all.
        DB::unprepared(
            'drop trigger if exists projection_commit_probe_trigger on payment_details;'
            .'drop function if exists projection_commit_probe();'
        );
    }

    // The probe must actually have fired; a silent success would make every
    // assertion below vacuous.
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

    // Nothing reached disk on this occasion — but the CLIENT cannot prove that, and
    // the classification must reflect what is knowable rather than what happened to
    // be true. The next delivery rereads canonical state and decides.
    expect(projectionReceiptCount($eventId))->toBe(0)
        ->and(projectionReadRow($eventId))->toBeNull();
});

it('carries the SQLSTATE of a database failure into the abort log', function (): void {
    // The classification rules require database failures to be told apart by
    // SQLSTATE and owned constraint identity rather than by exception text, so the
    // log line an operator triages has to carry it.
    $records = projectionCapturedLogs();
    $eventId = projectionEnvelope(merchantId: 987_654_321);

    try {
        projectionConsumer()->project($eventId);
    } catch (QueryException) {
        // The log context is what this test is about.
    }

    $aborted = array_values(array_filter(
        projectionLogContexts($records),
        fn (array $context): bool => ($context['category'] ?? null) === 'projection_failed_before_commit',
    ));

    expect($aborted)->toHaveCount(1)
        ->and($aborted[0]['sql_state'])->toBe('23503');

    // Null rather than absent for a failure that carries no SQLSTATE, so the field
    // is always present and a consumer of the log never has to guess.
    expect(array_keys($aborted[0]))->toContain('sql_state');
});

/*
|--------------------------------------------------------------------------
| The projection-local statement timeout
|--------------------------------------------------------------------------
*/

it('applies its statement timeout inside the transaction and lets it die with it', function (): void {
    // READ FROM POSTGRESQL, FROM INSIDE THE TRANSACTION. TransactionCommitting
    // fires at the last instant the transaction is provably still open, which is
    // where the bound has to be in effect.
    $eventId = projectionEnvelope();

    $inside = null;

    Event::listen(TransactionCommitting::class, function () use (&$inside): void {
        $inside = DB::selectOne('show statement_timeout')?->statement_timeout;
    });

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::Projected);

    // The configured 2000 ms, as PostgreSQL renders it.
    expect($inside)->toBe('2s');

    // AND GONE AFTERWARDS, which is what makes it SET LOCAL rather than SET. A
    // session-level bound would outlive this transaction and silently apply to
    // every later statement on a worker connection that lives for hours.
    expect(DB::selectOne('show statement_timeout')?->statement_timeout)->toBe('0');
});

it('cancels a statement that outlives the bound rather than waiting for the worker', function (): void {
    // THE BOUND DOING ITS JOB, against a real lock held by a second backend. With a
    // deliberately tiny timeout the wait is milliseconds rather than the configured
    // two seconds, so this is real evidence without a slow test.
    //
    // 57014 is query_canceled — the SQLSTATE the classification treats as
    // non-countable, which is the entire reason the bound must fire BEFORE the
    // worker's own alarm: a worker kill mid-transaction is not classifiable at all.
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

    // A SESSION-LEVEL FLOOR UNDERNEATH THE CONSUMER'S OWN BOUND, for the reason
    // ContentionProbe records: `SET LOCAL` does nothing when there is no
    // transaction to be local to, so deleting the consumer's bound made this test
    // HANG on the row lock rather than fail — the worst way for a mutation to be
    // caught. Found exactly that way.
    //
    // Deliberately far longer than the 150 ms under test, so it never pre-empts the
    // 57014 this test exists to produce; it only ever speaks when the LOCAL bound
    // has evaporated. The elapsed-time assertion below is what tells the two apart,
    // since both raise the same SQLSTATE.
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

    // THE CONSUMER'S BOUND FIRED, NOT THE FLOOR. hrtime, not microtime: an NTP step
    // mid-test must not decide this.
    expect($elapsedMs)->toBeLessThan(2_000)
        ->and($sqlState)->toBe('57014')
        // Nothing was written, and nothing was charged: a cancelled statement says
        // nothing about the envelope.
        ->and(projectionReceiptCount($eventId))->toBe(0)
        ->and((int) projectionEnvelopeRow($eventId)['projection_failure_count'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Concurrency — one envelope, two deliveries
|--------------------------------------------------------------------------
*/

it('serialises a concurrent duplicate on the envelope row lock', function (): void {
    // SYNCHRONISED BY PostgreSQL, NOT BY SLEEPING. The LOCK connection holds a real
    // FOR UPDATE on the envelope; the consumer's own SELECT ... FOR UPDATE must
    // therefore WAIT, and a bounded lock_timeout turns that wait into a reported
    // 55P03 instead of a hang. A consumer that used SKIP LOCKED, or no row lock at
    // all, would return an outcome here instead of blocking — which is precisely the
    // mutation this catches.
    $eventId = projectionEnvelope();

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->select('select event_id from payment_outbox where event_id = ? for update', [$eventId]);

    // Session level, so the consumer's own transaction inherits it without the
    // consumer knowing anything about this test.
    DB::statement("set lock_timeout = '250ms'");

    // WHICH STATEMENT BLOCKED, NOT MERELY THAT ONE DID. Found by mutation: with the
    // row lock deleted entirely, this test still passed — the consumer got as far as
    // inserting the receipt and then blocked on the marker UPDATE, which takes a row
    // lock of its own and raises the same 55P03. Asserting only the SQLSTATE proved
    // nothing about WHERE the serialisation happened.
    //
    // Connection::run() logs a query only AFTER it succeeds, so a statement that
    // raised 55P03 never appears here. A receipt insert in this log therefore means
    // the consumer wrote before it was serialised, which is exactly the defect.
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

    // 55P03 lock_not_available: the consumer genuinely queued behind the holder,
    // and it did so on the envelope lock, before touching anything.
    expect($sqlState)->toBe('55P03')
        ->and($writes)->toBe([])
        ->and(projectionReceiptCount($eventId))->toBe(0)
        ->and(projectionReadRow($eventId))->toBeNull();

    // NOW LET THE WINNER GO, and prove the loser converges rather than duplicating.
    $lock->rollBack();

    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::Projected);

    expect(projectionReceiptCount($eventId))->toBe(1)
        ->and(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_details')->count())->toBe(1);
});

it('converges to one receipt, one read row and one marker when a duplicate arrives after the winner', function (): void {
    // THE OTHER HALF OF THE RACE, played out with the winner's work COMMITTED by a
    // second backend rather than by this one. The loser sees exactly what a real
    // concurrent duplicate sees once the winner's transaction ends: a receipt it
    // did not write, and a read row it must not touch.
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

    // The loser now runs the real consumer against that committed state.
    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::AlreadyProjected);

    expect(projectionReceiptCount($eventId))->toBe(1)
        ->and(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_details')->count())->toBe(1)
        ->and(projectionReadRow($eventId))->toBe($afterWinner);

    $envelope = projectionEnvelopeRow($eventId);

    // NO POISON INCREMENT. Losing a race is not a failure, and the columns the
    // recorder owns must be exactly as the winner left them.
    expect(projectionRecorderColumns($envelope))->toBe([
        'projection_failure_count' => 0,
        'projection_failure_generation' => 0,
        'last_projection_error' => null,
        'exhausted_at' => null,
    ]);
});

/*
|--------------------------------------------------------------------------
| Ownership boundaries
|--------------------------------------------------------------------------
*/

it('never writes a column the relay or the failure recorder owns', function (): void {
    // ONE ASSERTION OVER BOTH GROUPS, on the two paths that write anything at all.
    // Named columns would only catch the ones a future test remembered to mention.
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
    // THE FENCE, CAPTURED UNDER THE LOCK AND CARRIED TO THE FAILURE BOUNDARY. The
    // recorder may only write metadata while the row is still on this generation, so
    // reading it after the rollback instead would read whatever the row holds by
    // then — exactly the value that cannot be trusted. Staged at a non-zero value so
    // a hardcoded 0 would fail.
    $records = projectionCapturedLogs();
    $eventId = projectionEnvelope(merchantId: 987_654_321);

    DB::table('payment_outbox')->where('event_id', $eventId)->update(['projection_failure_generation' => 3]);

    // WHEN IT WAS READ, NOT ONLY WHAT WAS READ. Asserting the logged value alone
    // proves the weaker claim "it came from the row rather than a constant": an
    // implementation that re-read the column inside abort(), AFTER the rollback,
    // would log 3 here too and pass. That implementation is the defect — by then
    // the lock is gone and an operator retry may already have advanced the fence,
    // so the recorder would carry a value that no longer identifies the cycle it
    // failed in.
    //
    // Counting the reads is what separates the two: the envelope must be read from
    // payment_outbox EXACTLY ONCE, by the locking SELECT.
    $selects = [];
    DB::listen(function ($query) use (&$selects): void {
        if (str_starts_with(trim(strtolower($query->sql)), 'select') && str_contains($query->sql, 'payment_outbox')) {
            $selects[] = $query->sql;
        }
    });

    try {
        projectionConsumer()->project($eventId);
    } catch (QueryException) {
        // The rollback is what this test is about; the SQLSTATE is pinned above.
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
    // THE LOG IS THE OTHER DURABLE OUTPUT, and the classification rules forbid raw
    // exception text there. Only the class name, the module's fixed category, the
    // event id and the phases may appear.
    $records = projectionCapturedLogs();
    $eventId = projectionEnvelope(payloadOverrides: ['currency' => 'zzz']);

    try {
        projectionConsumer()->project($eventId);
    } catch (ProjectionEnvelopeRejected) {
        // Expected; the context is what matters.
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

    // No context value anywhere carries the payload value or a message fragment.
    $rendered = json_encode($aborted[0]);

    expect($rendered)->not->toContain('zzz')
        ->and($rendered)->not->toContain('malformed:');
});

/*
|--------------------------------------------------------------------------
| T11's shared projection gate
|--------------------------------------------------------------------------
|
| Only the CLOSED-gate behaviour belongs here. Every test above ran with a
| `ready` control row, and none of it changed - the regression boundary Slice 2
| promises for T8.
*/

/** Close the gate as a rebuild-shaped holder of the durable row would. */
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

/** Reopen it, exactly as a completed rebuild would. */
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

    // NOTHING THROWN, DELIBERATELY (F5): ProjectPaymentEvent has tries = 1 and no
    // try/catch, so an exception here would land in failed_jobs instead of being
    // redelivered.
    expect(projectionConsumer()->project($eventId))->toBe(ProjectionOutcome::GateDeferred);

    expect(projectionReceiptCount($eventId))->toBe(0)
        ->and(projectionReadRow($eventId))->toBeNull()
        ->and(projectionEnvelopeRow($eventId))->toBe($before)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    // NO ENVELOPE LOCK TAKEN: a second backend can take FOR UPDATE on this exact
    // row immediately, with no wait and no timeout needed. A consumer that
    // reached its own lock before checking the gate would make this block.
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

    // projection_failure_count, projection_failure_generation, exhausted_at,
    // last_projection_error - and every transport column - exactly as they were.
    // A deferral must never consume the failure budget T8 owns nor the transport
    // budget T7 owns.
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

    // The gate reopened is the mutation: the SAME delivery now projects normally,
    // proving the deferral above left the envelope exactly as eligible as it
    // started.
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

    // GRANTED, ON ONE BACKEND. The control row is what deferred the delivery, not
    // the lock - both checks are load-bearing, for different failures (D3).
    expect($lockDuringCommit)->toHaveCount(1)
        ->and($lockDuringCommit[0]['mode'])->toBe('ShareLock')
        ->and($lockDuringCommit[0]['granted'])->toBeTrue();

    // THE SAME BACKEND THE CONSUMER'S TRANSACTION RUNS ON, proved by object
    // identity - the stronger evidence T4's rule about self-reported lock
    // possession leaves available. PaymentProjectionConsumer resolves the
    // IDENTICAL Connection object ProjectionGate does, per the shared binding in
    // PaymentsServiceProvider.
    $consumerConnection = new ReflectionProperty(PaymentProjectionConsumer::class, 'connection')
        ->getValue(projectionConsumer());
    expect($consumerConnection)->toBe(DB::connection());

    projectionReopenGate();
});
