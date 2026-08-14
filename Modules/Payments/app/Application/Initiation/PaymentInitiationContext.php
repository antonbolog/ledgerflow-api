<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

/**
 * Everything that must be identical across every transaction attempt.
 *
 * The specification requires this to be built BEFORE the transaction opens and
 * reused unchanged: stable identifiers, occurrence time, canonical input,
 * fingerprint, key hash, and the complete success response serialised exactly
 * once. A retry rebuilds the aggregate and the event from this; it never rebuilds
 * this.
 *
 * Readonly promotion is what enforces that, and it is not decoration. The
 * identifiers and the clock reading are taken in the HTTP layer, outside the retry
 * closure, so an attempt has nothing to regenerate them from even if someone tried.
 *
 * THE RAW IDEMPOTENCY KEY IS NOT HERE, AND MUST NEVER BE ADDED. Only its digest is.
 * Hashing is not encryption — the specification is explicit about that — but it
 * gives fixed-width lookup and keeps plaintext out of a durable receipt, a stored
 * event, an outbox payload and every log line that mentions this object.
 */
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
    ) {
        $this->responseStatus = 202;

        // ONE json_encode, here and nowhere else. The contract is that an identical
        // retry replays the exact bytes the first caller received, so those bytes
        // need exactly one producer. JSON_THROW_ON_ERROR because a silent `false`
        // would be stored as the string "" and replayed forever.
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

    /**
     * Canonical values only, in a written key order.
     *
     * Taken AFTER canonicalisation, which is what makes 'usd' and 'USD' one request
     * rather than a payload mismatch. The order is the contract and is written out
     * rather than sorted, so it cannot drift with a sort implementation.
     */
    public static function fingerprint(int $merchantId, int $amountMinor, string $currency): string
    {
        return hash('sha256', json_encode([
            'merchant_id' => $merchantId,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
        ], JSON_THROW_ON_ERROR));
    }
}
