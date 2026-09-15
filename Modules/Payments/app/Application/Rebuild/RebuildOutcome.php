<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Rebuild;

final readonly class RebuildOutcome
{
    public const string OUTCOME_COMPLETED = 'completed';

    public const string OUTCOME_REFUSED = 'refused';

    public const string OUTCOME_FAILED = 'failed';

    public const int EXIT_DONE = 0;

    public const int EXIT_REFUSED = 1;

    public const int EXIT_FAILED = 2;

    private function __construct(
        public RebuildReason $reason,
        public ?int $highWatermark,
        public ?int $eventsReplayed,
        public ?int $batchesCommitted,
        public bool $guardedRecovery,
    ) {}

    public static function completed(int $highWatermark, int $eventsReplayed, int $batchesCommitted): self
    {
        return new self(RebuildReason::RebuildComplete, $highWatermark, $eventsReplayed, $batchesCommitted, false);
    }

    public static function guardedRecovery(): self
    {
        return new self(RebuildReason::RebuildComplete, null, 0, 0, true);
    }

    public static function refused(RebuildReason $reason): self
    {
        return new self($reason, null, null, null, false);
    }

    public static function failed(
        RebuildReason $reason,
        ?int $highWatermark = null,
        ?int $eventsReplayed = null,
        ?int $batchesCommitted = null,
        bool $guardedRecovery = false,
    ): self {
        return new self($reason, $highWatermark, $eventsReplayed, $batchesCommitted, $guardedRecovery);
    }

    public function outcome(): string
    {
        if ($this->reason->isRefusal()) {
            return self::OUTCOME_REFUSED;
        }

        return $this->reason === RebuildReason::RebuildComplete
            ? self::OUTCOME_COMPLETED
            : self::OUTCOME_FAILED;
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
