<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Payment\Exceptions;

use DomainException;

final class PaymentAlreadyInitiated extends DomainException
{
    public static function for(string $paymentId): self
    {
        return new self("Payment {$paymentId} has already been initiated.");
    }
}
