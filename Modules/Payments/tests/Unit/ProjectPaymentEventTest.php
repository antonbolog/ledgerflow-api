<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Modules\Payments\Infrastructure\Relay\ProjectPaymentEvent;

/*
|--------------------------------------------------------------------------
| The relayed message
|--------------------------------------------------------------------------
|
| The outbox specification puts the canonical envelope in PostgreSQL and has the
| consumer load it from there, so the message carries an identifier and nothing
| else. Framework-free assertions: only the shape and the refusal matter here,
| and both are provable without Redis, a queue or a database.
|
*/

it('carries the event id and nothing else', function (): void {
    $properties = new ReflectionClass(ProjectPaymentEvent::class)->getProperties();

    expect(array_map(fn (ReflectionProperty $p): string => $p->getName(), $properties))
        ->toBe(['eventId']);

    expect(new ProjectPaymentEvent('01984f6f-6a20-7000-8000-000000000001')->eventId)
        ->toBe('01984f6f-6a20-7000-8000-000000000001');
});

it('keeps business data out of the serialised message', function (): void {
    // What a worker would read back. Asserted on the serialised form rather than
    // on the object, because that is what actually crosses the transport.
    $serialised = serialize(new ProjectPaymentEvent('01984f6f-6a20-7000-8000-000000000001'));

    expect($serialised)->toContain('01984f6f-6a20-7000-8000-000000000001');

    foreach (['amountMinor', 'currency', 'merchant', 'occurredAt', 'payload', 'aggregate'] as $business) {
        expect($serialised)->not->toContain($business);
    }
});

it('opts into nothing that could defer publication past commit', function (): void {
    // Queue::shouldDispatchAfterCommit() checks ShouldQueueAfterCommit and a truthy
    // $afterCommit property BEFORE it reads the connection's after_commit config
    // (Queue/Queue.php:396-408), so either would silently override the literal
    // after_commit => false on the payments-projection connection — and immediate
    // publication inside the still-open transaction is the whole basis of the
    // documented safe-duplicate property.
    //
    // The third spelling, ->afterCommit(), leaves no runtime trace and is caught by
    // the source rule in tests/Architecture/ModuleBoundaryTest.php instead.
    expect(new ProjectPaymentEvent('e'))->not->toBeInstanceOf(ShouldQueueAfterCommit::class);
    expect(property_exists(ProjectPaymentEvent::class, 'afterCommit'))->toBeFalse();
});

it('refuses loudly rather than silently draining the queue before T8 exists', function (): void {
    // A handle() that did nothing would let a stray `queue:work --queue=payments-projection`
    // consume every message and leave the outbox looking delivered while nothing was
    // ever projected — a false green with no error anywhere. T8 replaces this body
    // with the real consumer.
    expect(fn () => new ProjectPaymentEvent('01984f6f-6a20-7000-8000-000000000001')->handle())
        ->toThrow(LogicException::class, '01984f6f-6a20-7000-8000-000000000001');
});
