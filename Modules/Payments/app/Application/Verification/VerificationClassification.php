<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Verification;

/**
 * What one canonical Payments event's derived state is, in one word.
 *
 * FIVE CASES, PINNED BY THE SPECIFICATION rather than invented here. They answer
 * one question — "is this payment's derived state agreed, merely late, stopped,
 * or contradicted?" — and the whole point of keeping them separate is that
 * elapsed time is not corruption evidence. An audit that collapsed
 * `delivery_overdue` into `inconsistent` would page an operator for a backlog.
 *
 * ORDER IS THE DECLARATION ORDER, and it is what makes classification_counts
 * render identically on every run: the report seeds every case at zero rather
 * than sorting whatever happened to occur.
 */
enum VerificationClassification: string
{
    /** Canonical history and every required derived record agree. */
    case Consistent = 'consistent';

    /**
     * Nothing derived yet, nothing contradicted, and still inside grace.
     *
     * A VALID ENVELOPE MUST EXIST FOR THIS. T6 commits the stored event and its
     * initial envelope in one transaction, so "no envelope" is never explained
     * by queue lag and never lands here.
     */
    case InFlight = 'in_flight';

    /** The same non-contradictory delivery, still incomplete at or beyond grace. */
    case DeliveryOverdue = 'delivery_overdue';

    /** Stopped by the existing poison protection, not by ordinary healthy lag. */
    case Exhausted = 'exhausted';

    /** A concrete contradiction: a mismatch, a missing envelope, or orphaned state. */
    case Inconsistent = 'inconsistent';

    /**
     * Every case, in declaration order, as the zero-seeded count map.
     *
     * @return array<string, int>
     */
    public static function zeroed(): array
    {
        $counts = [];

        foreach (self::cases() as $case) {
            $counts[$case->value] = 0;
        }

        return $counts;
    }
}
