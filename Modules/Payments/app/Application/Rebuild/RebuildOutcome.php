<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Rebuild;

/**
 * What one rebuild attempt did, and the single answer it exits with.
 *
 * THE EXIT CODE IS DERIVED HERE, matching RepairOutcome's own rule: the mapping
 * is a contract rather than a console formatting choice.
 *
 *     0  full replay or guarded recovery completed and set `ready`
 *     1  an expected refusal, with no control-row column written
 *     2  a failure, including an unknown commit outcome or an audit failure
 *
 * `highWatermark`, `eventsReplayed` and `batchesCommitted` ARE PROGRESS, NOT A
 * SUCCESS CLAIM. They are populated as far as the run got even when it failed,
 * because an operator deciding whether to re-run needs to know how much of the
 * watermark was reached - and null, on a refusal, is what makes clear that
 * nothing was attempted at all.
 *
 * `guardedRecovery` records selection of D7's recovery path, not its success.
 * It stays true if that path fails or its completion audit fails, and is false
 * before a path is selected. On success it distinguishes recovery without
 * replay from a full rebuild over empty canonical history.
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
        public bool $guardedRecovery,
    ) {}

    public static function completed(int $highWatermark, int $eventsReplayed, int $batchesCommitted): self
    {
        return new self(RebuildReason::RebuildComplete, $highWatermark, $eventsReplayed, $batchesCommitted, false);
    }

    /**
     * A takeover found the durable row already `rebuilding` with `cleared_at`
     * still null - proof, per D7, that no destructive work has committed since
     * the last completed rebuild - and set `ready` directly, replaying
     * nothing. There is no watermark to report: no canonical read ever ran.
     */
    public static function guardedRecovery(): self
    {
        return new self(RebuildReason::RebuildComplete, null, 0, 0, true);
    }

    /** A refusal: no control-row column was written. */
    public static function refused(RebuildReason $reason): self
    {
        return new self($reason, null, null, null, false);
    }

    /**
     * A failed attempt, including an audit failure after a committed transition.
     *
     * The three progress fields carry how far the run got, when that is known -
     * never a claim that the run is safe to treat as complete.
     */
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
