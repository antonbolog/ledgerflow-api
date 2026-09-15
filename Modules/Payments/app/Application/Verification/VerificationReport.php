<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Verification;

final readonly class VerificationReport
{
    public const string PASS_CANONICAL_EVENTS = 'canonical_events';

    public const string PASS_PROJECTOR_RECEIPTS = 'projector_receipts';

    public const string PASS_READ_ROWS = 'payment_details';

    public const string PASS_OUTBOX_ENVELOPES = 'payment_outbox';

    public const array REQUIRED_PASSES = [
        self::PASS_CANONICAL_EVENTS,
        self::PASS_PROJECTOR_RECEIPTS,
        self::PASS_READ_ROWS,
        self::PASS_OUTBOX_ENVELOPES,
    ];

    public const int EXIT_CONSISTENT = 0;

    public const int EXIT_INCONSISTENT = 1;

    public const int EXIT_INCOMPLETE = 2;

    public const int EXIT_ATTENTION = 3;

    public const string OUTCOME_CONSISTENT = 'consistent';

    public const string OUTCOME_INCONSISTENT = 'inconsistent';

    public const string OUTCOME_ATTENTION = 'attention_required';

    public const string OUTCOME_INCOMPLETE = 'incomplete';

    public const string REASON_COMPLETE = 'audit_complete';

    /**
     * @param  array<string, int>  $scanned
     * @param  array<string, int>  $classificationCounts
     * @param  array<string, int>  $findingCounts
     * @param  list<VerificationExample>  $examples
     */
    private function __construct(
        public bool $complete,
        public string $reason,
        public ?int $highWatermark,
        public ?string $auditAt,
        public array $scanned,
        public array $classificationCounts,
        public array $findingCounts,
        public array $examples,
        public bool $examplesTruncated,
        public ?string $projectionControlState,
    ) {}

    /**
     * @param  array<string, int>  $scanned
     * @param  array<string, int>  $classificationCounts
     * @param  array<string, int>  $findingCounts
     * @param  list<VerificationExample>  $examples
     */
    public static function completed(
        int $highWatermark,
        string $auditAt,
        array $scanned,
        array $classificationCounts,
        array $findingCounts,
        array $examples,
        bool $examplesTruncated,
        ?string $projectionControlState = null,
    ): self {
        $missing = array_values(array_diff(self::REQUIRED_PASSES, array_keys($scanned)));

        if ($missing !== []) {
            throw new IncompleteVerificationScope(
                'A verification report cannot be complete without every required pass. Missing: '
                .implode(', ', $missing).'.'
            );
        }

        return new self(
            complete: true,
            reason: self::REASON_COMPLETE,
            highWatermark: $highWatermark,
            auditAt: $auditAt,
            scanned: $scanned,
            classificationCounts: $classificationCounts,
            findingCounts: $findingCounts,
            examples: $examples,
            examplesTruncated: $examplesTruncated,
            projectionControlState: $projectionControlState,
        );
    }

    /**
     * @param  array<string, int>  $scanned
     * @param  array<string, int>  $classificationCounts
     * @param  array<string, int>  $findingCounts
     * @param  list<VerificationExample>  $examples
     */
    public static function incomplete(
        string $reason,
        ?int $highWatermark,
        ?string $auditAt,
        array $scanned,
        array $classificationCounts,
        array $findingCounts,
        array $examples,
        bool $examplesTruncated,
        ?string $projectionControlState = null,
    ): self {
        return new self(
            complete: false,
            reason: $reason,
            highWatermark: $highWatermark,
            auditAt: $auditAt,
            scanned: $scanned,
            classificationCounts: $classificationCounts,
            findingCounts: $findingCounts,
            examples: $examples,
            examplesTruncated: $examplesTruncated,
            projectionControlState: $projectionControlState,
        );
    }

    // Precedence is incomplete > inconsistent > attention > consistent, not numeric exit-code order.
    public function outcome(): string
    {
        if (! $this->complete) {
            return self::OUTCOME_INCOMPLETE;
        }

        if ($this->contradictionCount() > 0) {
            return self::OUTCOME_INCONSISTENT;
        }

        return $this->needsAttention() ? self::OUTCOME_ATTENTION : self::OUTCOME_CONSISTENT;
    }

    public function exitCode(): int
    {
        return match ($this->outcome()) {
            self::OUTCOME_INCOMPLETE => self::EXIT_INCOMPLETE,
            self::OUTCOME_INCONSISTENT => self::EXIT_INCONSISTENT,
            self::OUTCOME_ATTENTION => self::EXIT_ATTENTION,
            default => self::EXIT_CONSISTENT,
        };
    }

    // Counted from findings: reverse-pass orphans have no canonical classification.
    private function contradictionCount(): int
    {
        $total = 0;

        foreach ($this->findingCounts as $finding => $count) {
            $case = VerificationFinding::tryFrom($finding);

            if ($case?->isContradiction() === true) {
                $total += $count;
            }
        }

        return $total;
    }

    private function needsAttention(): bool
    {
        $overdue = $this->classificationCounts[VerificationClassification::DeliveryOverdue->value] ?? 0;
        $exhausted = $this->classificationCounts[VerificationClassification::Exhausted->value] ?? 0;
        $futureTime = $this->findingCounts[VerificationFinding::EventTimeInFuture->value] ?? 0;

        return $overdue > 0 || $exhausted > 0 || $futureTime > 0;
    }
}
