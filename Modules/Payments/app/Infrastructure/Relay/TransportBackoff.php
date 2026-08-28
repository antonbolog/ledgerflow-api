<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Relay;

use InvalidArgumentException;

/**
 * How long a rejected publication waits before it becomes eligible again.
 *
 * Pure, and therefore provable without Redis, PostgreSQL or a clock. The relay
 * writes the result into `available_at`; nothing else consumes it.
 *
 * THE ARGUMENT IS THE POST-INCREMENT COUNT. The relay reads the locked row, adds
 * one to `consecutive_transport_failures` in SQL, and asks for the delay matching
 * the value the column will hold — so the first rejection asks for
 * secondsAfter(1) and gets the base.
 */
final readonly class TransportBackoff
{
    /**
     * Highest exponent evaluated, chosen so `2 ** EXPONENT_LIMIT` stays an integer
     * far below PHP_INT_MAX on any 64-bit build.
     *
     * NOT COSMETIC. An arbitrarily long transport outage must never set
     * exhausted_at, so this counter is genuinely unbounded — a long weekend with a
     * dead Redis reaches counts nothing else in the system bounds. Unclamped,
     * `2 ** ($count - 1)` overflows into a float, and casting a float above
     * PHP_INT_MAX back to int is undefined in PHP: the result can be negative, and
     * PostgreSQL would accept a negative interval and make the row eligible in the
     * past, forever.
     */
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

    public function secondsAfter(int $consecutiveFailures): int
    {
        // Defensive rather than expected: the relay always passes at least one.
        // Below one, the exponent would be negative and 2 ** -1 is a float that
        // rounds to a zero-second backoff.
        $steps = max(0, min($consecutiveFailures - 1, self::EXPONENT_LIMIT));

        return min($this->baseSeconds * 2 ** $steps, $this->maxSeconds);
    }
}
