<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Relay;

use InvalidArgumentException;

final readonly class TransportBackoff
{
    // The failure count is unbounded; unclamped, 2 ** n overflows to a float whose int cast can go negative.
    private const int EXPONENT_LIMIT = 40;

    public function __construct(
        private int $baseSeconds,
        private int $maxSeconds,
    ) {
        if ($baseSeconds < 1) {
            throw new InvalidArgumentException(
                "Transport backoff base must be at least one second, got {$baseSeconds}. "
                .'A zero or negative base makes a dead transport a hot loop.'
            );
        }

        if ($maxSeconds < $baseSeconds) {
            throw new InvalidArgumentException(
                "Transport backoff cap ({$maxSeconds}s) must not be below its base ({$baseSeconds}s)."
            );
        }
    }

    // Takes the post-increment failure count, so the first rejection gets the base delay.
    public function secondsAfter(int $consecutiveFailures): int
    {
        $steps = max(0, min($consecutiveFailures - 1, self::EXPONENT_LIMIT));

        return min($this->baseSeconds * 2 ** $steps, $this->maxSeconds);
    }
}
