<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projectors;

use RuntimeException;

/**
 * The outbox envelope could not be derived from the event it was handed.
 *
 * A bug signal, never a client condition: it means the projector ran somewhere its
 * contract does not hold. ProblemDetailsMapper renders it as a generic 500, and the
 * message reaches the log rather than the caller.
 */
final class OutboxEnvelopeNotDerivable extends RuntimeException
{
    /**
     * The condition NEITHER other factory can report.
     *
     * A replayed event is fully derivable — EloquentStoredEventRepository::persist()
     * writes `stored-event-id` into meta_data and StoredEvent::instantiateEvent()
     * restores it, so storedEventId() is non-null and its row is readable. Both other
     * guards pass and the writer would happily re-create envelopes, or die on
     * payment_outbox's unique constraint naming the wrong problem. Only
     * Projectionist::isReplaying() can tell this apart, and T6 refuses it rather than
     * deciding rebuild semantics by accident.
     */
    public static function duringReplay(string $eventId): self
    {
        return new self(
            "Refusing to write an outbox envelope for event {$eventId} during a projector replay. "
            .'This writer is correct on the live persist path only; whether a rebuild re-creates envelopes '
            .'is T11 scope and must be decided deliberately, not reached by replaying T6.'
        );
    }

    public static function withoutStoredEventId(string $eventId): self
    {
        return new self(
            "Event {$eventId} carries no stored-event id, so there is no stored_events row to copy. "
            .'The outbox writer is only correct on the live persist path.'
        );
    }

    public static function storedEventMissing(int|string $storedEventId): self
    {
        return new self("No stored_events row {$storedEventId} is visible to this transaction.");
    }
}
