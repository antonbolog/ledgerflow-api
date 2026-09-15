<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

use RuntimeException;

// The message is logged: it may name the stored payment, never the raw idempotency key.
final class IdempotencyPayloadMismatch extends RuntimeException
{
    public static function forStoredPayment(string $paymentId): self
    {
        return new self("Idempotency key already used for payment {$paymentId} with a different payload.");
    }
}
