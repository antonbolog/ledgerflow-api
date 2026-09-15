<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Verification;

// Values reach stdout and examples: literals only, never payload values or driver wording.
enum VerificationFinding: string
{
    case CanonicalAliasUnsupported = 'canonical_alias_unsupported';

    case CanonicalSchemaVersionUnsupported = 'canonical_schema_version_unsupported';

    case CanonicalAggregateVersionUnsupported = 'canonical_aggregate_version_unsupported';

    case CanonicalPayloadMalformed = 'canonical_payload_malformed';

    case CanonicalIdentityContradictory = 'canonical_identity_contradictory';

    case OutboxEnvelopeMissing = 'outbox_envelope_missing';

    case OutboxFieldMismatch = 'outbox_field_mismatch';

    case ProjectorReceiptWithoutReadRow = 'projector_receipt_without_read_row';

    case ReadRowWithoutProjectorReceipt = 'read_row_without_projector_receipt';

    case ReadRowFieldMismatch = 'read_row_field_mismatch';

    case ReadRowWrongSourceEvent = 'read_row_wrong_source_event';

    case CompletionMarkerMissing = 'completion_marker_missing';

    case CompletionMarkerWithoutReceipt = 'completion_marker_without_receipt';

    case EventTimeInFuture = 'event_time_in_future';

    case CanonicalDuplicateEventId = 'canonical_duplicate_event_id';

    case ProjectorReceiptWithoutEnvelope = 'projector_receipt_without_envelope';

    case ProjectorReceiptOrphaned = 'projector_receipt_orphaned';

    case ReadRowOrphaned = 'read_row_orphaned';

    case OutboxEnvelopeOrphaned = 'outbox_envelope_orphaned';

    public function isContradiction(): bool
    {
        return $this !== self::EventTimeInFuture;
    }
}
