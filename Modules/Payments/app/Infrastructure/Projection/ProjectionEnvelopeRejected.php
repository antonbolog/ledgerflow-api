<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

use RuntimeException;

// Messages reach logs and failed-job records: interpolate only event ids, schema-bound integers
// and module-owned literals, never payload values or exception text.
final class ProjectionEnvelopeRejected extends RuntimeException
{
    public const string UNSUPPORTED_ALIAS = 'projection_alias_unsupported';

    public const string UNSUPPORTED_SCHEMA_VERSION = 'projection_schema_version_unsupported';

    public const string UNSUPPORTED_AGGREGATE_VERSION = 'projection_aggregate_version_unsupported';

    public const string MALFORMED_PAYLOAD = 'projection_payload_malformed';

    public const string MALFORMED_ENVELOPE = 'projection_envelope_malformed';

    public const string CONTRADICTORY_IDENTITY = 'projection_identity_contradictory';

    public const string READ_ROW_MISSING = 'projection_read_row_missing';

    public const string READ_ROW_MISMATCH = 'projection_read_row_mismatch';

    private function __construct(public readonly string $category, string $message)
    {
        parent::__construct($message);
    }

    public static function unsupportedAlias(string $eventId, string $alias): self
    {
        return new self(
            self::UNSUPPORTED_ALIAS,
            "Outbox event {$eventId} carries alias {$alias}, which this consumer does not decode. "
            .'A decoder for another event type is a separate envelope with its own invariants.',
        );
    }

    public static function unsupportedSchemaVersion(string $eventId, int $version): self
    {
        return new self(
            self::UNSUPPORTED_SCHEMA_VERSION,
            "Outbox event {$eventId} declares schema version {$version}; this consumer decodes only "
            .PaymentInitiatedEnvelope::SCHEMA_VERSION.'. Refusing rather than guessing at fields it '
            .'has not agreed to.',
        );
    }

    public static function unsupportedAggregateVersion(string $eventId, int $version): self
    {
        return new self(
            self::UNSUPPORTED_AGGREGATE_VERSION,
            "Outbox event {$eventId} is at aggregate version {$version}, but a payment's first event "
            .'is always version '.PaymentInitiatedEnvelope::FIRST_AGGREGATE_VERSION.'. A later '
            .'lifecycle event needs its own envelope, not this one.',
        );
    }

    public static function malformedPayload(string $eventId, string $reason): self
    {
        return new self(
            self::MALFORMED_PAYLOAD,
            "The canonical payload of outbox event {$eventId} is malformed: {$reason}.",
        );
    }

    public static function malformedEnvelope(string $column): self
    {
        return new self(
            self::MALFORMED_ENVELOPE,
            "payment_outbox returned a row whose {$column} is not of its declared type. "
            .'The schema and the query disagree; refusing to project.',
        );
    }

    public static function contradictoryIdentity(string $eventId, string $field): self
    {
        return new self(
            self::CONTRADICTORY_IDENTITY,
            "The payload of outbox event {$eventId} disagrees with its own columns on {$field}. "
            .'The envelope describes two different payments and neither may be chosen by guessing.',
        );
    }

    public static function readRowMissing(string $eventId, string $paymentId): self
    {
        return new self(
            self::READ_ROW_MISSING,
            "A projector receipt for outbox event {$eventId} exists, but no payment_details row for "
            ."payment {$paymentId} does. Receipt and read row are written by one transaction, so this "
            .'is corruption for verification and rebuild to act on, not state for a duplicate '
            .'delivery to recreate silently.',
        );
    }

    public static function readRowMismatch(string $eventId, string $paymentId): self
    {
        return new self(
            self::READ_ROW_MISMATCH,
            "The payment_details row recorded against outbox event {$eventId} does not describe payment "
            ."{$paymentId}. The receipt and the read row disagree about which payment this event "
            .'projected, so neither may be trusted and the marker must not be written.',
        );
    }
}
