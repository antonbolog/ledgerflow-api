<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projectors;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\Projectionist;
use Spatie\EventSourcing\StoredEvents\StoredEvent;

// Must not implement ShouldQueue: it has to run inline inside persist() and the caller's transaction.
// Registered explicitly in PaymentsServiceProvider because Spatie auto-discovery scans app/ only.
final class PaymentOutboxWriter extends Projector
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Projectionist $projectionist,
    ) {}

    public function onPaymentInitiated(PaymentInitiated $event): void
    {
        // Replay first: replayed events carry a stored-event id, so the guards below would pass.
        if ($this->projectionist->isReplaying()) {
            throw OutboxEnvelopeNotDerivable::duringReplay($event->eventId);
        }

        $storedEventId = $event->storedEventId();

        if ($storedEventId === null) {
            throw OutboxEnvelopeNotDerivable::withoutStoredEventId($event->eventId);
        }

        // Copy the stored payload rather than re-serialising, so it equals stored_events by construction.
        $payload = $this->connection->table('stored_events')
            ->where('id', $storedEventId)
            ->value('event_properties');

        if (! is_string($payload)) {
            throw OutboxEnvelopeNotDerivable::storedEventMissing($storedEventId);
        }

        $now = CarbonImmutable::now();

        $this->connection->table('payment_outbox')->insert([
            'event_id' => $event->eventId,
            'event_type' => StoredEvent::getEventClass($event::class),
            'schema_version' => $event->eventVersion(),
            'aggregate_uuid' => $event->aggregateRootUuid(),
            'aggregate_version' => $event->aggregateRootVersion(),
            'payload' => $payload,
            'occurred_at' => $event->occurredAt,
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
