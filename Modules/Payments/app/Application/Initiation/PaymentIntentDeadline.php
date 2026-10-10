<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

interface PaymentIntentDeadline
{
    /**
     * Bounds the rest of the current attempt by the intent expiry; call inside the attempt's transaction.
     *
     * @throws PaymentIntentDeadlineRefused when too little time is left to start creating work
     */
    public function arm(PaymentInitiationContext $context): void;

    /**
     * Call as the attempt's last statement before COMMIT.
     *
     * @throws PaymentIntentDeadlineRefused when too little time is left to commit
     */
    public function recheck(PaymentInitiationContext $context): void;

    public function exceedsCap(string $intentExpiresAt): bool;
}
