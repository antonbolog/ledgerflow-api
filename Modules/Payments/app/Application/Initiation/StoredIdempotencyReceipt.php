<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

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
