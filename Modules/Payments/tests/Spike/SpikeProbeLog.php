<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Spike;

/**
 * Ordered record of what happened inside the spike's write closure.
 *
 * Bound as a container singleton by the test, which is load-bearing: Spatie's
 * Projectionist re-resolves an event handler with app($handler::class) before
 * calling it, so anything passed into the instance handed to addProjector() is
 * discarded. Constructor injection from the container is the only way the writer
 * and the test can share one log.
 */
final class SpikeProbeLog
{
    /** @var list<array{marker: string, backend_pid: int, transaction_level: int}> */
    private array $entries = [];

    /** @var list<array{aggregate: int, event: int, payment_id: string, event_id: string, occurred_at: string, key_hash: string}> */
    private array $builds = [];

    /**
     * Strong references to every object whose id was recorded, so PHP cannot
     * recycle an spl_object_id between retry attempts. See recordBuild().
     *
     * @var list<array{0: object, 1: object}>
     */
    private array $retained = [];

    /**
     * @param  array{backend_pid: int, transaction_level: int}  $probe
     */
    public function record(string $marker, array $probe): void
    {
        $this->entries[] = ['marker' => $marker] + $probe;
    }

    /**
     * Object identity per attempt, alongside the stable context that must NOT
     * change between attempts. Task 4 asserts the first differs and the second
     * does not.
     *
     * The objects are RETAINED, not just their ids. PHP reuses an spl_object_id
     * once the object it belonged to is garbage collected, so a rolled-back
     * attempt's aggregate could be freed and the next attempt's aggregate could
     * legitimately receive the same id. Holding a reference for the lifetime of
     * the log makes the ids provably distinct rather than probably distinct.
     */
    public function recordBuild(object $aggregate, object $event, SpikeCommandContext $context): void
    {
        $this->retained[] = [$aggregate, $event];

        $this->builds[] = [
            'aggregate' => spl_object_id($aggregate),
            'event' => spl_object_id($event),
            'payment_id' => $context->paymentId,
            'event_id' => $context->eventId,
            'occurred_at' => $context->occurredAt,
            'key_hash' => $context->keyHash,
        ];
    }

    /** @return list<string> */
    public function markers(): array
    {
        return array_column($this->entries, 'marker');
    }

    /** @return list<int> */
    public function backendPids(): array
    {
        return array_column($this->entries, 'backend_pid');
    }

    /** @return list<int> */
    public function transactionLevels(): array
    {
        return array_column($this->entries, 'transaction_level');
    }

    /** @return list<array{aggregate: int, event: int, payment_id: string, event_id: string, occurred_at: string, key_hash: string}> */
    public function builds(): array
    {
        return $this->builds;
    }
}
