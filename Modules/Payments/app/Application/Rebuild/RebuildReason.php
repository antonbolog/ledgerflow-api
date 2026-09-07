<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Rebuild;

/**
 * Why a rebuild ended the way it did, as a stable category.
 *
 * A CLOSED VOCABULARY, matching RepairReason's own rule: these strings reach
 * stdout and the operator log, so every one is a literal written in this
 * module - no exception text, no payload value, no SQL vocabulary.
 *
 * REFUSALS AND FAILURES ARE DIFFERENT ANSWERS, exactly as they are for repair.
 * A refusal is the command working correctly and declining - the confirmation
 * flag was missing, or a live rebuild already holds the gate - and it never
 * writes a control-row column. A failure is the command not knowing whether
 * its last step landed.
 */
enum RebuildReason: string
{
    /** The rebuild ran to completion: cleared, replayed, and `ready`. */
    case RebuildComplete = 'rebuild_complete';

    /*
    | REFUSALS. Nothing was written, and the operator has something to act on.
    */

    /** `--confirm-rebuild` was not supplied. Refused before any database work. */
    case ConfirmationMissing = 'confirmation_missing';

    /**
     * The exclusive gate lock could not be acquired within the bounded wait.
     *
     * A LIVE REBUILD, NOT A DEAD ONE. PostgreSQL's lock manager only makes this
     * run wait while some session still holds - or is queued ahead for - the
     * gate; a session that has gone away releases its lock immediately. No
     * control-row column is written and no projection data is cleared.
     */
    case LockTimeout = 'lock_timeout';

    /*
    | FAILURES. The command does not know, and may have changed something.
    */

    /**
     * The ownership fence refused a write at one of its three checkpoints.
     *
     * Either this run's token no longer matches the control row (a takeover
     * happened) or this backend no longer holds the exclusive lock (F3: a
     * silent reconnect). The run aborts immediately, writing nothing further
     * and never setting `ready`.
     */
    case OwnershipLost = 'ownership_lost';

    /**
     * A canonical Payments event could not be decoded during replay.
     *
     * Structurally unreachable from a healthy T6 write path - `PaymentInitiated`
     * validates strictly before it is ever stored - so this means corruption in
     * `stored_events` itself. The rebuild aborts rather than skip the row: a
     * partial replay must never be reported as `ready`.
     */
    case CanonicalEventUndecodable = 'canonical_event_undecodable';

    case StatementTimeout = 'statement_timeout';

    case DatabaseError = 'database_error';

    case InvalidInvocation = 'invalid_invocation';

    /**
     * COMMIT neither confirmed nor refused for the step in progress.
     *
     * Never retried automatically: the next authorized run reads the durable
     * control row rather than trusting this run's claim about its own last
     * commit - exactly as repair and every other Payments write path already
     * do at this boundary.
     */
    case CommitOutcomeUnknown = 'commit_outcome_unknown';

    /**
     * Operator intent could not be recorded, so the mutation never began.
     *
     * MATCHES RepairReason::IntentAuditFailed EXACTLY. The exclusive lock is
     * already held when this fires - the intent log is the first thing written
     * after acquiring it - but claimOwnership() never runs: a rebuild whose
     * intent was never recorded would be a change to durable delivery state
     * with no evidence anyone asked for it. The lock is still released and the
     * reconnector still restored on this path, like every other exit.
     */
    case IntentAuditFailed = 'intent_audit_failed';

    /**
     * The rebuild finished - `ready` was set, in a committed transaction - and
     * its own completion record did not.
     *
     * MATCHES RepairReason::AuditFailed. Reported as a failure, deliberately,
     * even though the work landed: a broken audit sink means this run cannot
     * vouch for the record of what it did, and `high_watermark`,
     * `events_replayed` and `batches_committed` on the outcome carry the real,
     * final progress - proof the run completed even though the reason says
     * `failed`. Never conflated with a database or commit failure, which would
     * wrongly suggest the projection is still in `rebuilding`.
     */
    case AuditFailed = 'audit_failed';

    public function isRefusal(): bool
    {
        return match ($this) {
            self::ConfirmationMissing, self::LockTimeout => true,
            default => false,
        };
    }
}
