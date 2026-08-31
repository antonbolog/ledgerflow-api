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

/*
|--------------------------------------------------------------------------
| The operator flow — the queued job body and `payments:outbox:retry`
|--------------------------------------------------------------------------
|
| IN THE INTEGRATION SUITE because both subjects commit for real: the job runs the
| consumer's own transaction, and the retry holds a row lock across a SELECT and an
| UPDATE. Under RefreshDatabase both would become savepoints and the second-connection
| evidence below would be vacuous.
|
| THE RETRY IS INVOKED THROUGH Artisan, not by calling the class, so the signature,
| the container binding and the registration are all exercised. A test that
| new-ed the command would pass with the command unregistered — which is exactly the
| failure an operator would meet.
|
*/

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
        // Deliberately in the past and behind a backoff, so a successful reset
        // moving it to now() is observable.
        'available_at' => '2099-01-01T00:00:00+00:00',
        'projection_completed_at' => $projectionCompletedAt,
        'exhausted_at' => $exhaustedAt,
        'projection_failure_count' => $failureCount,
        'projection_failure_generation' => $generation,
        'last_projection_error' => $lastProjectionError,
        // Publication and transport history the reset must preserve.
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

/**
 * Publication and transport history, as one group.
 *
 * The reset destroys projection poison state and NOTHING ELSE. Comparing these as a
 * whole is what catches a reset that learned to clear a transport counter, rather
 * than leaving that to whichever test happened to name the column.
 */
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

/** The retry as an operator runs it, through Artisan. */
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

/*
|--------------------------------------------------------------------------
| The queued job body
|--------------------------------------------------------------------------
*/

it('projects the envelope when the queued job runs through the container', function (): void {
    // THE DELEGATION, END TO END. The consumer is final, so there is no fake to
    // inject; running the job the way a worker does — through Container::call(),
    // which is what resolves the type-hinted handle() parameter — proves both the
    // delegation and the binding that makes it possible.
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
    // A worker turns an escaping exception into a retained failed-job record, which
    // is the only durable evidence of a refused delivery. A job that caught this
    // would mark the envelope delivered while nothing was projected.
    $eventId = operatorEnvelope(merchantId: 987_654_321);

    expect(fn () => app()->call([new ProjectPaymentEvent($eventId), 'handle']))
        ->toThrow(QueryException::class);

    // And the failure was still charged, so the job did not bypass the accounting.
    expect((int) operatorRow($eventId)['projection_failure_count'])->toBe(1);
});

it('still deserialises a message published before the body changed', function (): void {
    // THE WIRE CONTRACT. A queue that survived a deploy holds envelopes serialised
    // against the old shape; this is that exact byte string, written by hand rather
    // than produced by the current class, so a changed property name or an added
    // property fails here instead of at 3am on a worker.
    $eventId = '01984f6f-6a20-7000-8000-000000000001';

    // THE FQCN AS A LITERAL, never ::class: the fully-qualified name is half the
    // wire contract, so moving or renaming the class must fail here rather than
    // silently rewriting the expectation. Only its LENGTH is computed, because that
    // is arithmetic rather than contract.
    $class = 'Modules\Payments\Infrastructure\Relay\ProjectPaymentEvent';

    // One property, named eventId, holding the 36-character uuid — the exact shape
    // the relay published before handle() changed.
    $legacy = 'O:'.strlen($class).':"'.$class.'":1:{s:7:"eventId";s:36:"'.$eventId.'";}';

    $restored = unserialize($legacy);

    expect($restored)->toBeInstanceOf(ProjectPaymentEvent::class)
        ->and($restored->eventId)->toBe($eventId);
});

/*
|--------------------------------------------------------------------------
| Refusals, without mutation
|--------------------------------------------------------------------------
*/

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

    // BYTE FOR BYTE, THE WHOLE ROW. A refusal that nonetheless bumped the
    // generation, or nudged available_at, would be caught here rather than by
    // whichever column a narrower assertion happened to name.
    expect(operatorRow($eventId))->toBe($before);

    $refused = operatorLogsOfCategory($records, 'projection_operator_retry_refused');

    expect($refused)->toHaveCount(1)
        ->and($refused[0]['reason'])->toBe($reason);
})->with([
    // Completed work is never reactivated: the projection is done and the envelope
    // has nothing left to deliver.
    'a completed projection' => [
        ['projectionCompletedAt' => '2026-08-21T10:00:00+00:00'],
        PaymentOutboxRetryCommand::ALREADY_PROJECTED,
    ],
    // Still inside its automatic budget. Reactivating would hand the operator a
    // false sense of having fixed something, and would reset a count that is doing
    // its job.
    'an envelope that has never failed' => [
        [],
        PaymentOutboxRetryCommand::NOT_EXHAUSTED,
    ],
    'an envelope part way through its budget' => [
        ['failureCount' => 4, 'lastProjectionError' => 'projection_handler_failed'],
        PaymentOutboxRetryCommand::NOT_EXHAUSTED,
    ],
    // Both completed AND exhausted: a duplicate succeeded after exhaustion. The
    // operator needs to be told the work is done, not that it was poisoned.
    'a completed envelope that had also exhausted' => [
        [
            'projectionCompletedAt' => '2026-08-21T10:00:00+00:00',
            'exhaustedAt' => '2026-08-21T09:00:00+00:00',
            'failureCount' => 5,
        ],
        PaymentOutboxRetryCommand::ALREADY_PROJECTED,
    ],
]);

