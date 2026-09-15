<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Infrastructure\Projectors\OutboxEnvelopeNotDerivable;
use Modules\Payments\Infrastructure\Projectors\PaymentOutboxWriter;
use Throwable;

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

    // Separate statements: the setters return ShouldBeStored, which would not satisfy the return type.
    $event->setAggregateRootUuid($event->paymentId);
    $event->setAggregateRootVersion(1);

    return $event;
}

/**
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

    // Returned before assertions run, so their own queries are not counted.
    return [$thrown, $statements];
}

it('refuses an event carrying no stored-event id before it touches the database', function (): void {
    $writer = $this->app->make(PaymentOutboxWriter::class);

    [$thrown, $statements] = runOutboxWriter($writer, handWrittenPaymentInitiated());

    expect($thrown)->toBeInstanceOf(OutboxEnvelopeNotDerivable::class)
        ->and($thrown?->getMessage())->toContain('carries no stored-event id')
        ->and($thrown?->getMessage())->not->toContain('No stored_events row')
        ->and($thrown?->getMessage())->not->toContain('replay')
        ->and($thrown?->getMessage())->not->toContain('SQLSTATE');

    expect($statements)->toBe([])
        ->and(DB::table('payment_outbox')->count())->toBe(0);
});

it('refuses an event naming a stored-event row that does not exist, without attempting the insert', function (): void {
    $event = handWrittenPaymentInitiated();
    $event->setStoredEventId(987_654_321);

    $writer = $this->app->make(PaymentOutboxWriter::class);

    [$thrown, $statements] = runOutboxWriter($writer, $event);

    expect($thrown)->toBeInstanceOf(OutboxEnvelopeNotDerivable::class)
        ->and($thrown?->getMessage())->toContain('No stored_events row 987654321')
        ->and($thrown?->getMessage())->not->toContain('carries no stored-event id')
        ->and($thrown?->getMessage())->not->toContain('replay')
        ->and($thrown?->getMessage())->not->toContain('SQLSTATE')
        ->and($thrown?->getMessage())->not->toContain('23502');

    expect($statements)->toHaveCount(1)
        ->and($statements[0])->toContain('stored_events')
        ->and($statements[0])->toStartWith('select');

    foreach ($statements as $statement) {
        expect($statement)->not->toContain('insert into "payment_outbox"');
    }

    expect(DB::table('payment_outbox')->count())->toBe(0);
});
