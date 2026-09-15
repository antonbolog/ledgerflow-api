<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Rebuild;

// Values reach stdout and operator logs: literals only, never exception text or payload values.
enum RebuildReason: string
{
    case RebuildComplete = 'rebuild_complete';

    case ConfirmationMissing = 'confirmation_missing';

    case LockTimeout = 'lock_timeout';

    case OwnershipLost = 'ownership_lost';

    case CanonicalEventUndecodable = 'canonical_event_undecodable';

    case StatementTimeout = 'statement_timeout';

    case DatabaseError = 'database_error';

    case InvalidInvocation = 'invalid_invocation';

    case CommitOutcomeUnknown = 'commit_outcome_unknown';

    case IntentAuditFailed = 'intent_audit_failed';

    case AuditFailed = 'audit_failed';

    public function isRefusal(): bool
    {
        return match ($this) {
            self::ConfirmationMissing, self::LockTimeout => true,
            default => false,
        };
    }
}
