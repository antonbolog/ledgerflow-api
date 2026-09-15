<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

interface IdempotencyReceipts
{
    /** Returns true only when this request won the merchant/key pair and may write. */
    public function reserve(PaymentInitiationContext $context): bool;

    public function findWinner(int $merchantId, string $idempotencyKeyHash): ?StoredIdempotencyReceipt;
}
