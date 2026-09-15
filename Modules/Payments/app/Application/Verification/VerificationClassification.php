<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Verification;

enum VerificationClassification: string
{
    case Consistent = 'consistent';

    case InFlight = 'in_flight';

    case DeliveryOverdue = 'delivery_overdue';

    case Exhausted = 'exhausted';

    case Inconsistent = 'inconsistent';

    /**
     * Declaration order is the rendered classification_counts order.
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
