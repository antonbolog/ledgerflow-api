<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projectors;

use RuntimeException;

final class OutboxEnvelopeNotDerivable extends RuntimeException
{
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
