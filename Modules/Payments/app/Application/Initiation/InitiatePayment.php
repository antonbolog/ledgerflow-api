<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

use Modules\Payments\Domain\Payment\PaymentAggregate;

/**
 * The initiation use case: one outer transaction, one arbitration, one payment.
 *
 * FRAMEWORK-FREE BY RULE, NOT BY TASTE. tests/Architecture/ModuleBoundaryTest.php
 * permits this layer to name only this module's Domain and Application namespaces
 * and other modules' Application contracts. Everything that touches a connection
 * lives behind the two ports above; Closure is a single-segment global the scanner
 * skips.
 *
 * THE CONTEXT ARRIVES BUILT. Identifiers, occurrence time, hashes and the response
 * bytes are minted in the HTTP layer before this method is entered, so the retry
 * closure has nothing to regenerate and byte-stability across attempts is
 * structural rather than remembered.
 */
final readonly class InitiatePayment
{
    public function __construct(
        private AtomicTransaction $transaction,
        private IdempotencyReceipts $receipts,
    ) {}

    public function __invoke(PaymentInitiationContext $context): StoredIdempotencyReceipt
    {
        return $this->transaction->run(function () use ($context): StoredIdempotencyReceipt {
            if (! $this->receipts->reserve($context)) {
                return $this->replay($context);
            }

            // FRESH OBJECTS ON EVERY ATTEMPT. retrieve() is static and resolves
            // through the container, so each attempt gets a new aggregate and, via
            // initiate(), a new event. Holding either across attempts would carry a
            // rolled-back aggregate version into the next one.
            //
            // initiate() takes scalars rather than the event because
            // AggregateRoot::apply() invokes any method whose parameter accepts the
            // event, so a command method typed against PaymentInitiated would be
            // re-invoked during replay.
            //
            // persist() opens no transaction of its own: this closure's is the only
            // one, and the synchronous outbox projector runs inside it.
            PaymentAggregate::retrieve($context->paymentId)
                ->initiate(
                    eventId: $context->eventId,
                    paymentId: $context->paymentId,
                    merchantId: $context->merchantId,
                    amountMinor: $context->amountMinor,
                    currency: $context->currency,
                    occurredAt: $context->occurredAt,
                )
                ->persist();

            return StoredIdempotencyReceipt::fromContext($context);
        });
    }

    private function replay(PaymentInitiationContext $context): StoredIdempotencyReceipt
    {
        $winner = $this->receipts->findWinner($context->merchantId, $context->idempotencyKeyHash);

        if ($winner === null) {
            throw IdempotencyContention::reservationLostWithoutWinner();
        }

        // hash_equals over two SHA-256 digests. Not a secret comparison — the
        // fingerprint is derived from values the client sent — but constant time
        // costs nothing here and removes the question.
        if (! hash_equals($winner->requestFingerprint, $context->requestFingerprint)) {
            throw IdempotencyPayloadMismatch::forStoredPayment($winner->paymentId);
        }

        return $winner;
    }
}
