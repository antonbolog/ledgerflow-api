<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Integration;

use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use SplObjectStorage;

/**
 * Retains every event object, so a recycled spl_object_id() cannot make two attempts look like one object.
 * Bind it as a container singleton: the projectionist re-resolves handlers through app().
 */
final class IdentityRecorder extends Projector
{
    public int $weight = 1;

    /** @var list<PaymentInitiated> */
    public array $events = [];

    /** @var list<int> diagnostics only, never the basis of an identity assertion */
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

    public function distinctObjects(): int
    {
        $seen = new SplObjectStorage;

        foreach ($this->events as $event) {
            $seen->attach($event);
        }

        return count($seen);
    }
}
