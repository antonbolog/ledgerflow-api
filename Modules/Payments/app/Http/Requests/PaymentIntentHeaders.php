<?php

declare(strict_types=1);

namespace Modules\Payments\Http\Requests;

use DateTimeImmutable;
use DateTimeZone;

// Strict on purpose: the BFF writes both values itself, so any other spelling is a broken or forged client.
final class PaymentIntentHeaders
{
    public const string INTENT_ID = 'Ledgerflow-Intent-Id';

    public const string EXPIRES_AT = 'Ledgerflow-Intent-Expires-At';

    private const string CANONICAL_UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    // Date.prototype.toISOString() for years 0001-9999: always milliseconds, always Z. PostgreSQL has no year zero.
    private const string ISO_STRING = '/\A(?!0000)\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/';

    private const string ISO_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    /** @param  list<string|null>  $values  every value received for the header */
    public static function intentId(array $values): ?string
    {
        $value = self::single($values);

        return $value !== null && preg_match(self::CANONICAL_UUID, $value) === 1 ? $value : null;
    }

    /** @param  list<string|null>  $values  every value received for the header */
    public static function expiresAt(array $values): ?DateTimeImmutable
    {
        $value = self::single($values);

        if ($value === null || preg_match(self::ISO_STRING, $value) !== 1) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!'.self::ISO_FORMAT, $value, new DateTimeZone('UTC'));

        // The round trip refuses dates PHP would otherwise roll over, such as February 30.
        return $parsed !== false && $parsed->format(self::ISO_FORMAT) === $value ? $parsed : null;
    }

    /** @param  list<string|null>  $values */
    private static function single(array $values): ?string
    {
        return count($values) === 1 ? $values[0] : null;
    }
}
