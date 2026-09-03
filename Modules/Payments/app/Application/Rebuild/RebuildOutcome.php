<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Rebuild;

/**
 * What one rebuild attempt did, and the single answer it exits with.
 *
 * THE EXIT CODE IS DERIVED HERE, matching RepairOutcome's own rule: the mapping
 * is a contract rather than a console formatting choice.
 *
 *     0  the rebuild ran to completion: cleared, replayed, and `ready`
 *     1  an expected refusal, with no control-row column written
 *     2  the command does not know whether its last step landed
 *
 * `highWatermark`, `eventsReplayed` and `batchesCommitted` ARE PROGRESS, NOT A
 * SUCCESS CLAIM. They are populated as far as the run got even when it failed,
 * because an operator deciding whether to re-run needs to know how much of the
 * watermark was reached - and null, on a refusal, is what makes clear that
 * nothing was attempted at all.
 */
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
    ) {}

    public static function completed(int $highWatermark, int $eventsReplayed, int $batchesCommitted): self
    {
        return new self(RebuildReason::RebuildComplete, $highWatermark, $eventsReplayed, $batchesCommitted);
    }

    /** A refusal: no control-row column was written. */
    public static function refused(RebuildReason $reason): self
    {
        return new self($reason, null, null, null);
    }

    /**
     * A rebuild that did not reach a known end.
     *
     * The three progress fields carry how far the run got, when that is known -
     * never a claim that the run is safe to treat as complete.
     */
    public static function failed(
        RebuildReason $reason,
        ?int $highWatermark = null,
        ?int $eventsReplayed = null,
        ?int $batchesCommitted = null,
    ): self {
        return new self($reason, $highWatermark, $eventsReplayed, $batchesCommitted);
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
