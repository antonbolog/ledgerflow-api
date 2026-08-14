<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Integration;

use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use SplObjectStorage;

/**
 * Records WHICH event object each attempt built, and what it carried.
 *
 * $weight = 1: after the production writer (implicit 0) and before the contention
 * probe (2). It must see every attempt, including the one that finally commits.
 *
 * IT RETAINS THE OBJECTS, AND THAT IS THE WHOLE POINT OF THE CLASS.
 * An earlier version kept only spl_object_id(). PHP reuses an object id as soon as
 * the object it named is destroyed, so once attempt 1's rolled-back event went out
 * of scope, attempt 2's event could legally be handed the SAME id — and
 * `$objectIds[0] !== $objectIds[1]` would fail against code that was behaving
 * perfectly. Nothing in the run guaranteed otherwise; it passed because the events
 * happened to overlap in memory, which is a property of the allocator, not of the
 * freshness contract. Holding a reference makes overlap CERTAIN rather than likely,
 * and turns the assertion into one about object identity instead of about a
 * recycled integer.
 *
 * The numeric ids are kept as diagnostics only. Read distinctObjects() for the
 * claim; read objectIds when a failure needs explaining.
 *
 * CONTAINER SINGLETON, like every test projector here: callEventHandler()
 * re-resolves through app() and discards the registered instance.
 */
final class IdentityRecorder extends Projector
{
    public int $weight = 1;

    /**
     * Every event object seen, RETAINED so no two can share an id by recycling.
     *
     * @var list<PaymentInitiated>
     */
    public array $events = [];

    /**
     * Diagnostics only — never the basis of an identity assertion.
     *
     * @var list<int>
     */
    public array $objectIds = [];

    /** @var list<string> */
    public array $eventIds = [];

    /** @var list<string> */
    public array $occurredAt = [];

    public function onPaymentInitiated(PaymentInitiated $event): void
    {
        $this->events[] = $event;
        $this->objectIds[] = spl_object_id($event);
        $this->eventIds[] = $event->eventId;
        $this->occurredAt[] = $event->occurredAt;
    }

    /**
     * How many DISTINCT objects were seen, by identity rather than by id.
     *
     * SplObjectStorage compares object identity directly, so this answer cannot be
     * corrupted by id reuse the way array_unique(objectIds) could.
     */
    public function distinctObjects(): int
    {
        $seen = new SplObjectStorage;

        foreach ($this->events as $event) {
            $seen->attach($event);
        }

        return count($seen);
    }
}
