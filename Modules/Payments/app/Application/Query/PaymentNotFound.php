<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query;

use RuntimeException;

// No arguments: a logged message must not distinguish malformed, unknown and foreign payments.
final class PaymentNotFound extends RuntimeException
{
    public static function forRequestedPayment(): self
    {
        return new self('No payment matching the requested identifier is visible to this merchant.');
    }
}
