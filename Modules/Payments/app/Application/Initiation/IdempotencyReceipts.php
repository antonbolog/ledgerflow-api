<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

/**
 * Durable arbitration over (merchant_id, idempotency_key_hash).
 *
 * A port because Modules\Payments\Application may not name a framework type — the
 * module boundary rule permits only this module's Domain and Application plus other
 * modules' Application namespaces. Its signatures traffic only in this module's own
 * types and scalars, so no Eloquent model, connection or query builder crosses the
 * boundary.
 */
interface IdempotencyReceipts
{
    /** True when THIS request won the merchant/key pair and may proceed to write. */
    public function reserve(PaymentInitiationContext $context): bool;

    public function findWinner(int $merchantId, string $idempotencyKeyHash): ?StoredIdempotencyReceipt;
}
