<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

use RuntimeException;

// The message is logged: it may name the stored payment, never the raw idempotency key.
final class IdempotencyPayloadMismatch extends RuntimeException
{
    public static function forStoredPayment(string $paymentId): self
    {
        return new self("Idempotency key or payment intent already used for payment {$paymentId} with a different request.");
    }

    public static function forCrossedBinding(): self
    {
        return new self('The idempotency key and the payment intent are bound to two different payments.');
    }
}
