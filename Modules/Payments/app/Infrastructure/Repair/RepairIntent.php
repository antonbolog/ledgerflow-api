<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Repair;

use Modules\Payments\Application\Repair\RepairMutationState;
use Modules\Payments\Infrastructure\Projection\PaymentInitiatedEnvelope;
use Modules\Payments\Infrastructure\Verification\VerificationEnvelope;

/**
 * What a repair would change, decided before anything is written.
 *
 * THE MUTATION MATRIX AS ONE DECISION. Working out whether a column changes, what
 * the completion marker becomes, and whether the row becomes eligible again are
 * three questions with one answer, and computing them inside the UPDATE would
 * scatter the contract across a SQL string. Here they are comparable, testable
 * without a database, and reported as `changed_fields` from the same values that
 * drive the statement.
 *
 * THE CANONICAL COMPARISON IS REUSED, NOT REIMPLEMENTED. VerificationEnvelope
 * already owns "does this envelope match canonical history", including semantic
 * JSON equality for the payload and instant equality for the event time. A second
 * copy of that here would be a second definition of the same agreement, free to
 * drift - and the audit and the repair would disagree about whether a row was
 * already correct.
 *
 * NOTHING IS A CHANGE UNLESS IT REALLY DIFFERS. An empty change set is the
 * `unchanged` result, and it writes nothing at all: not the canonical columns it
 * would have restated to the same values, not `updated_at`, and not the transport
 * backoff. Repair is not an implicit force-redelivery command, so running it twice
 * must be indistinguishable from running it once.
 */
final readonly class RepairIntent
{
    /**
     * The columns an insert DECIDES a value for, in statement order.
     *
     * NOT EVERY COLUMN THE STATEMENT TOUCHES. `event_id` is the identity being
     * repaired rather than something repair chose, and `created_at` / `updated_at`
     * are bookkeeping that changes on every write by definition. Listing them would
     * make `changed_fields` differ in kind between a created row and a corrected
     * one, when it is meant to answer one question on both paths: which values did
     * this repair decide?
     *
     * @var list<string>
     */
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
            // A MISSING ENVELOPE IS REBUILT FROM CANONICAL HISTORY, and its delivery
            // history cannot come with it: publication counts, transport failures,
            // the failure generation and any former exhaustion were deleted with the
            // row and are not derivable from a stored event. The counters take their
            // zero defaults and the result says the history was reinitialised, which
            // is a weaker claim than restored and the only honest one.
            return new self(
                createsRow: true,
                changedFields: self::CREATED_FIELDS,
                // The named receipt still decides completion. Without one the row is
                // inserted immediately eligible, which is the only case where repair
                // makes an envelope deliverable that was not there at all.
                marker: $receiptCreatedAt,
                refreshesAvailability: false,
            );
        }

        $changed = $existing->mismatchedFields($canonical, $canonicalPayload);

        // COMPLETION COMES FROM THE RECEIPT, IN BOTH DIRECTIONS. With a receipt, an
        // existing marker is preserved - its timestamp is the historical fact, and
        // overwriting it with the receipt's would rewrite when the projection
        // completed - and a missing one is filled from the receipt. Without a
        // receipt the marker MUST be null, so an erroneous one is cleared rather
        // than left to retire an envelope whose projection never happened.
        $marker = $receiptCreatedAt === null
            ? null
            : ($existing->projectionCompletedAt ?? $receiptCreatedAt);

        if ($marker !== $existing->projectionCompletedAt) {
            $changed[] = 'projection_completed_at';
        }

        // ELIGIBILITY IS THE NARROWEST OF THE THREE RULES. A corrected envelope
        // becomes deliverable again only when something actually changed, no named
        // receipt claims it is already projected, and it is not exhausted.
        //
        // AN EXHAUSTED ROW KEEPS ITS AVAILABILITY AND ITS exhausted_at. Repair
        // corrects what the envelope SAYS; `payments:outbox:retry` remains the only
        // path that decides an exhausted envelope may be delivered again, and
        // bypassing it here would let a repair silently spend a poison budget the
        // operator never reset.
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
