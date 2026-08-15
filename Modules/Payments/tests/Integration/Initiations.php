<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Integration;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Payments\Application\Initiation\PaymentInitiationContext;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;

/**
 * Builds the immutable command context these tests hand to InitiatePayment.
 *
 * THE USE CASE IS DRIVEN DIRECTLY, NOT OVER HTTP. The commit boundary is a property
 * of InitiatePayment and the runner beneath it; going through the controller would
 * add Sanctum token rows and a personal_access_tokens table to every truncation
 * without adding a single fact about when the transaction committed. The HTTP
 * contract is the Feature suite's job, and it already owns it.
 *
 * The context is built OUTSIDE the transaction, which is the production shape: the
 * identifiers and the clock reading are minted in the HTTP layer, so a retry has
 * nothing to regenerate and byte-stability across attempts is structural.
 */
final class Initiations
{
    public const string KEY = 'integration-idempotency-key';

    public static function context(
        int $merchantId,
        int $amountMinor = 1_250,
        string $key = self::KEY,
    ): PaymentInitiationContext {
        return new PaymentInitiationContext(
            merchantId: $merchantId,
            paymentId: (string) Str::uuid7(),
            eventId: (string) Str::uuid7(),
            amountMinor: $amountMinor,
            currency: 'USD',
            occurredAt: CarbonImmutable::now()->utc()->format(PaymentInitiated::OCCURRED_AT_FORMAT),
            idempotencyKeyHash: PaymentInitiationContext::hashKey($key),
            requestFingerprint: PaymentInitiationContext::fingerprint($merchantId, $amountMinor, 'USD'),
        );
    }
}
