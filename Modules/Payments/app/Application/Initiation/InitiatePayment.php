<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

use Modules\Payments\Domain\Payment\PaymentAggregate;

final readonly class InitiatePayment
{
    public function __construct(
        private AtomicTransaction $transaction,
        private IdempotencyReceipts $receipts,
        private PaymentIntentDeadline $deadline,
    ) {}

    public function __invoke(PaymentInitiationContext $context): StoredIdempotencyReceipt
    {
        try {
            return $this->attempt($context);
        } catch (ReservationRaced) {
            // Once, with the same context and so the same deadline: the race means another row committed, and the rerun
            // classifies it. A second race can only be a payment_id collision, which surfaces as an internal error.
            return $this->attempt($context);
        }
    }

    private function attempt(PaymentInitiationContext $context): StoredIdempotencyReceipt
    {
        return $this->transaction->run(function () use ($context): StoredIdempotencyReceipt {
            // A committed intent replays even after its expiry, so classification precedes the deadline.
            $winner = $this->classify($context);

            if ($winner !== null) {
                return $winner;
            }

            $this->deadline->arm($context);

            if (! $this->receipts->reserveIntent($context)) {
                return $this->classify($context) ?? throw IdempotencyContention::reservationLostWithoutWinner();
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

            $this->deadline->recheck($context);

            return StoredIdempotencyReceipt::fromContext($context);
        });
    }

    private function classify(PaymentInitiationContext $context): ?StoredIdempotencyReceipt
    {
        $candidates = $this->receipts->findArbitrationCandidates(
            $context->merchantId,
            $context->intentId,
            $context->idempotencyKeyHash,
        );

        if ($candidates === []) {
            return null;
        }

        if (count($candidates) > 1) {
            throw IdempotencyPayloadMismatch::forCrossedBinding();
        }

        $candidate = $candidates[0];

        // A receipt without an intent was written by a worker that ignored the headers; it matched on the key, so a retry
        // straddling the cutover replays it rather than being settled as rejected.
        $boundElsewhere = $candidate->intentId !== null && $candidate->intentId !== $context->intentId;

        if ($boundElsewhere || ! hash_equals($candidate->receipt->requestFingerprint, $context->requestFingerprint)) {
            throw IdempotencyPayloadMismatch::forStoredPayment($candidate->receipt->paymentId);
        }

        return $candidate->receipt;
    }
}
