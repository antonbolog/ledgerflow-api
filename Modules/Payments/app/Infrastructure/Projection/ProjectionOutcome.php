<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

/**
 * What one projection delivery did to one envelope.
 *
 * FOUR CASES, AND ONLY FOUR — every one of them a COMMITTED ending. Every other
 * ending escapes as an exception and rolls the transaction back, exactly as
 * RelayOutcome does for the relay. Representing a failure as an outcome would
 * invite a caller to acknowledge the message and move on, which is what the
 * failure classification exists to prevent.
 *
 * BACKED BY STABLE STRINGS because these reach the structured log an operator
 * reads, so renaming a case must not silently rename a log value.
 */
enum ProjectionOutcome: string
{
    /** First delivery: receipt, read row and completion marker committed together. */
    case Projected = 'projected';

    /** Duplicate delivery over a complete projection. Nothing was written. */
    case AlreadyProjected = 'already_projected';

    /**
     * Duplicate delivery that found receipt and read row present and the outbox
     * completion marker missing, and filled only the marker.
     *
     * The state a crash between COMMIT and the relay's next read leaves behind, and
     * the only repair a duplicate is allowed to perform.
     */
    case MarkerRepaired = 'marker_repaired';

    /**
     * The locked row was already exhausted, so the delivery did nothing at all.
     *
     * Observability only: no projection, no counter, no marker. Operator retry is
     * the sole path back.
     */
    case SkippedExhausted = 'skipped_exhausted';
}
