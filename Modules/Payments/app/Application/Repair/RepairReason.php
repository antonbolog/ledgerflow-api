<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Repair;

/**
 * Why a repair ended the way it did, as a stable category.
 *
 * A CLOSED VOCABULARY, NEVER A DRIVER'S WORDING. These strings reach stdout and
 * the audit log, so every one is a literal written in this module: no exception
 * text, no payload value, no idempotency key, no supplied identifier.
 *
 * REFUSALS AND FAILURES ARE DIFFERENT ANSWERS, which is what isRefusal() exists to
 * keep apart. A refusal is the command working correctly and declining - the
 * history is not there, or it is ambiguous, or the operator has not acknowledged
 * quiescence - and it never mutated anything. A failure is the command not
 * knowing, and it may have. Collapsing the two would tell an operator "nothing
 * happened" at the exact moment that is least safe to assume.
 */
enum RepairReason: string
{
    /** The repair ran to a known end. */
    case RepairComplete = 'repair_complete';

    /*
    | REFUSALS. Nothing was written, and the operator has something to act on.
    */

    /** `--delivery-quiesced` was not supplied. Refused before any database work. */
    case DeliveryNotQuiesced = 'delivery_not_quiesced';

    /** The projection gate is closed; no canonical or envelope row was locked. */
    case RebuildInProgress = 'rebuild_in_progress';

    /** No canonical Payments event carries that durable event id. */
    case CanonicalEventAbsent = 'canonical_event_absent';

    /**
     * More than one canonical event carries it.
     *
     * A REFUSAL RATHER THAN A CHOICE. Picking the first match would reconstruct an
     * envelope from one of two histories with no way to tell which, and the
     * envelope would look repaired either way.
     */
    case CanonicalEventAmbiguous = 'canonical_event_ambiguous';

    case CanonicalAliasUnsupported = 'canonical_alias_unsupported';

    case CanonicalSchemaVersionUnsupported = 'canonical_schema_version_unsupported';

    case CanonicalAggregateVersionUnsupported = 'canonical_aggregate_version_unsupported';

    case CanonicalPayloadMalformed = 'canonical_payload_malformed';

    case CanonicalIdentityContradictory = 'canonical_identity_contradictory';

    /**
     * Another envelope already holds this aggregate identity.
     *
     * REFUSED, NEVER RESOLVED. `payment_outbox` is unique on
     * (aggregate_uuid, aggregate_version), so writing this row would mean removing
     * or moving that one - which is a second corruption, not a repair.
     */
    case AggregateIdentityConflict = 'aggregate_identity_conflict';

    /*
    | FAILURES. The command does not know, and may have changed something.
    */

    /**
     * The supplied identifier is not a canonical UUID, and is never echoed back.
     *
     * A FAILURE RATHER THAN A REFUSAL, DESPITE MUTATING NOTHING, and the exit code
     * is what the distinction is for. A refusal is a well-formed request the
     * command correctly declines; this is malformed input, which the contract puts
     * beside invalid invocation and invalid configuration at exit `2`. Listing it
     * above would only change isRefusal(), and with it the exit code the operator
     * scripts against.
     */
    case MalformedEventId = 'malformed_event_id';

    /** Operator intent could not be recorded, so the mutation never began. */
    case IntentAuditFailed = 'intent_audit_failed';

    /** The work finished and its record did not. The mutation state says which. */
    case AuditFailed = 'audit_failed';

    case StatementTimeout = 'statement_timeout';

    case DatabaseError = 'database_error';

    case InvalidInvocation = 'invalid_invocation';

    /** COMMIT neither confirmed nor refused. Never retried automatically. */
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
