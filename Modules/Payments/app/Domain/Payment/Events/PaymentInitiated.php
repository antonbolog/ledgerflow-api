<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Payment\Events;

use DateTimeImmutable;
use Modules\Payments\Domain\Payment\Exceptions\InvalidPaymentInitiation;
use Override;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

// ALIAS and the property names are durable stored-event keys; renaming either needs a new alias.
// occurredAt is a string: Spatie's DateTimeNormalizer would truncate a DateTimeImmutable to whole seconds.
final class PaymentInitiated extends ShouldBeStored
{
    public const string ALIAS = 'payments.payment_initiated';

    public const string OCCURRED_AT_FORMAT = 'Y-m-d\TH:i:s.uP';

    // \A and \z, never ^ and $: $ also matches before a trailing newline.
    private const string UUID7_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    public function __construct(
        public readonly string $eventId,
        public readonly string $paymentId,
        public readonly int $merchantId,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $occurredAt,
    ) {
        if (preg_match(self::UUID7_PATTERN, $eventId) !== 1) {
            throw InvalidPaymentInitiation::identifier('eventId', $eventId);
        }

        if (preg_match(self::UUID7_PATTERN, $paymentId) !== 1) {
            throw InvalidPaymentInitiation::identifier('paymentId', $paymentId);
        }

        if ($merchantId < 1) {
            throw InvalidPaymentInitiation::merchant($merchantId);
        }

        if ($amountMinor < 1 || $amountMinor > 100_000_000) {
            throw InvalidPaymentInitiation::amount($amountMinor);
        }

        if ($currency !== 'USD') {
            throw InvalidPaymentInitiation::currency($currency);
        }

        // The re-format comparison rejects dates createFromFormat rolls forward, such as 2026-02-30.
        $parsed = DateTimeImmutable::createFromFormat(self::OCCURRED_AT_FORMAT, $occurredAt);

        if ($parsed === false
            || $parsed->format(self::OCCURRED_AT_FORMAT) !== $occurredAt
            || $parsed->getOffset() !== 0) {
            throw InvalidPaymentInitiation::occurredAt($occurredAt, self::OCCURRED_AT_FORMAT);
        }
    }

    // An override, not #[EventVersion]: ADR 0002 permits only two Spatie types in Domain.
    #[Override]
    public function eventVersion(): int
    {
        return 1;
    }
}
