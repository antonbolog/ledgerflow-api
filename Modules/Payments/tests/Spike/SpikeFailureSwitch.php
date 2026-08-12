<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Spike;

/**
 * Deterministic failure injection for code that the test cannot hold a reference
 * to.
 *
 * Projectionist::callEventHandler() re-resolves every handler with
 * app($handler::class) before invoking it, so the SpikeOutboxWriter instance the
 * test passed to addProjector() is discarded and a container-built one runs
 * instead. Attaching a callback to that instance would therefore never fire.
 *
 * Binding this switch as a container SINGLETON solves it: the rebuilt writer
 * receives the same switch the test armed, without the test needing a reference
 * to the writer at all.
 *
 * This is test-only fixture code. Production Payments logic must never carry an
 * equivalent, which is why docs/testing/payments-resilience.md forbids
 * environment-conditioned fault flags: the injection point lives in the fixture,
 * not in the code under test.
 */
final class SpikeFailureSwitch
{
    private bool $armed = false;

    private ?string $trippedAt = null;

    public function arm(): void
    {
        $this->armed = true;
    }

    /**
     * Throw if armed. The caller names the boundary so the assertion message
     * says where the failure was planted.
     */
    public function trip(string $boundary): void
    {
        if (! $this->armed) {
            return;
        }

        $this->trippedAt = $boundary;

        throw new SpikeInjectedFailure('Injected spike failure at: '.$boundary);
    }

    public function trippedAt(): ?string
    {
        return $this->trippedAt;
    }
}
