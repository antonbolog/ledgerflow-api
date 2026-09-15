<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query;

final readonly class PendingPayment
{
    public function __construct(public string $paymentId) {}
}
