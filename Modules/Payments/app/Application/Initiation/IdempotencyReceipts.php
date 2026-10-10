<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

interface IdempotencyReceipts
{
    /**
     * Every receipt of this merchant bound to the intent or to the key: zero, one, or two distinct rows.
     *
     * @return list<ReceiptCandidate>
     */
    public function findArbitrationCandidates(int $merchantId, string $intentId, string $idempotencyKeyHash): array;

    /**
     * Returns true only when this request won the merchant/intent pair and may write; false when the intent is taken.
     *
     * @throws ReservationRaced when the row collides on any other unique key
     */
    public function reserveIntent(PaymentInitiationContext $context): bool;
}
