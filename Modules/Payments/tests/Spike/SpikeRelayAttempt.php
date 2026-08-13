<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Spike;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * One relay pass, shaped like the outbox spec's lock-through-push algorithm and
 * implementing none of it for production.
 *
 *   1. select the eligible row FOR UPDATE
 *   2. publish the event id to Redis
 *   3. mark the row published
 *   4. hit the injectable failure boundary
 *
 * BOTH Q10 PASSES INVOKE THE SAME INSTANCE OF THIS CLASS. That is the whole
 * point: T3 learned that a second attempt which quietly differs from the first
 * proves a retry ran while pretending the contention was released. Here the
 * risk is the same in a new place — a hand-written second pass could make the
 * duplicate an artifact of the pass rather than of the rolled-back first one.
 * The only state that differs between the two invocations is whether
 * SpikeFailureSwitch is armed.
 *
 * push(), never pushRaw(). pushRaw bypasses the after_commit check entirely and
 * is unconditionally immediate, so it could not fail the question Q9 asks.
 *
 * The eligibility predicate is literally "last_published_at is null" and stays
 * that way. No available_at, no backoff, no exhausted_at: the outbox state
 * machine belongs to T7, and TODOS.md already forbids anticipating it.
 */
final readonly class SpikeRelayAttempt
{
    public function __construct(
        private string $eventId,
        private SpikeFailureSwitch $switch,
    ) {}

    public function __invoke(): void
    {
        $connection = DB::connection(SpikeDatabase::CONNECTION);

        $row = $connection->table('spike_outbox_delivery')
            ->where('event_id', $this->eventId)
            ->whereNull('last_published_at')
            ->lockForUpdate()
            ->first();

        // A missing row is meaningful rather than incidental. On pass 2 it would
        // mean the rollback did NOT restore eligibility, which is the failure
        // Q10 exists to detect, so it must be loud instead of a silent no-op.
        if ($row === null) {
            throw new RuntimeException(sprintf(
                'No eligible spike_outbox_delivery row for event %s. On a second pass this means the '
                .'previous rollback did not restore last_published_at to null.',
                $this->eventId,
            ));
        }

        Queue::connection(SpikeRelayQueue::CONNECTION)->push(
            new SpikeRelayJob($this->eventId),
            '',
            SpikeRelayQueue::QUEUE,
        );

        $connection->table('spike_outbox_delivery')
            ->where('event_id', $this->eventId)
            ->update([
                'last_published_at' => now(),
                'publish_count' => ((int) $row->publish_count) + 1,
            ]);

        // The crash window the outbox spec names: after the transport accepted
        // the message, before the transaction commits.
        $this->switch->trip('after-publish');
    }
}
