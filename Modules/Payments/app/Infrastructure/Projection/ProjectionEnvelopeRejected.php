<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

use RuntimeException;

/**
 * A deterministic refusal to project the envelope this delivery carried.
 *
 * DETERMINISTIC IS THE WHOLE CLASSIFICATION. Every factory below describes a
 * condition that the SAME bytes will reproduce on every redelivery: the alias is
 * not the one this consumer decodes, the schema version is not the one it
 * understands, the payload disagrees with itself or with its columns, or a receipt
 * exists whose read row does not. None of them can be fixed by waiting, so a
 * redelivery budget is the right thing for them to consume — as opposed to a lock
 * timeout or a lost connection, which say nothing about the envelope and are not
 * this type.
 *
 * The CATEGORY, not the message, is the durable artefact. `last_projection_error`
 * takes one of the constants below and nothing else, so an operator reading a
 * poisoned row sees a fixed vocabulary rather than whatever a driver or a decoder
 * happened to write. The message stays for the log and for the failed-job record.
 *
 * NO CALLER DATA IN ANY MESSAGE. Every `$reason` below is a literal written in this
 * module — a column name, a key name, a type name — and the only interpolated
 * values are the event id and integers the schema already constrains. A payload
 * value, an idempotency key or a raw exception text must never reach here.
 *
 * WRITING THE METADATA IS NOT THIS CLASS'S JOB. It only says what happened and how
 * it should be counted; the separate short transaction that records it belongs to
 * the failure recorder.
 */
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
        // The alias is module-owned durable schema, not caller data, so naming it
        // is safe and is what makes the refusal diagnosable.
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

    /**
     * A projector receipt exists, and the read row it claims to have written does not.
     *
     * CORRUPTION, NOT AN EMPTY SLOT TO FILL. The receipt and the read row are
     * written by one transaction, so this state cannot arise from an interrupted
     * delivery — only from something that deleted or never wrote one of them.
     * Recreating the row here would make a duplicate delivery quietly repair
     * damage nobody had detected, and would destroy the evidence that verification
     * and rebuild exist to act on.
     */
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

    /**
     * A projector receipt's read row exists and describes a DIFFERENT payment.
     *
     * The same corruption as readRowMissing, reachable by the same routes, and
     * strictly worse: a delivery that accepted it would report the projection
     * complete and stamp the outbox marker over a row for another payment,
     * permanently retiring an envelope whose work was never done.
     */
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
