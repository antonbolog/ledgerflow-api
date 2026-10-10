<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Integration;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Payments\Application\Initiation\PaymentInitiationContext;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;

final class Initiations
{
    public const string KEY = 'integration-idempotency-key';

    public const string EXPIRY_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    /** @var array<string, string> */
    private static array $intents = [];

    /**
     * One intent per key unless given, so a repeated key stays a replay rather than a crossed binding.
     * The default expiry is fresh per call: a cached one would outlive the test that created it.
     */
    public static function context(
        int $merchantId,
        int $amountMinor = 1_250,
        string $key = self::KEY,
        ?string $intentId = null,
        ?string $intentExpiresAt = null,
        ?string $paymentId = null,
    ): PaymentInitiationContext {
        return new PaymentInitiationContext(
            merchantId: $merchantId,
            paymentId: $paymentId ?? (string) Str::uuid7(),
            eventId: (string) Str::uuid7(),
            amountMinor: $amountMinor,
            currency: 'USD',
            occurredAt: CarbonImmutable::now()->utc()->format(PaymentInitiated::OCCURRED_AT_FORMAT),
            idempotencyKeyHash: PaymentInitiationContext::hashKey($key),
            requestFingerprint: PaymentInitiationContext::fingerprint($merchantId, $amountMinor, 'USD'),
            intentId: $intentId ?? self::$intents[$key] ??= (string) Str::uuid(),
            intentExpiresAt: $intentExpiresAt ?? CarbonImmutable::now('UTC')->addSeconds(30)->format(self::EXPIRY_FORMAT),
        );
    }
}
