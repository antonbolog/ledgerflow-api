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

    /**
     * T11's shared projection gate was closed. No envelope was locked, nothing was
     * written, and `projection_failure_count`, `projection_failure_generation`,
     * `exhausted_at` and every transport column are untouched by construction.
     *
     * NOTHING WAS THROWN, DELIBERATELY. ProjectPaymentEvent has tries = 1 and no
     * try/catch, so an exception here would land the message in `failed_jobs`
     * rather than being redelivered - see PaymentProjectionConsumer's class
     * docblock.
     *
     * THE MESSAGE IS ACKNOWLEDGED AND DISCARDED, NOT RE-QUEUED. The durable outbox
     * row still has a null projection_completed_at, and once its available_at
     * grace expires the relay's ordinary reconciliation republishes it - the same
     * mechanism T7 already owns for any other missed delivery, so this deferral
     * adds no second redelivery owner.
     */
    case GateDeferred = 'gate_deferred';
}
