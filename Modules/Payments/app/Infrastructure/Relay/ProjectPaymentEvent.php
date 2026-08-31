<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Relay;

use Modules\Payments\Infrastructure\Projection\PaymentProjectionConsumer;

/**
 * The message the relay publishes: one outbox event id, and nothing else.
 *
 * THE ENVELOPE STAYS IN POSTGRESQL. The outbox specification makes the consumer
 * load the canonical envelope from `payment_outbox` by this id, so the transport
 * carries an identifier rather than business data. Redis is never authoritative
 * and never holds a payment payload.
 *
 * T8 REPLACED THE REFUSING BODY WITH THE REAL CONSUMER. Everything else about
 * this class is unchanged on purpose: the fully-qualified name, the constructor
 * and the single serialized property are the wire contract for messages already
 * sitting on the queue.
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
     * Hand the event id to the consumer, and nothing else.
     *
     * THE CONSUMER ARRIVES AS A METHOD PARAMETER, NOT AS A CONSTRUCTOR ARGUMENT,
     * and that is a serialization constraint rather than a style preference. This
     * object is serialized onto Redis by the relay: a constructor dependency would
     * be serialized with it — a Connection, a logger and a PDO handle inside a
     * queue payload — and would then be UNSERIALIZED into a worker process where
     * none of it is valid. Laravel resolves a type-hinted handle() parameter
     * through the container at call time (CallQueuedHandler::call() dispatches
     * through Container::call()), so the dependency is built fresh in the worker
     * and never crosses the transport.
     *
     * THE PROPERTY LIST IS THEREFORE STILL EXACTLY `eventId`, which is what keeps
     * messages the relay published BEFORE this method existed deserializable now.
     * A queue that survived a deploy holds envelopes serialized against the old
     * shape, and adding a property would make every one of them fail to restore.
     *
     * NO try/catch, NO acknowledgement of failure. Every exception the consumer
     * raises must reach the queue worker, which is what produces the retained
     * failed-job record. Swallowing one here would mark an envelope delivered while
     * nothing was projected — the same false green the refusing body used to guard
     * against, arrived at by a different route.
     */
    public function handle(PaymentProjectionConsumer $consumer): void
    {
        $consumer->project($this->eventId);
    }
}
