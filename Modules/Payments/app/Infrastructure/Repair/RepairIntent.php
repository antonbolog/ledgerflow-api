<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Repair;

use Modules\Payments\Application\Repair\RepairMutationState;
use Modules\Payments\Infrastructure\Projection\PaymentInitiatedEnvelope;
use Modules\Payments\Infrastructure\Verification\VerificationEnvelope;

// Reuses VerificationEnvelope's comparison so audit and repair agree; an empty change set writes nothing.
final readonly class RepairIntent
{
    private const array CREATED_FIELDS = [
        'event_type', 'schema_version', 'aggregate_uuid', 'aggregate_version',
        'payload', 'occurred_at', 'available_at', 'projection_completed_at',
    ];

    /** @param list<string> $changedFields */
    private function __construct(
        public bool $createsRow,
        public array $changedFields,
        public ?string $marker,
        public bool $refreshesAvailability,
    ) {}

    public static function resolve(
        PaymentInitiatedEnvelope $canonical,
        string $canonicalPayload,
        ?VerificationEnvelope $existing,
        ?string $receiptCreatedAt,
    ): self {
        if ($existing === null) {
            return new self(
                createsRow: true,
                changedFields: self::CREATED_FIELDS,
                marker: $receiptCreatedAt,
                refreshesAvailability: false,
            );
        }

        $changed = $existing->mismatchedFields($canonical, $canonicalPayload);

        // With a receipt, keep an existing marker as the historical fact; without one, the marker must be null.
        $marker = $receiptCreatedAt === null
            ? null
            : ($existing->projectionCompletedAt ?? $receiptCreatedAt);

        if ($marker !== $existing->projectionCompletedAt) {
            $changed[] = 'projection_completed_at';
        }

        // An exhausted row keeps its availability: payments:outbox:retry is the only reactivation path.
        $refreshesAvailability = $changed !== []
            && $receiptCreatedAt === null
            && $existing->exhaustedAt === null;

        if ($refreshesAvailability) {
            $changed[] = 'available_at';
        }

        return new self(
            createsRow: false,
            changedFields: $changed,
            marker: $marker,
            refreshesAvailability: $refreshesAvailability,
        );
    }

    public function writes(): bool
    {
        return $this->createsRow || $this->changedFields !== [];
    }

    public function mutationState(): RepairMutationState
    {
        if ($this->createsRow) {
            return RepairMutationState::Created;
        }

        return $this->changedFields === []
            ? RepairMutationState::NoChanges
            : RepairMutationState::Repaired;
    }
}
