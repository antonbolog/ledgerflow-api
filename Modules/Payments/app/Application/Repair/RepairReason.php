<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Repair;

// Values reach stdout and audit logs: literals only, never exception text or supplied identifiers.
enum RepairReason: string
{
    case RepairComplete = 'repair_complete';

    case DeliveryNotQuiesced = 'delivery_not_quiesced';

    case RebuildInProgress = 'rebuild_in_progress';

    case CanonicalEventAbsent = 'canonical_event_absent';

    case CanonicalEventAmbiguous = 'canonical_event_ambiguous';

    case CanonicalAliasUnsupported = 'canonical_alias_unsupported';

    case CanonicalSchemaVersionUnsupported = 'canonical_schema_version_unsupported';

    case CanonicalAggregateVersionUnsupported = 'canonical_aggregate_version_unsupported';

    case CanonicalPayloadMalformed = 'canonical_payload_malformed';

    case CanonicalIdentityContradictory = 'canonical_identity_contradictory';

    case AggregateIdentityConflict = 'aggregate_identity_conflict';

    // A failure, not a refusal, although nothing is written: malformed input exits 2 by contract.
    case MalformedEventId = 'malformed_event_id';

    case IntentAuditFailed = 'intent_audit_failed';

    case AuditFailed = 'audit_failed';

    case StatementTimeout = 'statement_timeout';

    case DatabaseError = 'database_error';

    case InvalidInvocation = 'invalid_invocation';

    case CommitOutcomeUnknown = 'commit_outcome_unknown';

    public function isRefusal(): bool
    {
        return match ($this) {
            self::DeliveryNotQuiesced,
            self::RebuildInProgress,
            self::CanonicalEventAbsent,
            self::CanonicalEventAmbiguous,
            self::CanonicalAliasUnsupported,
            self::CanonicalSchemaVersionUnsupported,
            self::CanonicalAggregateVersionUnsupported,
            self::CanonicalPayloadMalformed,
            self::CanonicalIdentityContradictory,
            self::AggregateIdentityConflict => true,
            default => false,
        };
    }
}
