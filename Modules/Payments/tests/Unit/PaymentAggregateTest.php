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
    // Version 1, not 0: recordThat() applies the event before it stamps the version.
    $events = initiateOn(new PaymentAggregate)->getRecordedEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PaymentInitiated::class)
        ->and($events[0]->aggregateRootVersion())->toBe(1);
});

it('refuses a second initiation, which is what proves apply() ran', function (): void {
    $aggregate = initiateOn(new PaymentAggregate);

    expect(fn () => initiateOn($aggregate))->toThrow(PaymentAlreadyInitiated::class);
});
