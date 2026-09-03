<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Verification;

/**
 * What one audit run observed, and the single answer it exits with.
 *
 * THE EXIT CODE IS DERIVED HERE, NOT AT THE CONSOLE, because the precedence is
 * a contract rather than a formatting choice:
 *
 *     2 (incomplete) > 1 (inconsistent) > 3 (attention) > 0 (consistent)
 *
 * and it is NOT numerical ordering. A run that found an inconsistency and then
 * lost its connection has not proved anything about the rest of history, so it
 * must exit 2 even though 1 is "worse-looking"; and overdue delivery must not
 * outrank a real contradiction just because 3 is a larger number. Deriving it
 * from the counts in one place is what stops a caller from reassembling it
 * differently.
 *
 * Contradiction findings decide exit 1, including reverse-pass orphans that belong
 * to no canonical event classification. Operational findings are distinguished by
 * VerificationFinding::isContradiction(). Counts describe facts, not unique payments.
 *
 * `scanned` NAMES THE PASSES THAT ACTUALLY RAN, and that is the scope statement.
 * A COMPLETE REPORT MUST CARRY ALL FOUR, which completed() refuses to build
 * without: full-scope completion is claimable only when canonical history and
 * each of the three derived tables have been walked in the same snapshot. A run
 * that finished three of them is an incomplete run, not a clean one with a
 * smaller scope.
 *
 * AN INCOMPLETE RUN KEEPS ITS FINDINGS AND SAYS SO. `complete` is false, the
 * reason names the safe category that stopped it, and the counts describe only
 * what was examined before that point — never a clean bill of health for
 * history the audit never reached.
 */
final readonly class VerificationReport
{
    /** The canonical forward pass over `stored_events`. */
    public const string PASS_CANONICAL_EVENTS = 'canonical_events';

    /** The reverse pass over the named projector's receipts. */
    public const string PASS_PROJECTOR_RECEIPTS = 'projector_receipts';

    /** The reverse pass over the read model. */
    public const string PASS_READ_ROWS = 'payment_details';

    /** The reverse pass over delivery envelopes. */
    public const string PASS_OUTBOX_ENVELOPES = 'payment_outbox';

    /**
     * Every pass a complete audit has to have finished, in report order.
     *
     * @var list<string>
     */
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
    ): self {
        // FULL SCOPE OR NOT COMPLETE, ENFORCED HERE RATHER THAN TRUSTED. An
        // adapter that grew a fifth pass, or lost one to an early return, would
        // otherwise keep reporting `complete: true` over a scope that had quietly
        // shrunk - and a clean audit that skipped a table is the one answer this
        // command must never give.
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
        );
    }

    /**
     * A run that stopped, carrying what it had already seen.
     *
     * $auditAt IS NULLABLE HERE AND NOWHERE ELSE: a failure that happened before
     * the snapshot was established has no audit timestamp, and inventing one
     * would date findings that do not exist to a moment the audit never reached.
     *
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
        );
    }

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

    /**
     * How many contradictions were found, in either direction.
     *
     * COUNTED FROM FINDINGS, NOT FROM CLASSIFICATIONS, because only canonical
     * events get classified: a reverse pass finds an orphan that belongs to no
     * canonical event, so there is nothing to mark `inconsistent`. Reading exit 1
     * off the classifications would have made every orphan exit 0.
     *
     * The two agree on the forward path by construction - a canonical event is
     * classified `inconsistent` exactly when it recorded a contradiction - so this
     * widens the rule rather than changing it.
     */
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

    /**
     * Overdue delivery, exhaustion, or the event-time anomaly.
     *
     * NONE OF THE THREE IS CORRUPTION, and none of them may be silent either.
     * Exhaustion in particular is a stopped delivery that only an operator can
     * restart, so a run reporting it must not exit 0 beside a genuinely clean one.
     */
    private function needsAttention(): bool
    {
        $overdue = $this->classificationCounts[VerificationClassification::DeliveryOverdue->value] ?? 0;
        $exhausted = $this->classificationCounts[VerificationClassification::Exhausted->value] ?? 0;
        $futureTime = $this->findingCounts[VerificationFinding::EventTimeInFuture->value] ?? 0;

        return $overdue > 0 || $exhausted > 0 || $futureTime > 0;
    }
}
