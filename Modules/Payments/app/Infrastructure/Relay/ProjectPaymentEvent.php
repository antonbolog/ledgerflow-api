<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Relay;

use LogicException;

/**
 * The message the relay publishes: one outbox event id, and nothing else.
 *
 * THE ENVELOPE STAYS IN POSTGRESQL. The outbox specification makes the consumer
 * load the canonical envelope from `payment_outbox` by this id, so the transport
 * carries an identifier rather than business data. Redis is never authoritative
 * and never holds a payment payload.
 *
 * IT MUST NOT IMPLEMENT ShouldQueueAfterCommit, MUST NOT DECLARE $afterCommit,
 * AND MUST NEVER BE DISPATCHED WITH ->afterCommit(). Queue::shouldDispatchAfterCommit()
 * checks the interface and that property BEFORE it reads the connection's
 * after_commit config (Queue/Queue.php:396-408), so any of the three would defer
 * publication past COMMIT even though `payments-projection` is configured
 * `after_commit => false`. Immediate publication inside the still-open transaction
 * is what makes a crash before COMMIT produce one safe duplicate instead of a lost
 * projection, so this is a correctness constraint, not a style rule.
 *
 * It implements no queue interface at all. `push()` does not need ShouldQueue —
 * T4 measured that with SpikeRelayJob — and every interface added here is another
 * chance to walk into the trap above.
 */
final readonly class ProjectPaymentEvent
{
    public function __construct(public string $eventId) {}

    /**
     * T8 replaces this body with the real projection consumer.
     *
     * REFUSING LOUDLY IS THE POINT. An empty handle() would let a stray
     * `queue:work --queue=payments-projection` drain the queue, marking every
     * envelope delivered while nothing was ever projected — a false green with no
     * error anywhere. A plain LogicException, because this is a programming
     * condition and the outbox failure vocabulary belongs to the columns, not to an
     * exception hierarchy nothing else would use.
     */
    public function handle(): never
    {
        throw new LogicException(sprintf(
            'No projection consumer exists yet, so outbox event %s must not be consumed. '
            .'Publishing is T7; consuming, projector receipts and Horizon supervision are T8, '
            .'which replaces this method. Until then the relay is deliberately not run outside '
            .'its isolated evidence flow.',
            $this->eventId,
        ));
    }
}
