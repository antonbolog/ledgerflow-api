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

/*
|--------------------------------------------------------------------------
| The projection failure recorder — poison accounting and the generation fence
|--------------------------------------------------------------------------
|
| IN THE INTEGRATION SUITE, not Feature, for the reason the consumer suite gives:
| RefreshDatabase would turn the recorder's own transaction into a SAVEPOINT, and
| every claim about what a SECOND connection can see afterwards would be vacuous.
|
| THE RACES ARE PLAYED OUT BY ORDERING, NOT BY THREADS. A "late recorder" is
| exactly a recorder invoked with a generation captured earlier, against a row that
| has since moved on — so staging the row's new state and then calling record()
| with the OLD generation reproduces the race deterministically, with no sleeps and
| no second process. What the recorder cannot see is when the value was captured;
| that is the whole reason the fence works.
|
| EVERY DURABLE CLAIM IS READ FROM THE OBSERVER, so only committed state counts.
|
*/

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

/** The transport columns the relay owns, which the recorder must never write. */
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

/*
|--------------------------------------------------------------------------
| Counting, and the fifth failure
|--------------------------------------------------------------------------
*/

it('increments the count and records the category in its own committed transaction', function (): void {
    $eventId = recorderEnvelope();

    recorder()->record($eventId, 0, ProjectionEnvelopeRejected::MALFORMED_PAYLOAD);

    $row = recorderRow($eventId);

    expect((int) $row['projection_failure_count'])->toBe(1)
        ->and($row['last_projection_error'])->toBe(ProjectionEnvelopeRejected::MALFORMED_PAYLOAD)
        ->and($row['exhausted_at'])->toBeNull()
        // Committed and closed: the recorder's transaction is short and leaves
        // nothing open behind it.
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});

it('exhausts the envelope on exactly the fifth confirmed failure', function (): void {
    // WALKED ONE FAILURE AT A TIME, so the boundary is observed rather than jumped
    // to. Four must leave the row retryable; the fifth must exhaust it, and it must
    // do so in the SAME statement that writes the count — a split would let two
    // recorders each read four and each write five with neither crossing.
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

    // ONE TRANSACTION, PROVED BY PostgreSQL: the tuple carrying count 5 is the same
    // tuple carrying exhausted_at, so no window exists between them.
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

    // BYTE FOR BYTE. An exhausted row is out of budget and only operator retry may
    // reopen it; a sixth failure that bumped the count to 6 or rewrote the category
    // would destroy the record of what actually exhausted it.
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

    // AS A WHOLE GROUP, so a recorder that learned to reset a transport counter is
    // caught by this test rather than by whichever one happened to name that column.
    expect(recorderTransportColumns(recorderRow($eventId)))->toBe(recorderTransportColumns($before));
});

/*
|--------------------------------------------------------------------------
| The stale-failure guards
|--------------------------------------------------------------------------
*/

