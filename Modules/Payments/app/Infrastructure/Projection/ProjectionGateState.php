<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

/**
 * What a gate check found, and which half of the gate found it.
 *
 * FOUR CASES RATHER THAN A BOOLEAN, because the three closed reasons are three
 * different operational facts and an operator triaging a `503` needs to know
 * which one happened. They are not degrees of closed: every one of them closes
 * the gate completely.
 *
 * The two closed-by reasons that are not "missing" correspond exactly to the two
 * halves the specification requires to be checked together, and each catches a
 * failure the other cannot see. A rebuild holding its lock is caught by
 * ClosedByRebuildLock; a rebuild whose backend went away, releasing that lock
 * while the read model is still half-cleared, is caught by ClosedByControlState.
 */
enum ProjectionGateState: string
{
    /** The lock was free and the control row says `ready`. Work may proceed. */
    case Open = 'open';

    /**
     * A rebuild currently holds the exclusive advisory lock.
     *
     * The shared acquisition was refused immediately, with no wait: traffic never
     * blocks on this gate, so a rebuild can never turn a request, a relay pass or
     * a delivery into a timeout.
     */
    case ClosedByRebuildLock = 'closed_by_rebuild_lock';

    /**
     * The lock was free, but the durable row does not say `ready`.
     *
     * THIS IS THE CASE THE LOCK CANNOT SEE. PostgreSQL releases a session-level
     * advisory lock when its backend goes away, so a rebuild that lost its
     * session leaves the lock free and the read model half-cleared. Without this
     * check the gate would silently reopen over partial data.
     */
    case ClosedByControlState = 'closed_by_control_state';

    /**
     * No control row exists for this projection.
     *
     * CLOSED, NEVER `ready`. A projection whose control state cannot be read is
     * not a projection anyone should be reading. The forward migration seeds the
     * row, so its absence means damage rather than a fresh install.
     */
    case ClosedByMissingControlRow = 'closed_by_missing_control_row';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
