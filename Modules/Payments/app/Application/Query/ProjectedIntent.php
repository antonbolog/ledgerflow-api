<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query;

// The constructor is the merchant-visible allowlist: never add receipt or payment_details internals.
final readonly class ProjectedIntent
{
    public function __construct(
        public string $intentId,
        public string $paymentId,
        public string $createdAt,
        public int $amountMinor,
        public string $currency,
    ) {}
}
