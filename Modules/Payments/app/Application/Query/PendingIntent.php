<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query;

final readonly class PendingIntent
{
    public function __construct(
        public string $intentId,
        public string $paymentId,
        public string $createdAt,
    ) {}
}
