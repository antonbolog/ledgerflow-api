<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Modules\Payments\Infrastructure\Projection\PaymentProjectionConsumer;
use Modules\Payments\Infrastructure\Relay\ProjectPaymentEvent;

it('carries the event id and nothing else', function (): void {
    $properties = new ReflectionClass(ProjectPaymentEvent::class)->getProperties();

    expect(array_map(fn (ReflectionProperty $p): string => $p->getName(), $properties))
        ->toBe(['eventId']);

    expect(new ProjectPaymentEvent('01984f6f-6a20-7000-8000-000000000001')->eventId)
        ->toBe('01984f6f-6a20-7000-8000-000000000001');
});

it('keeps business data out of the serialised message', function (): void {
    $serialised = serialize(new ProjectPaymentEvent('01984f6f-6a20-7000-8000-000000000001'));

    expect($serialised)->toContain('01984f6f-6a20-7000-8000-000000000001');

    foreach (['amountMinor', 'currency', 'merchant', 'occurredAt', 'payload', 'aggregate'] as $business) {
        expect($serialised)->not->toContain($business);
    }
});

it('opts into nothing that could defer publication past commit', function (): void {
    expect(new ProjectPaymentEvent('e'))->not->toBeInstanceOf(ShouldQueueAfterCommit::class);
    expect(property_exists(ProjectPaymentEvent::class, 'afterCommit'))->toBeFalse();
});

it('takes its consumer as a handle() parameter, never as serialised state', function (): void {
    $handle = new ReflectionMethod(ProjectPaymentEvent::class, 'handle');

    expect($handle->getNumberOfParameters())->toBe(1)
        ->and($handle->getParameters()[0]->getType()?->__toString())
        ->toBe(PaymentProjectionConsumer::class);

    expect(new ReflectionMethod(ProjectPaymentEvent::class, '__construct')->getNumberOfParameters())
        ->toBe(1);
});
