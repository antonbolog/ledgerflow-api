<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

use RuntimeException;
use Throwable;

/**
 * The request could not be arbitrated. Rendered as 503 with Retry-After: 1.
 *
 * Two causes, deliberately one type: the retry budget was exhausted against an
 * allowlisted SQLSTATE, or the reservation was lost with no winner to replay. Both
 * are "try again", and neither tells a client anything actionable beyond that.
 *
 * The SQLSTATE rides in the MESSAGE, which is reported to logs, never into the
 * problem document — ProblemDetailsMapper renders the type's own safe sentence for
 * everything except an unmapped failure in debug.
 */
final class IdempotencyContention extends RuntimeException
{
    public static function afterAttempts(int $attempts, ?string $sqlState = null, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Payment initiation was not arbitrated after %d attempts (last SQLSTATE %s).', $attempts, $sqlState ?? 'none'),
            0,
            $previous,
        );
    }

    /**
     * H-1 measured that a targeted ON CONFLICT DO NOTHING WAITS for a concurrent
     * inserter to commit or abort, so a zero-row reservation implies a committed,
     * readable winner. If this ever fires, that measurement no longer holds and the
     * arbitration is unsound — which is worth a loud 503 rather than a second
     * payment.
     */
    public static function reservationLostWithoutWinner(): self
    {
        return new self('The idempotency reservation was lost but no committed winner could be read.');
    }
}
