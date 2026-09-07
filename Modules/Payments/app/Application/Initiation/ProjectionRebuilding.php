<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

use RuntimeException;

/**
 * The `payment_details` projection is being rebuilt. Rendered as 503 with
 * Retry-After: 1, exactly like IdempotencyContention.
 *
 * A RuntimeException for the same reason IdempotencyContention is one: the client
 * caused a legitimate runtime condition, not a programming error.
 *
 * RAISED FROM RetryingAtomicTransaction, NOT FROM InitiatePayment. The gate is an
 * Infrastructure concern (Modules\Payments\Infrastructure\Projection\ProjectionGate)
 * and InitiatePayment must not reference it — the module boundary rule permits
 * Application to reference only its own module's Domain/Application and other
 * modules' Application contracts. The check runs as the first statement inside the
 * transaction RetryingAtomicTransaction opens for each attempt, so it is
 * re-acquired per attempt exactly as the specification requires, without this port
 * ever naming the gate.
 *
 * NEVER RETRIED. It carries no SQLSTATE, so RetryingAtomicTransaction's classifier
 * treats it as non-retryable and it escapes on the first attempt — the gate does
 * not reopen mid-loop, and hammering it three times would only add latency.
 */
final class ProjectionRebuilding extends RuntimeException
{
    public static function duringInitiation(): self
    {
        return new self('The payment_details projection is being rebuilt; initiation is deferred.');
    }
}
