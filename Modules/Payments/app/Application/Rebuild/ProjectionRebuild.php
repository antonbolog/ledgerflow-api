<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Rebuild;

/**
 * Clear and replay the `payment_details` read model from canonical history.
 *
 * A PORT FOR THE REASON OutboxRepair AND ProjectionVerifier ARE ONES: nothing
 * about connections, locks or isolation appears in this signature. The pinned
 * connection, the exclusive gate lock, the ownership fence and every SQL
 * statement are an adapter's problem.
 *
 * $confirmRebuild IS THE OPERATOR'S ASSERTION, TAKEN AS AN ARGUMENT rather than
 * read from a console flag directly, exactly as OutboxRepair takes delivery
 * quiescence. A destructive command's confirmation belongs in its request, not
 * only in the process that happens to be asking.
 *
 * $operationId CORRELATES INTENT WITH OUTCOME, minted by the caller before
 * anything is attempted, so a record of what was asked for exists even when the
 * record of what happened does not.
 *
 * IT NEVER THROWS FOR A REBUILD THAT REFUSED OR FAILED. A lock timeout, a lost
 * ownership fence, or an unknown commit are outcomes an operator has to be able
 * to READ, with whatever progress was made attached, rather than incidents that
 * arrive as an exception carrying a driver's wording.
 */
interface ProjectionRebuild
{
    public function rebuild(bool $confirmRebuild, string $operationId): RebuildOutcome;
}
