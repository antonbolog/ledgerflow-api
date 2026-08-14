<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Infrastructure\Projectors\OutboxEnvelopeNotDerivable;
use Modules\Payments\Infrastructure\Projectors\PaymentOutboxWriter;
use Throwable;

/*
|--------------------------------------------------------------------------
| PaymentOutboxWriter — the two non-replay guards
|--------------------------------------------------------------------------
|
| The replay guard has its own file, because replay is a condition of the
| PROJECTIONIST rather than of the event. These two are conditions of the event
| itself, and neither is reachable through the HTTP surface: an accepted request
| always hands the projector a repository-stamped event whose row it just wrote.
| They are reached here by handing the handler an event directly, which is exactly
| the situation each guard exists for.
|
| WHY EACH TEST WATCHES THE QUERY LOG: the point of a dedicated exception is that
| it names the real problem. Both defects are ALSO detectable as a database error
| — a null id silently selects nothing, and a null payload violates
| payment_outbox.payload NOT NULL — and a generic `SQLSTATE[23502]` sends a
| maintainer looking at the schema instead of at the caller. Asserting the
| exception type alone cannot tell those apart, so each test pins WHICH statements
| reached PostgreSQL: the null-id guard must run before any query at all, and the
| missing-row guard must run after the SELECT and before the INSERT.
|
*/

/** A structurally valid event, exactly as the aggregate would have applied it. */
function handWrittenPaymentInitiated(): PaymentInitiated
{
    $event = new PaymentInitiated(
        eventId: '01984f6f-1111-7000-8000-000000000001',
        paymentId: '01984f6f-2222-7000-8000-000000000002',
        merchantId: 1,
        amountMinor: 1_250,
        currency: 'USD',
        occurredAt: CarbonImmutable::now()->utc()->format(PaymentInitiated::OCCURRED_AT_FORMAT),
    );

    // The metadata the repository would have stamped, MINUS the stored-event id.
    // Supplied so that the only thing wrong with this event is the one thing each
    // test is about: without them a mutation could fail on aggregate_uuid NOT NULL
    // and look like the guard working.
    //
    // Called as statements, not chained: the setters are declared `: self` on
    // ShouldBeStored, so a chain is typed as the base class and would not satisfy
    // this function's return type.
    $event->setAggregateRootUuid($event->paymentId);
    $event->setAggregateRootVersion(1);

    return $event;
}

/**
 * Runs the handler, returning what it threw and every statement it executed.
 *
 * The writer is passed IN rather than resolved here: `$app` is protected on the
 * TestCase, reachable from a bound test closure and not from a plain function.
 *
 * @return array{0: ?Throwable, 1: list<string>}
 */
function runOutboxWriter(PaymentOutboxWriter $writer, PaymentInitiated $event): array
{
    $statements = [];

    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $thrown = null;

    try {
        $writer->onPaymentInitiated($event);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    // Snapshotted BEFORE the assertions run, so the tests' own count() queries
    // cannot pollute what the handler is credited with.
    return [$thrown, $statements];
}

it('refuses an event carrying no stored-event id before it touches the database', function (): void {
    $writer = $this->app->make(PaymentOutboxWriter::class);

    [$thrown, $statements] = runOutboxWriter($writer, handWrittenPaymentInitiated());

    expect($thrown)->toBeInstanceOf(OutboxEnvelopeNotDerivable::class)
        // THE DEDICATED PATH. Delete the guard and the missing-row guard catches the
        // fallout instead, reporting a row that was never named — so the type alone
        // is not enough, the sentence has to be this guard's.
        ->and($thrown?->getMessage())->toContain('carries no stored-event id')
        ->and($thrown?->getMessage())->not->toContain('No stored_events row')
        ->and($thrown?->getMessage())->not->toContain('replay')
        // NOT a database failure, and provably so: a generic constraint violation
        // would arrive as a QueryException carrying a SQLSTATE.
        ->and($thrown?->getMessage())->not->toContain('SQLSTATE');

    // The strongest form of "before it touches the database": the handler ran no
    // statement at all. `where('id', null)` compiles to `id is null`, which is a
    // perfectly valid query — this is what proves it was never sent.
    expect($statements)->toBe([])
        ->and(DB::table('payment_outbox')->count())->toBe(0);
});

it('refuses an event naming a stored-event row that does not exist, without attempting the insert', function (): void {
    // An id no row can have: the table is empty and the sequence starts at 1.
    $event = handWrittenPaymentInitiated();
    $event->setStoredEventId(987_654_321);

    $writer = $this->app->make(PaymentOutboxWriter::class);

    [$thrown, $statements] = runOutboxWriter($writer, $event);

    expect($thrown)->toBeInstanceOf(OutboxEnvelopeNotDerivable::class)
        // THE DEDICATED PATH, naming the row it looked for.
        ->and($thrown?->getMessage())->toContain('No stored_events row 987654321')
        ->and($thrown?->getMessage())->not->toContain('carries no stored-event id')
        ->and($thrown?->getMessage())->not->toContain('replay')
        // Delete this guard and the insert goes ahead with a null payload, which
        // PostgreSQL rejects as SQLSTATE[23502] on payment_outbox.payload — a
        // generic constraint failure that describes the schema rather than the
        // caller. This is the assertion that separates the two.
        ->and($thrown?->getMessage())->not->toContain('SQLSTATE')
        ->and($thrown?->getMessage())->not->toContain('23502');

    // The SELECT happened; the INSERT did not.
    expect($statements)->toHaveCount(1)
        ->and($statements[0])->toContain('stored_events')
        ->and($statements[0])->toStartWith('select');

    foreach ($statements as $statement) {
        expect($statement)->not->toContain('insert into "payment_outbox"');
    }

    expect(DB::table('payment_outbox')->count())->toBe(0);
});
