<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query;

/**
 * The owned read model, asked for one payment.
 *
 * A port for the reason IdempotencyReceipts is one: Modules\Payments\Application
 * may not name a framework type, so the signature traffics only in scalars and
 * this module's own result types. No connection, query builder or row object
 * crosses the boundary.
 *
 * SEPARATE FROM IdempotencyReceipts DELIBERATELY. That port arbitrates initiation
 * by (merchant_id, idempotency_key_hash) and returns stored HTTP replay bytes;
 * this one answers a merchant's question about projected business state. Merging
 * them would give one interface two use cases and two reasons to change.
 *
 * MERCHANT SCOPE IS PART OF THE QUESTION, not a filter the caller may forget: the
 * merchant id is a required argument, so there is no unscoped lookup to reach for.
 */
interface PaymentQuery
{
    /**
     * @throws PaymentNotFound when the id is malformed, unknown, or owned by
     *                         another merchant - the three are one answer.
     */
    public function findOwned(int $merchantId, string $paymentId): ProjectedPayment|PendingPayment;
}
