<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

use RuntimeException;
use Throwable;

// The outcome is unknown: the session may have died while a COMMIT was already durable, so it is never retried.
final class PaymentIntentSessionTerminated extends RuntimeException
{
    public static function afterConnectionLoss(?Throwable $previous = null): self
    {
        return new self('The database session ended before the initiation outcome was known.', 0, $previous);
    }
}
