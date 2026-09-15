<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

use RuntimeException;
use Throwable;

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

    // A targeted ON CONFLICT waits for concurrent inserters (ADR 0003), so reaching this means arbitration is unsound.
    public static function reservationLostWithoutWinner(): self
    {
        return new self('The idempotency reservation was lost but no committed winner could be read.');
    }
}
