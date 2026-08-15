<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

use RuntimeException;

/**
 * The merchant/key pair is already owned by a request whose canonical payload
 * differs. Rendered as 409.
 *
 * A RuntimeException, not a LogicException: the client caused a legitimate runtime
 * condition, not a programming error.
 *
 * IT NAMES THE STORED PAYMENT, NEVER THE KEY. The raw idempotency key must not
 * reach an exception message, because that message is reported and logged.
 */
final class IdempotencyPayloadMismatch extends RuntimeException
{
    public static function forStoredPayment(string $paymentId): self
    {
        return new self("Idempotency key already used for payment {$paymentId} with a different payload.");
    }
}
