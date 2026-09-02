<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query;

/**
 * The payment is durably committed but its projection has not caught up.
 *
 * CARRIES THE ID AND NOTHING ELSE, because nothing else is known: the committed
 * idempotency receipt proves the payment exists and who owns it, and no business
 * field of the read model has been written yet. A completion estimate or a queue
 * depth would be a guess about a worker this layer cannot see.
 *
 * This is read-model transport state, not a domain payment status. Nothing
 * durable records it, and no aggregate ever transitions into it.
 */
final readonly class PendingPayment
{
    public function __construct(public string $paymentId) {}
}
