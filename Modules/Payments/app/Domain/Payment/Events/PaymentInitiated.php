<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Payment\Events;

use DateTimeImmutable;
use Modules\Payments\Domain\Payment\Exceptions\InvalidPaymentInitiation;
use Override;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The first and, in this slice, only Payments business event.
 *
 * Immutable by construction. Every attempt of T6's retry loop builds a new
 * instance from the same immutable command context, so identity is carried by
 * the values, never by the object.
 *
 * WHY occurredAt IS A STRING: Symfony's DateTimeNormalizer sits in Spatie's
 * default normalizer chain and formats with RFC3339, which has no sub-second
 * component. A DateTimeImmutable property would silently truncate business time
 * to whole seconds inside the stored payload.
 *
 * WHY eventVersion() IS OVERRIDDEN rather than carrying #[EventVersion(1)]:
 * the attribute is a third Spatie type and ADR 0002 permits exactly two.
 * Spatie's ObjectNormalizer filters `eventVersion` out of the payload unless the
 * child declares a PROPERTY of that name, so an overridden method changes the
 * stored key set not at all.
 *
 * The property names below are the durable payload keys. Changing one is a
 * schema change needing a new alias and a plan for existing stored events.
 */
final class PaymentInitiated extends ShouldBeStored
{
    /** The stable alias stored_events.event_class and payment_outbox.event_type carry. */
    public const string ALIAS = 'payments.payment_initiated';

    /** ISO-8601, microsecond precision, UTC. */
    public const string OCCURRED_AT_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * \A and \z, never ^ and $. In PCRE, $ also matches immediately BEFORE a
     * terminal newline, so "…aaaa\n" would satisfy a $-anchored pattern and a
     * trailing newline would reach stored_events.aggregate_uuid and the outbox.
     * \z is the absolute end of the subject and admits nothing after it.
     */
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

        // createFromFormat alone is too permissive: it accepts 2026-02-30 and
        // rolls it forward. Comparing the re-formatted value against the input
        // is what makes the check exact.
        $parsed = DateTimeImmutable::createFromFormat(self::OCCURRED_AT_FORMAT, $occurredAt);

        if ($parsed === false
            || $parsed->format(self::OCCURRED_AT_FORMAT) !== $occurredAt
            || $parsed->getOffset() !== 0) {
            throw InvalidPaymentInitiation::occurredAt($occurredAt, self::OCCURRED_AT_FORMAT);
        }
    }

    #[Override]
    public function eventVersion(): int
    {
        return 1;
    }
}
