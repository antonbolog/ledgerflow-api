<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Payment\Exceptions;

use InvalidArgumentException;

/**
 * A PaymentInitiated could not be constructed from the values supplied.
 *
 * Deliberately extends an SPL class: a single-segment global name is the one
 * thing the Domain rule admits without an allowlist entry, and there is no
 * framework exception this could usefully be.
 *
 * This is the last line of defence, not the user-facing one. T6's request
 * validation returns 422 before any of these can fire; if one does fire through
 * the HTTP path, the handler skipped canonicalisation.
 */
final class InvalidPaymentInitiation extends InvalidArgumentException
{
    public static function identifier(string $field, string $value): self
    {
        return new self("{$field} must be a canonical lowercase UUIDv7, got '{$value}'.");
    }

    public static function merchant(int $merchantId): self
    {
        return new self("merchantId must be a positive integer, got {$merchantId}.");
    }

    public static function amount(int $amountMinor): self
    {
        return new self("amountMinor must be between 1 and 100000000 inclusive, got {$amountMinor}.");
    }

    public static function currency(string $currency): self
    {
        return new self("currency must be the canonical uppercase 'USD', got '{$currency}'.");
    }

    public static function occurredAt(string $occurredAt, string $format): self
    {
        return new self("occurredAt must match '{$format}' at UTC, got '{$occurredAt}'.");
    }

    public static function paymentIdDoesNotMatchAggregate(string $paymentId, string $uuid): self
    {
        return new self("paymentId '{$paymentId}' does not match aggregate uuid '{$uuid}'.");
    }
}
