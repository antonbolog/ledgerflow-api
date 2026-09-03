<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query;

use RuntimeException;

/**
 * The `payment_details` projection is being rebuilt. Rendered as 503 with
 * Retry-After: 1.
 *
 * A RuntimeException for the same reason PaymentNotFound is one: a legitimate
 * runtime condition a well-behaved client retries, not a programming error.
 *
 * RAISED FROM DatabasePaymentQuery, inside the same transaction as the projection
 * lookup, so the gate check and the read it protects are one statement pair on one
 * connection. See ProjectionGate for why that connection affinity matters.
 */
final class ProjectionRebuilding extends RuntimeException
{
    public static function forRequestedPayment(): self
    {
        return new self('The payment_details projection is being rebuilt; this read is deferred.');
    }
}
