<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Spike;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Test-only stand-in for the real PaymentInitiated, which T5 owns.
 *
 * It exists to make the spike's persistence path real: Spatie only stores classes
 * extending ShouldBeStored, and the serializer, the aggregate-version constraint
 * and the handler-method discovery all key off that type.
 */
final class SpikePaymentInitiated extends ShouldBeStored
{
    public function __construct(
        public string $eventId,
        public string $paymentId,
        public int $merchantId,
        public int $amountMinor,
        public string $currency,
        public string $occurredAt,
    ) {}
}
