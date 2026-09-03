<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Repair;

/**
 * What one targeted repair did, and the single answer it exits with.
 *
 * THE EXIT CODE IS DERIVED HERE, NOT AT THE CONSOLE, for the reason the verifier's
 * report derives its own: the mapping is a contract rather than a formatting
 * choice, and a second caller of the port must not be able to reassemble it
 * differently.
 *
 *     0  the repair ran to a known end: created, repaired, or unchanged
 *     1  an expected refusal, with nothing written
 *     2  the command does not know, or knows the record of what it did is missing
 *
 * A SUCCESSFUL REPAIR CLAIMS ONE ENVELOPE AND NOTHING MORE. It is not a claim that
 * the payment's derived state is consistent - a projector receipt without its read
 * row survives this command untouched - and it is not a claim that delivery
 * happened. Verification is what answers those.
 *
 * `changedFields` CARRIES NAMES, NEVER VALUES. It is printed, and the previous and
 * replacement values of a payment column are exactly what must not be.
 */
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
     * A repair that reached a known end.
     *
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

    /**
     * A refusal: nothing was written, and the operator has something to act on.
     *
     * $eventId IS NULLABLE HERE AND ONLY HERE. A malformed identifier must not be
     * echoed into the result or the log, so the one refusal that cannot name its
     * subject reports null rather than repeating whatever was typed.
     */
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
     * A repair that did not reach a known end.
     *
     * THE MUTATION STATE IS THE POINT OF THIS CONSTRUCTOR. `rolled_back` and
     * `unknown` are different facts about the database, and `committed` beside a
     * failure reason says the row changed while its record did not.
     *
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
