<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Relay;

/**
 * What one relay attempt did to one row.
 *
 * THREE CASES, AND ONLY THREE. Every other ending — a PostgreSQL failure, a
 * programming error, an ambiguous commit — escapes as an exception and aborts the
 * pass. Representing those as outcomes would invite the command to treat them as
 * ordinary results and continue, which is precisely the behaviour the failure
 * classification forbids: Laravel can retain stale transaction bookkeeping after a
 * connection loss, so the next row must not be attempted on that connection.
 *
 * BACKED BY STABLE STRINGS because these values reach the structured pass log and
 * the operator reads them. They are a contract, not an implementation detail, and
 * renaming a case must not silently rename a log field.
 */
enum RelayOutcome: string
{
    /** No row satisfied the eligibility predicate; the backlog is drained. */
    case NothingEligible = 'nothing_eligible';

    /** Redis accepted the message and the transport state committed. */
    case Published = 'published';

    /** Redis refused; the failure count and backoff committed. */
    case TransportRejected = 'transport_rejected';

    /**
     * T11's shared projection gate was closed. No row was selected, nothing was
     * written, and the transport budget is untouched by construction.
     *
     * A SEPARATE CASE FROM NothingEligible, DELIBERATELY. An operator reading
     * `payments:outbox:status` or a relay pass log must never mistake a closed
     * gate for a drained backlog - they call for opposite responses, and
     * collapsing them into one value would hide which one is true.
     */
    case GateClosed = 'gate_closed';
}
