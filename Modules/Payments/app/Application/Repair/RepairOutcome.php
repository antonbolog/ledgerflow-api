<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Repair;

final readonly class RepairOutcome
{
    public const string OUTCOME_CREATED = 'created';

    public const string OUTCOME_REPAIRED = 'repaired';

    public const string OUTCOME_UNCHANGED = 'unchanged';

    public const string OUTCOME_REFUSED = 'refused';

    public const string OUTCOME_FAILED = 'failed';

    public const int EXIT_DONE = 0;

    public const int EXIT_REFUSED = 1;

    public const int EXIT_FAILED = 2;

    /** @param list<string> $changedFields */
    private function __construct(
        public RepairReason $reason,
        public RepairMutationState $mutationState,
        public ?string $eventId,
        public bool $deliveryQuiesced,
        public array $changedFields,
        public bool $historyReinitialized,
    ) {}

    /**
     * @param  list<string>  $changedFields
     */
    public static function completed(
        RepairMutationState $mutationState,
        string $eventId,
        array $changedFields,
        bool $historyReinitialized,
    ): self {
        return new self(
            reason: RepairReason::RepairComplete,
            mutationState: $mutationState,
            eventId: $eventId,
            deliveryQuiesced: true,
            changedFields: $changedFields,
            historyReinitialized: $historyReinitialized,
        );
    }

    public static function refused(RepairReason $reason, ?string $eventId, bool $deliveryQuiesced): self
    {
        return new self(
            reason: $reason,
            mutationState: RepairMutationState::None,
            eventId: $eventId,
            deliveryQuiesced: $deliveryQuiesced,
            changedFields: [],
            historyReinitialized: false,
        );
    }

    /**
     * @param  list<string>  $changedFields
     */
    public static function failed(
        RepairReason $reason,
        RepairMutationState $mutationState,
        ?string $eventId,
        array $changedFields = [],
        bool $historyReinitialized = false,
        bool $deliveryQuiesced = true,
    ): self {
        return new self(
            reason: $reason,
            mutationState: $mutationState,
            eventId: $eventId,
            deliveryQuiesced: $deliveryQuiesced,
            changedFields: $changedFields,
            historyReinitialized: $historyReinitialized,
        );
    }

    public function outcome(): string
    {
        if ($this->reason->isRefusal()) {
            return self::OUTCOME_REFUSED;
        }

        if ($this->reason !== RepairReason::RepairComplete) {
            return self::OUTCOME_FAILED;
        }

        return match ($this->mutationState) {
            RepairMutationState::Created => self::OUTCOME_CREATED,
            RepairMutationState::Repaired => self::OUTCOME_REPAIRED,
            default => self::OUTCOME_UNCHANGED,
        };
    }

    public function exitCode(): int
    {
        return match ($this->outcome()) {
            self::OUTCOME_REFUSED => self::EXIT_REFUSED,
            self::OUTCOME_FAILED => self::EXIT_FAILED,
            default => self::EXIT_DONE,
        };
    }
}
