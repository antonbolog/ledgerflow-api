<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

// Never add the raw idempotency key: only its digest may reach receipts, events, payloads or logs.
final readonly class PaymentInitiationContext
{
    public int $responseStatus;

    public string $responseBody;

    public string $responseLocation;

    public function __construct(
        public int $merchantId,
        public string $paymentId,
        public string $eventId,
        public int $amountMinor,
        public string $currency,
        public string $occurredAt,
        public string $idempotencyKeyHash,
        public string $requestFingerprint,
        public string $intentId,
        // Absolute and BFF-stamped: every attempt and the rerun spend this one deadline.
        public string $intentExpiresAt,
    ) {
        $this->responseStatus = 202;

        $this->responseBody = json_encode([
            'payment_id' => $paymentId,
            'status' => 'initiated',
        ], JSON_THROW_ON_ERROR);

        $this->responseLocation = '/api/payments/'.$paymentId;
    }

    public static function hashKey(string $rawIdempotencyKey): string
    {
        return hash('sha256', $rawIdempotencyKey);
    }

    // The written key order is part of the fingerprint contract; never sort it.
    public static function fingerprint(int $merchantId, int $amountMinor, string $currency): string
    {
        return hash('sha256', json_encode([
            'merchant_id' => $merchantId,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
        ], JSON_THROW_ON_ERROR));
    }
}
