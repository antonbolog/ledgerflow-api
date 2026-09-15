<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Throwable;

// Unknown SQLSTATEs charge nothing because they may be transient; non-database exceptions come from
// projector code, reproduce on every redelivery, and are charged.
final readonly class ProjectionFailureClassification
{
    public const string HANDLER_FAILED = 'projection_handler_failed';

    private const array OWNED_CONSTRAINTS = [
        'payment_details_pkey' => ProjectionEnvelopeRejected::CONTRADICTORY_IDENTITY,
        'payment_details_source_event_id_unique' => ProjectionEnvelopeRejected::CONTRADICTORY_IDENTITY,
        'payment_details_merchant_id_foreign' => self::HANDLER_FAILED,
        'payment_projector_receipts_pkey' => self::HANDLER_FAILED,
    ];

    /**
     * @return list<string>
     */
    private static function nonCountableStates(): array
    {
        return [...RetryingAtomicTransaction::RETRYABLE, '57014'];
    }

    public static function categoryFor(Throwable $failure): ?string
    {
        if ($failure instanceof ProjectionEnvelopeRejected) {
            return $failure->category;
        }

        if ($failure instanceof ProjectionEnvelopeMissing) {
            return null;
        }

        $sqlState = RetryingAtomicTransaction::sqlStateOf($failure);

        if ($sqlState === null) {
            return self::HANDLER_FAILED;
        }

        if (in_array($sqlState, self::nonCountableStates(), true)) {
            return null;
        }

        if (str_starts_with($sqlState, '08')) {
            return null;
        }

        return self::ownedConstraintCategory($failure);
    }

    // PDO exposes constraint names only in message text. Matching only owned names means a reworded
    // driver message can make this charge less, never charge the wrong category.
    private static function ownedConstraintCategory(Throwable $failure): ?string
    {
        $message = $failure->getMessage();

        foreach (self::OWNED_CONSTRAINTS as $constraint => $category) {
            if (str_contains($message, $constraint)) {
                return $category;
            }
        }

        return null;
    }
}
