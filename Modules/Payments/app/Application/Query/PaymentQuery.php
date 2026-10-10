<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query;

interface PaymentQuery
{
    /**
     * @throws PaymentNotFound when the id is malformed, unknown, or owned by another merchant
     */
    public function findOwned(int $merchantId, string $paymentId): ProjectedPayment|PendingPayment;

    public function recentIntents(int $merchantId): RecentIntents;
}
