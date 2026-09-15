<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Repair;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Payments\Application\Repair\OutboxRepair;
use Modules\Payments\Application\Repair\RepairMutationState;
use Modules\Payments\Application\Repair\RepairOutcome;
use Modules\Payments\Application\Repair\RepairReason;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Infrastructure\Projection\PaymentInitiatedEnvelope;
use Modules\Payments\Infrastructure\Projection\PaymentProjectionConsumer;
use Modules\Payments\Infrastructure\Projection\ProjectionEnvelopeRejected;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Modules\Payments\Infrastructure\Projection\ProjectionGateState;
use Modules\Payments\Infrastructure\Verification\VerificationEnvelope;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

// Locking the canonical stored_events row serialises concurrent repairs, even when the envelope is missing.
// READ COMMITTED so a repair that waited on that lock sees the first repair's committed result.
final readonly class DatabaseOutboxRepair implements OutboxRepair
{
    private const string PAYMENTS_ALIAS_PREFIX = 'payments.';

    // Contract literals, not operator dials: they bound how long a manual repair locks canonical history.
    private const int STATEMENT_TIMEOUT_MS = 2_000;

    private const int TRANSACTION_TIMEOUT_MS = 10_000;

    private const string STATEMENT_TIMEOUT_SQLSTATE = '57014';

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {}

    #[Override]
    public function repair(string $eventId, bool $deliveryQuiesced, string $operationId): RepairOutcome
    {
        if (! $deliveryQuiesced) {
            return $this->refuse(RepairReason::DeliveryNotQuiesced, null, false, $operationId);
        }

        // Validated before any query: a malformed id is never echoed and must not reach a uuid column.
        if (! Str::isUuid($eventId)) {
            return $this->refuse(RepairReason::MalformedEventId, null, true, $operationId);
        }

        try {
            $this->logger->warning('Payments outbox: an operator asked to repair one envelope.', [
                'category' => 'outbox_repair_intent',
                'operation_id' => $operationId,
                'event_id' => $eventId,
                'delivery_quiesced' => true,
            ]);
        } catch (Throwable) {
            return RepairOutcome::failed(RepairReason::IntentAuditFailed, RepairMutationState::None, $eventId);
        }

        $outcome = $this->apply($eventId);

        return $this->recordOutcome($outcome, $operationId);
    }

    private function apply(string $eventId): RepairOutcome
    {
        // A savepoint cannot establish this command's isolation or durable commit.
        if ($this->connection->transactionLevel() !== 0) {
            return RepairOutcome::failed(RepairReason::DatabaseError, RepairMutationState::None, $eventId);
        }

        $transactionStarted = false;
        $commitAttempted = false;
        $intent = null;

        try {
            $this->connection->beginTransaction();
            $transactionStarted = true;

            $this->connection->statement('set transaction isolation level read committed, read write');
            $this->connection->statement('set local statement_timeout = '.self::STATEMENT_TIMEOUT_MS);
            $this->connection->statement('set local transaction_timeout = '.self::TRANSACTION_TIMEOUT_MS);

            if (new ProjectionGate($this->connection)->inspectPaymentDetails() !== ProjectionGateState::Open) {
                return $this->refuseInTransaction(RepairReason::RebuildInProgress, $eventId);
            }

            $canonicalRows = $this->lockCanonicalEvent($eventId);

            if ($canonicalRows === []) {
                return $this->refuseInTransaction(RepairReason::CanonicalEventAbsent, $eventId);
            }

            if (count($canonicalRows) > 1) {
                return $this->refuseInTransaction(RepairReason::CanonicalEventAmbiguous, $eventId);
            }

            $canonicalRow = $canonicalRows[0];
            $canonicalPayload = $canonicalRow->event_properties;

            try {
                $canonical = PaymentInitiatedEnvelope::fromStoredEventRow([
                    'aggregate_uuid' => $canonicalRow->aggregate_uuid,
                    'aggregate_version' => $canonicalRow->aggregate_version,
                    'event_version' => $canonicalRow->event_version,
                    'event_class' => $canonicalRow->event_class,
                    'event_properties' => $canonicalPayload,
                ]);
            } catch (ProjectionEnvelopeRejected $rejected) {
                return $this->refuseInTransaction(self::reasonFor($rejected), $eventId);
            }

            $existing = $this->lockEnvelope($eventId);

            if ($this->identityHeldByAnotherEnvelope($canonical, $eventId)) {
                return $this->refuseInTransaction(RepairReason::AggregateIdentityConflict, $eventId);
            }

            $receiptCreatedAt = $this->namedReceiptCreatedAt($eventId);
            $intent = RepairIntent::resolve($canonical, $canonicalPayload, $existing, $receiptCreatedAt);

            if ($intent->writes()) {
                $this->write($eventId, $canonical, $canonicalPayload, $intent);
            }

            $commitAttempted = true;
            $this->connection->commit();

            return RepairOutcome::completed(
                mutationState: $intent->mutationState(),
                eventId: $eventId,
                changedFields: $intent->changedFields,
                historyReinitialized: $intent->createsRow,
            );
        } catch (Throwable $failure) {
            // After a commit attempt the outcome stays unknown and nothing is retried.
            if ($commitAttempted) {
                return RepairOutcome::failed(
                    RepairReason::CommitOutcomeUnknown,
                    RepairMutationState::Unknown,
                    $eventId,
                    $intent === null ? [] : $intent->changedFields,
                );
            }

            $rolledBack = $transactionStarted && $this->rollBack();

            return RepairOutcome::failed(
                self::failureReason($failure),
                $rolledBack ? RepairMutationState::RolledBack : RepairMutationState::Unknown,
                $eventId,
            );
        }
    }

    // An unindexed JSON scan, accepted for a manual command bounded by statement_timeout.
    // LIMIT 2, not 1: a duplicated durable event id must be refused, never resolved by scan order.
    /**
     * @return list<object{id: int|string, aggregate_uuid: string, aggregate_version: int|string, event_version: int|string, event_class: string, event_properties: string}>
     */
    private function lockCanonicalEvent(string $eventId): array
    {
        /** @var list<object{id: int|string, aggregate_uuid: string, aggregate_version: int|string, event_version: int|string, event_class: string, event_properties: string}> $rows */
        $rows = $this->connection->select(
            <<<'SQL'
            select id,
                   aggregate_uuid,
                   aggregate_version,
                   event_version,
                   event_class,
                   event_properties::text as event_properties
              from stored_events
             where event_class like ?
               and event_properties->>'eventId' = ?
             limit 2
             for update
            SQL,
            [self::PAYMENTS_ALIAS_PREFIX.'%', $eventId],
        );

        return $rows;
    }

    private function lockEnvelope(string $eventId): ?VerificationEnvelope
    {
        /** @var object{event_type: string, schema_version: int|string, aggregate_uuid: string, aggregate_version: int|string, occurred_at: string, projection_completed_at: string|null, exhausted_at: string|null, payload: string|null}|null $row */
        $row = $this->connection->selectOne(
            <<<'SQL'
            select event_type,
                   schema_version,
                   aggregate_uuid,
                   aggregate_version,
                   occurred_at,
                   projection_completed_at,
                   exhausted_at,
                   payload::text as payload
              from payment_outbox
             where event_id = ?::uuid
             for update
            SQL,
            [$eventId],
        );

        return $row === null ? null : VerificationEnvelope::fromRow($row);
    }

    // No lock needed: the conflict set can only shrink, because only a repair of this already locked
    // canonical row could move an envelope onto its identity.
    private function identityHeldByAnotherEnvelope(PaymentInitiatedEnvelope $canonical, string $eventId): bool
    {
        return $this->connection->selectOne(
            <<<'SQL'
            select event_id
              from payment_outbox
             where aggregate_uuid = ?::uuid
               and aggregate_version = ?
               and event_id <> ?::uuid
             limit 1
            SQL,
            [$canonical->paymentId, $canonical->aggregateVersion, $eventId],
        ) !== null;
    }

    private function namedReceiptCreatedAt(string $eventId): ?string
    {
        /** @var object{created_at: string}|null $row */
        $row = $this->connection->selectOne(
            'select created_at from payment_projector_receipts where projector_name = ? and event_id = ?::uuid',
            [PaymentProjectionConsumer::PROJECTOR_NAME, $eventId],
        );

        return $row?->created_at;
    }

    // The UPDATE never touches delivery history: counters, errors, the generation, exhausted_at and created_at survive.
    private function write(
        string $eventId,
        PaymentInitiatedEnvelope $canonical,
        string $canonicalPayload,
        RepairIntent $intent,
    ): void {
        $occurredAt = $canonical->occurredAt->format(PaymentInitiated::OCCURRED_AT_FORMAT);

        if ($intent->createsRow) {
            $this->connection->statement(
                <<<'SQL'
                insert into payment_outbox (
                    event_id, event_type, schema_version, aggregate_uuid, aggregate_version,
                    payload, occurred_at, available_at, projection_completed_at, created_at, updated_at
                ) values (
                    ?::uuid, ?, ?, ?::uuid, ?,
                    ?::jsonb, ?::timestamptz, now(), ?::timestamptz, now(), now()
                )
                SQL,
                [
                    $eventId,
                    PaymentInitiated::ALIAS,
                    PaymentInitiatedEnvelope::SCHEMA_VERSION,
                    $canonical->paymentId,
                    $canonical->aggregateVersion,
                    $canonicalPayload,
                    $occurredAt,
                    $intent->marker,
                ],
            );

            return;
        }

        $availability = $intent->refreshesAvailability ? 'available_at = now(),' : '';

        $this->connection->statement(
            <<<SQL
            update payment_outbox
               set event_type = ?,
                   schema_version = ?,
                   aggregate_uuid = ?::uuid,
                   aggregate_version = ?,
                   payload = ?::jsonb,
                   occurred_at = ?::timestamptz,
                   projection_completed_at = ?::timestamptz,
                   {$availability}
                   updated_at = now()
             where event_id = ?::uuid
            SQL,
            [
                PaymentInitiated::ALIAS,
                PaymentInitiatedEnvelope::SCHEMA_VERSION,
                $canonical->paymentId,
                $canonical->aggregateVersion,
                $canonicalPayload,
                $occurredAt,
                $intent->marker,
                $eventId,
            ],
        );
    }

    private function refuseInTransaction(RepairReason $reason, string $eventId): RepairOutcome
    {
        $this->connection->commit();

        return RepairOutcome::refused($reason, $eventId, true);
    }

    private function refuse(RepairReason $reason, ?string $eventId, bool $quiesced, string $operationId): RepairOutcome
    {
        return $this->recordOutcome(RepairOutcome::refused($reason, $eventId, $quiesced), $operationId);
    }

    // The commit and the audit log are not atomic: a failed log after a confirmed commit still reports it as committed.
    private function recordOutcome(RepairOutcome $outcome, string $operationId): RepairOutcome
    {
        try {
            $this->logger->warning('Payments outbox: a targeted repair finished.', [
                'category' => 'outbox_repair_outcome',
                'operation_id' => $operationId,
                'outcome' => $outcome->outcome(),
                'reason' => $outcome->reason->value,
                'mutation_state' => $outcome->mutationState->value,
                'event_id' => $outcome->eventId,
                'delivery_quiesced' => $outcome->deliveryQuiesced,
                'changed_fields' => $outcome->changedFields,
                'history_reinitialized' => $outcome->historyReinitialized,
            ]);

            return $outcome;
        } catch (Throwable) {
            return RepairOutcome::failed(
                RepairReason::AuditFailed,
                self::committedState($outcome->mutationState),
                $outcome->eventId,
                $outcome->changedFields,
                $outcome->historyReinitialized,
                $outcome->deliveryQuiesced,
            );
        }
    }

    private static function committedState(RepairMutationState $state): RepairMutationState
    {
        return match ($state) {
            RepairMutationState::Created, RepairMutationState::Repaired => RepairMutationState::Committed,
            default => $state,
        };
    }

    private function rollBack(): bool
    {
        if ($this->connection->transactionLevel() <= 0) {
            return false;
        }

        try {
            $this->connection->rollBack();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function reasonFor(ProjectionEnvelopeRejected $rejected): RepairReason
    {
        return match ($rejected->category) {
            ProjectionEnvelopeRejected::UNSUPPORTED_ALIAS => RepairReason::CanonicalAliasUnsupported,
            ProjectionEnvelopeRejected::UNSUPPORTED_SCHEMA_VERSION => RepairReason::CanonicalSchemaVersionUnsupported,
            ProjectionEnvelopeRejected::UNSUPPORTED_AGGREGATE_VERSION => RepairReason::CanonicalAggregateVersionUnsupported,
            ProjectionEnvelopeRejected::CONTRADICTORY_IDENTITY => RepairReason::CanonicalIdentityContradictory,
            default => RepairReason::CanonicalPayloadMalformed,
        };
    }

    private static function failureReason(Throwable $failure): RepairReason
    {
        return RetryingAtomicTransaction::sqlStateOf($failure) === self::STATEMENT_TIMEOUT_SQLSTATE
            ? RepairReason::StatementTimeout
            : RepairReason::DatabaseError;
    }
}
