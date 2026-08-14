<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

/**
 * A committed idempotency receipt, and the use case's answer on BOTH paths.
 *
 * There is no separate result type: the receipt row IS the response. On a winning
 * reservation it is built from the immutable context; on a replay it is read back
 * from the row the winner committed. Both carry the same three response fields, so
 * the caller cannot tell — and must not need to tell — which path produced it.
 */
final readonly class StoredIdempotencyReceipt
{
    public function __construct(
        public string $paymentId,
        public string $requestFingerprint,
        public int $responseStatus,
        public string $responseBody,
        public string $responseLocation,
    ) {}

    public static function fromContext(PaymentInitiationContext $context): self
    {
        return new self(
            paymentId: $context->paymentId,
            requestFingerprint: $context->requestFingerprint,
            responseStatus: $context->responseStatus,
            responseBody: $context->responseBody,
            responseLocation: $context->responseLocation,
        );
    }
}