it('declines to poison a row a concurrent duplicate has already completed', function (
    array $envelope,
    string $reason,
): void {
    // THE LATE RECORDER, reproduced by ordering: it holds a generation captured
    // before the row moved on, and the row has since reached a state that forbids
    // charging. Every case is a safe logged no-op, never a mutation.
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
    // A duplicate delivery won the race and committed the projection. Charging here
    // would poison a row whose work is done.
    'a completed projection' => [
        ['projectionCompletedAt' => '2026-08-21T10:00:00+00:00'],
        'already_projected',
    ],
    // Already spent; only operator retry may reopen it.
    'an exhausted envelope' => [
        ['exhaustedAt' => '2026-08-21T10:00:00+00:00'],
        'already_exhausted',
    ],
    // THE FENCE. An operator retry has started a new cycle, and a failure from the
    // old one must not consume the fresh budget.
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

    // NO ORPHAN METADATA: nothing is created to hold a failure for a row that is
    // not there.
    expect($stale)->toHaveCount(1)
        ->and($stale[0]['reason'])->toBe('envelope_absent')
        ->and(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_outbox')->count())->toBe(0);
});

it('cannot charge a reset cycle with a failure from the cycle before it', function (): void {
    // THE ABA CASE, and the reason the fence is a generation rather than a count.
    // A recorder captures generation 0 at count 4. The envelope then exhausts, an
    // operator retry clears the poison state and increments the generation, and the
    // late recorder finally arrives. Count 0 after the retry looks EXACTLY like
    // count 0 before the first failure; only the generation tells them apart, and
    // without it this recorder would spend the first fifth of a fresh budget on a
    // failure from a cycle that is over.
    $records = recorderCapturedLogs();
    $eventId = recorderEnvelope(failureCount: 4, generation: 0);

    // The fifth failure of the old cycle exhausts it.
    recorder()->record($eventId, 0, ProjectionFailureClassification::HANDLER_FAILED);

    expect((int) recorderRow($eventId)['projection_failure_count'])->toBe(5)
        ->and(recorderRow($eventId)['exhausted_at'])->not->toBeNull();

    // Operator retry, staged as the reset it performs. The command itself is a
    // later slice; the FENCE SEMANTICS are what this test is about.
    DB::table('payment_outbox')->where('event_id', $eventId)->update([
        'exhausted_at' => null,
        'projection_failure_count' => 0,
        'last_projection_error' => null,
        'projection_failure_generation' => DB::raw('projection_failure_generation + 1'),
        'available_at' => now(),
    ]);

    $afterRetry = recorderRow($eventId);

    // The straggler from the old cycle arrives, still carrying generation 0.
    recorder()->record($eventId, 0, ProjectionFailureClassification::HANDLER_FAILED);

    expect(recorderRow($eventId))->toBe($afterRetry)
        ->and((int) recorderRow($eventId)['projection_failure_count'])->toBe(0);

    $stale = recorderLogsOfCategory($records, 'projection_failure_stale');

    expect($stale)->toHaveCount(1)
        ->and($stale[0]['reason'])->toBe('generation_advanced');

    // POSITIVE CONTROL: a recorder on the CURRENT generation charges normally, so
    // the fence refuses the stale write without freezing the row.
    recorder()->record($eventId, 1, ProjectionFailureClassification::HANDLER_FAILED);

    expect((int) recorderRow($eventId)['projection_failure_count'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| When the metadata write itself fails
|--------------------------------------------------------------------------
*/

it('leaves the envelope retryable when it cannot persist the failure', function (): void {
    // A REAL LOCK CONFLICT, bounded by PostgreSQL's own lock_timeout: the LOCK
    // connection holds the row, so the recorder's guarded UPDATE cannot take it.
    // No fault injector in production code, and no sleeps.
    $records = recorderCapturedLogs();
    $eventId = recorderEnvelope();

    $before = recorderRow($eventId);

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->select('select event_id from payment_outbox where event_id = ? for update', [$eventId]);

    DB::statement("set lock_timeout = '250ms'");

    // SWALLOWED, NOT RETHROWN. The caller is holding the projection failure that
    // brought it here and must rethrow THAT; an operator who saw "could not record
    // the failure" instead of the failure has lost the incident.
    recorder()->record($eventId, 0, ProjectionFailureClassification::HANDLER_FAILED);

    DB::statement('set lock_timeout = default');
    $lock->rollBack();

    // INCOMPLETE AND NON-EXHAUSTED, so the relay redelivers rather than the row
    // being falsely counted or falsely retired.
    expect(recorderRow($eventId))->toBe($before)
        ->and((int) recorderRow($eventId)['projection_failure_count'])->toBe(0)
        ->and(recorderRow($eventId)['exhausted_at'])->toBeNull();

    $failed = recorderLogsOfCategory($records, 'projection_failure_not_recorded');

    expect($failed)->toHaveCount(1)
        ->and($failed[0]['event_id'])->toBe($eventId);

    // BOTH transaction bookkeepings clean afterwards. Laravel's and the PDO's can
    // disagree after a failure at a transaction boundary, so both are asked.
    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Leakage sentinels
|--------------------------------------------------------------------------
*/

it('persists only a fixed category, never server text or a payload value', function (): void {
    // last_projection_error IS DURABLE and is read by operators. Whatever the
    // driver said, and whatever the payload held, only the closed-vocabulary
    // category may reach the column.
    $eventId = recorderEnvelope();

    recorder()->record($eventId, 0, ProjectionEnvelopeRejected::CONTRADICTORY_IDENTITY);

    $stored = recorderRow($eventId)['last_projection_error'];

    expect($stored)->toBe('projection_identity_contradictory')
        ->and($stored)->not->toContain(' ')
        ->and($stored)->toStartWith('projection_');
});

it('keeps every recorder log context to safe fields only', function (): void {
    // FULLY RENDERED, then searched. Asserting field by field would miss a value
    // nested inside one of them, so the whole context is encoded and inspected.
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

    // The event id is a uuid the relay generated and is safe by construction;
    // nothing else identifying may appear.
    expect($rendered)->toContain($eventId)
        ->and($rendered)->not->toContain('USD')
        ->and($rendered)->not->toContain('1250')
        ->and($rendered)->not->toContain('idempotency');
});

it('names the exhaustion in its own log category', function (): void {
    // An operator watching for exhaustion must not have to parse a count out of a
    // generic line; crossing the threshold is its own event.
    $records = recorderCapturedLogs();
    $eventId = recorderEnvelope(failureCount: 4);

    recorder()->record($eventId, 0, ProjectionFailureClassification::HANDLER_FAILED);

    expect(recorderLogsOfCategory($records, 'projection_exhausted'))->toHaveCount(1)
        ->and(recorderLogsOfCategory($records, 'projection_failure_recorded'))->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| End to end, through the consumer
|--------------------------------------------------------------------------
*/

it('charges five confirmed deliveries and then stops delivering', function (): void {
    // THE WHOLE BUDGET, THROUGH THE REAL CONSUMER rather than the recorder alone.
    // The envelope names a merchant that does not exist, so every delivery fails
    // deterministically on the read row's foreign key — a real constraint, not an
    // injected fault.
    $eventId = recorderEnvelope(merchantId: 987_654_321);

    foreach ([1, 2, 3, 4] as $expected) {
        try {
            resolve(PaymentProjectionConsumer::class)->project($eventId);
        } catch (QueryException) {
            // The accounting is what this test is about.
        }

        expect((int) recorderRow($eventId)['projection_failure_count'])->toBe($expected)
            ->and(recorderRow($eventId)['exhausted_at'])->toBeNull();
    }

    try {
        resolve(PaymentProjectionConsumer::class)->project($eventId);
    } catch (QueryException) {
        // Expected on the fifth as well.
    }

    expect((int) recorderRow($eventId)['projection_failure_count'])->toBe(5)
        ->and(recorderRow($eventId)['exhausted_at'])->not->toBeNull();

    // THE SIXTH DELIVERY IS A NO-OP, not a failure: the consumer sees an exhausted
    // row under its lock and returns without projecting or charging.
    $exhausted = recorderRow($eventId);

    expect(resolve(PaymentProjectionConsumer::class)->project($eventId)->value)
        ->toBe('skipped_exhausted')
        ->and(recorderRow($eventId))->toBe($exhausted);
});

it('does not charge a delivery that lost a race to a successful duplicate', function (): void {
    // DUPLICATE REDELIVERY IS NOT A FAILURE. The consumer returns AlreadyProjected
    // and nothing is charged — distinguishing this from the five confirmed failures
    // above is the entire point of the classification.
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
    // THE CATEGORY GUARD, ISOLATED. Found by mutation: the existing lock-timeout
    // test passes even when the guard is deleted, because the timeout happens on
    // the envelope SELECT itself — so no generation is ever captured and a
    // different guard refuses the charge. To test the category guard the failure
    // must land AFTER the lock succeeded.
    //
    // Staged the way ContentionProbe does it: the LOCK connection holds FOR UPDATE
    // on the MERCHANT row, and payment_details' foreign-key check needs FOR KEY
    // SHARE on that same row. The envelope lock and the receipt insert both
    // succeed; the read-row insert is what times out.
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

    // The failure genuinely happened past the lock, which is what makes the rest of
    // this test mean anything.
    expect($sqlState)->toBe('55P03');

    // AND NOTHING WAS CHARGED. A lock timeout says nothing about the envelope.
    expect(recorderRow($eventId))->toBe($before)
        ->and((int) recorderRow($eventId)['projection_failure_count'])->toBe(0);
});

it('does not charge a lock timeout, however many times it happens', function (): void {
    // A DATABASE OUTAGE MUST NOT EXHAUST AN ENVELOPE. Five real lock timeouts in a
    // row leave the budget untouched, which is what separates a transient database
    // condition from a confirmed deterministic failure.
    $eventId = recorderEnvelope();

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->select('select event_id from payment_outbox where event_id = ? for update', [$eventId]);

    DB::statement("set lock_timeout = '100ms'");

    foreach (range(1, 5) as $ignored) {
        try {
            resolve(PaymentProjectionConsumer::class)->project($eventId);
        } catch (QueryException) {
            // 55P03, five times over.
        }
    }

    DB::statement('set lock_timeout = default');
    $lock->rollBack();

    $row = recorderRow($eventId);

    expect((int) $row['projection_failure_count'])->toBe(0)
        ->and($row['exhausted_at'])->toBeNull()
        ->and($row['last_projection_error'])->toBeNull();
});
