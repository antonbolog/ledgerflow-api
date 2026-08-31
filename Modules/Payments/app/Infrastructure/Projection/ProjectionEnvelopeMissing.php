<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

use RuntimeException;

/**
 * The delivered event id addresses no `payment_outbox` row.
 *
 * TYPED, AND SEPARATE FROM ProjectionEnvelopeRejected, because the two land in
 * DIFFERENT FAILURE BUDGETS and a shared type would make that distinction a string
 * comparison. There is no row to charge: writing failure metadata would mean
 * inserting an outbox row to hold it, which is orphan state describing a delivery
 * for an envelope that does not exist.
 *
 * IT MUST NOT BE SWALLOWED. Acknowledging this quietly would drain the queue while
 * hiding the only evidence that the transport and PostgreSQL disagree — the state
 * an outbox is supposed to make impossible. It escapes, the job fails visibly, and
 * the retained failed-job record is where an operator finds it.
 *
 * The event id is the only identifier it carries, and that is safe by construction:
 * a uuid the relay generated, never caller data.
 */
final class ProjectionEnvelopeMissing extends RuntimeException
{
    /** The fixed, safe log category. Never a message, never caller data. */
    public const string CATEGORY = 'projection_envelope_missing';

    public function __construct(public readonly string $eventId)
    {
        parent::__construct(
            "No payment_outbox row exists for delivered event {$eventId}. The transport carried an "
            .'identifier PostgreSQL does not know, so there is nothing to project and nothing to '
            .'charge. Failing visibly rather than acknowledging, so the disagreement is not hidden.'
        );
    }
}
