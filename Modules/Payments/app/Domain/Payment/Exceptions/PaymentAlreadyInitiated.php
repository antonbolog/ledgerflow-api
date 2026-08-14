<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Payment\Exceptions;

use DomainException;

/**
 * A payment can be initiated once.
 *
 * Distinct from InvalidPaymentInitiation because the values were fine and the
 * STATE was not: T6 maps the two to different Problem Details.
 */
final class PaymentAlreadyInitiated extends DomainException
{
    public static function for(string $paymentId): self
    {
        return new self("Payment {$paymentId} has already been initiated.");
    }
}
