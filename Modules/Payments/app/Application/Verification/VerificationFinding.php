<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Verification;

/**
 * One named check that failed, as a stable category.
 *
 * A CATEGORY VOCABULARY, NOT A MESSAGE. These strings reach stdout and every
 * example, so each one is a literal written in this module: never a payload
 * value, never a driver's wording, never an exception text.
 *
 * A FINDING IS NOT AUTOMATICALLY AN INCONSISTENCY, which is why
 * `isContradiction()` exists rather than being assumed. `EventTimeInFuture` is
 * an operational anomaly on an otherwise non-contradictory delivery: it must be
 * visible to an operator, and it must not be counted as corruption or hidden
 * inside an ordinary in-flight classification. Every other case here IS a
 * contradiction, and one is enough to classify its event `inconsistent`.
 *
 * TWO DIRECTIONS, ONE VOCABULARY. The canonical checks walk history forward and
 * ask what is missing or wrong downstream. The reverse checks walk each derived
 * table and ask whether anything upstream still accounts for the record.
 *
 * EACH PASS RESOLVES ON THE KEY WHOSE CORRUPTION IT OWNS, so one damaged record
 * usually yields one finding. Where two passes genuinely describe DIFFERENT facts
 * about the same row - the envelope for this event has a wrong column, and that
 * envelope now matches no canonical event - both are reported, because an operator
 * needs both. A finding count is therefore a count of facts, not of damaged rows.
 */
enum VerificationFinding: string
{
    /** The canonical row carries a Payments alias this audit does not decode. */
    case CanonicalAliasUnsupported = 'canonical_alias_unsupported';

    /** `stored_events.event_version` is not the version this audit decodes. */
    case CanonicalSchemaVersionUnsupported = 'canonical_schema_version_unsupported';

    /** The canonical row is not a payment's first event. */
    case CanonicalAggregateVersionUnsupported = 'canonical_aggregate_version_unsupported';

    /** `event_properties` is not a payload this audit can read. */
    case CanonicalPayloadMalformed = 'canonical_payload_malformed';

    /** The canonical payload disagrees with the canonical row's own columns. */
    case CanonicalIdentityContradictory = 'canonical_identity_contradictory';

    /** No `payment_outbox` row exists for a visible canonical event. */
    case OutboxEnvelopeMissing = 'outbox_envelope_missing';

    /** The envelope exists and does not match canonical history. */
    case OutboxFieldMismatch = 'outbox_field_mismatch';

    /** A projector receipt exists and its `payment_details` row does not. */
    case ProjectorReceiptWithoutReadRow = 'projector_receipt_without_read_row';

    /** A `payment_details` row exists and its projector receipt does not. */
    case ReadRowWithoutProjectorReceipt = 'read_row_without_projector_receipt';

    /** The read row exists and a business column disagrees with canonical history. */
    case ReadRowFieldMismatch = 'read_row_field_mismatch';

    /** A read row for this payment was projected from a different source event. */
    case ReadRowWrongSourceEvent = 'read_row_wrong_source_event';

    /** The named receipt exists and `projection_completed_at` is null. */
    case CompletionMarkerMissing = 'completion_marker_missing';

    /** `projection_completed_at` is set and the named receipt does not exist. */
    case CompletionMarkerWithoutReceipt = 'completion_marker_without_receipt';

    /** Canonical event time is after the audit timestamp. Operational, not corruption. */
    case EventTimeInFuture = 'event_time_in_future';

    /**
     * Two canonical Payments events carry the same durable event id.
     *
     * OWNED BY THE CANONICAL PASS, but found by one grouped query rather than by
     * the forward walk: two rows sharing an id can sit in different batches, and
     * a walk that compared only within a batch would miss them. Detecting it
     * without holding every id in memory is exactly why it is a database
     * aggregate and not a PHP set.
     */
    case CanonicalDuplicateEventId = 'canonical_duplicate_event_id';

    /**
     * A projector receipt whose outbox envelope does not exist.
     *
     * OWNED BY THE RECEIPT PASS. The consumer writes a receipt only while holding
     * that envelope's row lock, so a receipt without one is not an interrupted
     * delivery: something removed the envelope afterwards. Without it there is no
     * identity to resolve the receipt against, which is why it is reported as its
     * own case rather than folded into the orphan below.
     */
    case ProjectorReceiptWithoutEnvelope = 'projector_receipt_without_envelope';

    /** A projector receipt whose envelope resolves to no canonical event. */
    case ProjectorReceiptOrphaned = 'projector_receipt_orphaned';

    /** A `payment_details` row no canonical event accounts for. */
    case ReadRowOrphaned = 'read_row_orphaned';

    /** A `payment_outbox` envelope no canonical event accounts for. */
    case OutboxEnvelopeOrphaned = 'outbox_envelope_orphaned';

    public function isContradiction(): bool
    {
        return $this !== self::EventTimeInFuture;
    }
}
