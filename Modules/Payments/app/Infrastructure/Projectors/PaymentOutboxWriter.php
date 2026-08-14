<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projectors;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\Projectionist;
use Spatie\EventSourcing\StoredEvents\StoredEvent;

/**
 * Step 5 of the atomic write: one outbox envelope, written synchronously inside
 * persist(), inside the caller's transaction.
 *
 * IT MUST NOT IMPLEMENT ShouldQueue. Spatie splits handlers purely on
 * `instanceof ShouldQueue`: sync handlers run inline inside persist(), and a job is
 * dispatched only when some handler IS ShouldQueue. Omitting the interface is what
 * keeps this inline and inside the transaction — there is no configuration that
 * could restore it, and no after-commit hook is involved.
 *
 * IT IS REGISTERED EXPLICITLY by PaymentsServiceProvider, because
 * auto_discover_projectors_and_reactors defaults to [app()->path()] — app/, never
 * Modules/. A projector living here is invisible to discovery and the only symptom
 * would be an empty outbox.
 *
 * COLLABORATORS ARRIVE BY CONSTRUCTOR INJECTION because
 * Projectionist::callEventHandler() re-resolves the handler with app($handler::class)
 * and throws the registered instance away.
 *
 * SAME CONNECTION AS THE TRANSACTION: ConnectionInterface is a container alias of
 * db.connection, which resolves to the default connection, cached per name by
 * DatabaseManager — the same object the retry runner opened the transaction on and
 * the same one Spatie's EloquentStoredEvent writes through.
 *
 * THE PAYLOAD IS READ BACK, NOT RE-SERIALISED. The handling method receives the
 * EVENT only — HandlesEvents::handle() never passes the StoredEvent — but the event
 * carries the stored-event id that EloquentStoredEventRepository::persist() stamped
 * on this very instance. Reading that row makes jsonb-equality with
 * stored_events.event_properties true BY CONSTRUCTION. A second serializer call
 * would be an empirical claim that a future normalizer change could quietly falsify.
 *
 * The column mapping is lifted from T5's proven derivation, not re-derived:
 * Modules/Payments/tests/Feature/PaymentInitiationPersistenceTest.php.
 */
final class PaymentOutboxWriter extends Projector
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Projectionist $projectionist,
    ) {}

    /** Discovered by PARAMETER TYPE, not by name. */
    public function onPaymentInitiated(PaymentInitiated $event): void
    {
        // REPLAY IS NOT DETECTABLE FROM THE EVENT, AND THIS MUST COME FIRST.
        //
        // A replayed event is fully derivable: EloquentStoredEventRepository::persist()
        // writes `stored-event-id` into meta_data (:132), and
        // StoredEvent::instantiateEvent() restores that meta_data onto the
        // reconstituted event — so storedEventId() is NON-NULL on a replay and its
        // stored_events row is right there to read. Both guards below therefore pass,
        // and a standard `event-sourcing:replay` would re-create every envelope it
        // can, or die incidentally on payment_outbox's unique constraint reporting a
        // problem that is not the real one.
        //
        // Projectionist::isReplaying() is the only thing that can tell the two paths
        // apart. It is a container SINGLETON, so the instance injected here is the one
        // replay() flips. Refusing LOUDLY rather than skipping silently: a rebuild
        // that quietly produced no envelopes would look successful and leave the
        // outbox permanently short. Whether a rebuild re-creates envelopes is T11's
        // decision to make deliberately.
        if ($this->projectionist->isReplaying()) {
            throw OutboxEnvelopeNotDerivable::duringReplay($event->eventId);
        }

        // storedEventId() is int|string|null. It is null when the event never went
        // through the repository at all — a hand-constructed event handed straight to
        // a handler. Without this guard, where('id', null) matches nothing, value()
        // returns null, and the insert dies on payload NOT NULL with a message naming
        // the wrong problem.
        $storedEventId = $event->storedEventId();

        if ($storedEventId === null) {
            throw OutboxEnvelopeNotDerivable::withoutStoredEventId($event->eventId);
        }

        $payload = $this->connection->table('stored_events')
            ->where('id', $storedEventId)
            ->value('event_properties');

        if (! is_string($payload)) {
            throw OutboxEnvelopeNotDerivable::storedEventMissing($storedEventId);
        }

        $now = CarbonImmutable::now();

        $this->connection->table('payment_outbox')->insert([
            'event_id' => $event->eventId,
            // The ALIAS, never the class name, so the namespace can move without
            // rewriting history.
            'event_type' => StoredEvent::getEventClass($event::class),
            'schema_version' => $event->eventVersion(),
            // Both come from the event's own metaData and are NOT NULL columns, and
            // payment_outbox_aggregate_version_unique mirrors the key stored_events
            // already enforces — so an envelope for the wrong aggregate or version
            // cannot be written silently.
            'aggregate_uuid' => $event->aggregateRootUuid(),
            'aggregate_version' => $event->aggregateRootVersion(),
            'payload' => $payload,
            // Business time from the event, NOT now(): the envelope carries when the
            // payment was initiated, not when the row was written.
            'occurred_at' => $event->occurredAt,
            // Immediately eligible. T7 owns every later mutation of this column.
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // publish_count, consecutive_transport_failures and projection_failure_count
        // are deliberately NOT supplied: they take their zero defaults, which is what
        // outbox-delivery.md requires of T6. T7 owns two of them, T8 the third.
    }
}
