<?php

declare(strict_types=1);

use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Domain\Payment\Exceptions\PaymentAlreadyInitiated;
use Modules\Payments\Domain\Payment\PaymentAggregate;

function initiateOn(PaymentAggregate $aggregate): PaymentAggregate
{
    return $aggregate->initiate(
        eventId: '0197c9b0-1111-7000-8000-00000000aaaa',
        paymentId: '0197c9b0-2222-7000-9000-00000000bbbb',
        merchantId: 1,
        amountMinor: 1_250,
        currency: 'USD',
        occurredAt: '2026-08-14T10:11:12.123456+00:00',
    );
}

it('records one PaymentInitiated stamped with aggregate version 1', function (): void {
    // `new` rather than retrieve(): retrieve() resolves through the container and
    // replays from the database, and this suite is framework-free. Version 1 and
    // not 0 because recordThat() applies BEFORE it stamps — and 1 is the value
    // stored_events' unique key and payment_outbox.aggregate_version carry.
    $events = initiateOn(new PaymentAggregate)->getRecordedEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PaymentInitiated::class)
        ->and($events[0]->aggregateRootVersion())->toBe(1);
});

it('refuses a second initiation, which is what proves apply() ran', function (): void {
    $aggregate = initiateOn(new PaymentAggregate);

    expect(fn () => initiateOn($aggregate))->toThrow(PaymentAlreadyInitiated::class);
});
