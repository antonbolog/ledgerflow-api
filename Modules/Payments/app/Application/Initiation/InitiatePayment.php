<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

use Modules\Payments\Domain\Payment\PaymentAggregate;

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

            // Retrieve per attempt: a retained aggregate would carry a rolled-back version into the retry.
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

        if (! hash_equals($winner->requestFingerprint, $context->requestFingerprint)) {
            throw IdempotencyPayloadMismatch::forStoredPayment($winner->paymentId);
        }

        return $winner;
    }
}
