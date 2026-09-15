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