/*
|--------------------------------------------------------------------------
| The reset
|--------------------------------------------------------------------------
*/

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

    // EVERY H6 EFFECT, ASSERTED INDIVIDUALLY.
    expect($after['exhausted_at'])->toBeNull()
        ->and((int) $after['projection_failure_count'])->toBe(0)
        ->and($after['last_projection_error'])->toBeNull()
        ->and((int) $after['projection_failure_generation'])->toBe(3);

    // ELIGIBLE AGAIN IN THE SAME TRANSACTION. The fixture parks available_at in
    // 2099, so a reset that left T7 to reschedule would leave the row idle for
    // decades — asserted against now() from the database rather than from PHP.
    expect(DB::connection(IntegrationDatabase::OBSERVER)->selectOne(
        'select available_at <= now() as eligible from payment_outbox where event_id = ?',
        [$eventId],
    )?->eligible)->toBeTrue();

    // PUBLICATION AND TRANSPORT HISTORY SURVIVE. The reset destroys how the
    // envelope was PROJECTED, never the record of how it was DELIVERED.
    expect(operatorPublicationHistory($after))->toBe(operatorPublicationHistory($before));

    $retried = operatorLogsOfCategory($records, 'projection_operator_retry');

    expect($retried)->toHaveCount(1)
        ->and($retried[0]['event_id'])->toBe($eventId)
        ->and($retried[0]['projection_failure_generation'])->toBe(3);
});

it('makes the reset fence refuse a recorder from the cycle it ended', function (): void {
    // THE RECIPROCAL HALF of the recorder's ABA test, driven by the real command
    // rather than by a hand-written UPDATE. A recorder still in flight from the
    // exhausted cycle carries the OLD generation and must not spend the first fifth
    // of the fresh budget.
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

    // POSITIVE CONTROL: the new cycle charges normally, so the fence refused the
    // straggler without freezing the envelope.
    resolve(ProjectionFailureRecorder::class)
        ->record($eventId, 1, ProjectionFailureClassification::HANDLER_FAILED);

    expect((int) operatorRow($eventId)['projection_failure_count'])->toBe(1);
});

it('reactivates an exhausted envelope so the next delivery projects it', function (): void {
    // END TO END: exhausted envelopes are a no-op for the consumer, so a reset that
    // did not truly clear the state would show up as another skip rather than as a
    // projection.
    $merchantId = Merchant::factory()->create()->getKey();
    $eventId = operatorEnvelope(
        merchantId: $merchantId,
        failureCount: 5,
        exhaustedAt: '2026-08-21T09:00:00+00:00',
        lastProjectionError: 'projection_handler_failed',
    );

    // Before the reset the consumer refuses to touch it.
    expect(app()->call([new ProjectPaymentEvent($eventId), 'handle']))->toBeNull();
    expect(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_details')->count())->toBe(0);

    expect(operatorRetry($eventId))->toBe(0);

    app()->call([new ProjectPaymentEvent($eventId), 'handle']);

    expect(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_details')->count())->toBe(1)
        ->and(operatorRow($eventId)['projection_completed_at'])->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Serialisation against the consumer and the relay
|--------------------------------------------------------------------------
*/

it('waits for a delivery in flight rather than reading state about to change', function (): void {
    // THE ROW LOCK, PROVED BY CONTENTION. The LOCK connection holds the envelope the
    // way a mid-flight delivery or a relay pass does; the retry must queue behind
    // it, which a bounded lock_timeout turns into a reported 55P03 instead of a
    // hang. A retry that read without FOR UPDATE would return an answer here.
    $eventId = operatorEnvelope(
        failureCount: 5,
        exhaustedAt: '2026-08-21T09:00:00+00:00',
    );

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->select('select event_id from payment_outbox where event_id = ? for update', [$eventId]);

    DB::statement("set lock_timeout = '250ms'");

    // WHICH STATEMENT BLOCKED, NOT MERELY THAT ONE DID. Found by mutation: with the
    // FOR UPDATE deleted this test still passed, because the reset UPDATE takes a
    // row lock of its own and raises the same 55P03 one statement later.
    //
    // Connection::run() logs a query only AFTER it succeeds. So with the lock the
    // SELECT fails and never appears here; without it the SELECT SUCCEEDS and does.
    // A successful read of payment_outbox therefore means the retry read state it
    // had no right to trust — which is the defect.
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
        // Nothing was written while it was blocked.
        ->and(operatorRow($eventId)['exhausted_at'])->not->toBeNull();

    // Once the holder releases, the same retry succeeds.
    $lock->rollBack();

    expect(operatorRetry($eventId))->toBe(0)
        ->and(operatorRow($eventId)['exhausted_at'])->toBeNull();
});

it('leaves both transaction bookkeepings clean after a refusal and after a reset', function (): void {
    // The command commits on BOTH paths — a refusal releases the row lock rather
    // than holding it to the end of a transaction with no work left. Laravel's
    // level and the PDO's can disagree after a transaction-boundary failure, so
    // both are asked.
    $refused = operatorEnvelope();
    $resettable = operatorEnvelope(failureCount: 5, exhaustedAt: '2026-08-21T09:00:00+00:00');

    operatorRetry($refused);

    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    operatorRetry($resettable);

    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});
