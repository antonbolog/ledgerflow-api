<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query;

// The constructor is the merchant-visible allowlist: never add internal payment_details columns.
final readonly class ProjectedPayment
{
    public function __construct(
        public string $paymentId,
        public int $amountMinor,
        public string $currency,
        public string $status,
        public string $initiatedAt,
    ) {}
}
