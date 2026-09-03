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
use Modules\Payments\Infrastructure\Verification\VerificationEnvelope;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One canonical event, one envelope, one short transaction.
 *
 *     <refuse without --delivery-quiesced, and without a canonical event id>
 *     <log operator intent; a failure here means the mutation never begins>
 *     BEGIN  (READ COMMITTED)
 *     SET LOCAL statement_timeout / transaction_timeout
 *     SELECT ... FROM stored_events WHERE event_properties->>'eventId' = ? LIMIT 2 FOR UPDATE
 *     <refuse on zero, two, or undecodable canonical history>
 *     SELECT ... FROM payment_outbox WHERE event_id = ? FOR UPDATE
 *     <refuse when another envelope already holds this aggregate identity>
 *     SELECT created_at FROM payment_projector_receipts WHERE projector_name = ? AND event_id = ?
 *     UPDATE or INSERT exactly one payment_outbox row
 *     COMMIT
 *     <log the outcome; a failure here does not undo the commit>
 *
 * THE CANONICAL ROW IS WHAT SERIALISES TWO REPAIRS, and locking it is the only
 * reason to. When the envelope is missing there is no outbox row to lock, so two
 * concurrent repairs of the same event would otherwise both find nothing and both
 * insert - one of them dying on the aggregate-identity unique constraint with an
 * error that describes a conflict rather than a race. The canonical row exists in
 * every case by definition, so it is the identity both repairs contend on, and the
 * second one reads the first one's committed result instead of a stale snapshot.
 *
 * LOCKING CANONICAL HISTORY DOES NOT AUTHORISE CHANGING IT. `stored_events` is
 * read here and never written, and `FOR UPDATE` is used for mutual exclusion on an
 * identity, not as a prelude to an update.
 *
 * READ COMMITTED, NOT REPEATABLE READ, and deliberately unlike the verifier. This
 * transaction is short and holds its own row locks, so it wants to SEE a competing
 * repair's committed result after waiting on that lock - which a repeatable-read
 * snapshot taken before the wait would hide, leaving the second repair to overwrite
 * the first from a view of the world that is already stale.
 *
 * IT NEVER REUSES THE PROJECTION OR DISPATCH PATH. The aggregate is not rehydrated,
 * no event is dispatched, Spatie replay is not invoked, and PaymentOutboxWriter -
 * which refuses during replay precisely because it is not a repair API - is not
 * called. The envelope is derived from the persisted canonical row and nothing else.
 *
 * IT WRITES ONE TABLE. Projector receipts are read to decide the completion marker;
 * read rows and HTTP idempotency receipts are not accessed. Canonical events are
 * read and locked, never written. A receipt without its read row survives this
 * command as the corruption it was: read-model recovery is T11's rebuild.
 */
final readonly class DatabaseOutboxRepair implements OutboxRepair
{
    /**
     * Every Payments alias, so an event this command cannot decode is REFUSED
     * rather than reported as absent history.
     */
    private const string PAYMENTS_ALIAS_PREFIX = 'payments.';

    /**
     * The bounds from the approved contract, as literals rather than configuration.
     *
     * NOT OPERATOR DIALS. They bound how long a manual command may hold a lock on
     * canonical history while delivery is stopped; widening them by editing an
     * environment file would change that guarantee with nothing reporting it. The
     * relay's and the audit's tunables are timings a load test is expected to
     * adjust, which these are not.
     */
    private const int STATEMENT_TIMEOUT_MS = 2_000;

    private const int TRANSACTION_TIMEOUT_MS = 10_000;

    /** `query_canceled` - statement_timeout fired. */
    private const string STATEMENT_TIMEOUT_SQLSTATE = '57014';

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {}

    #[Override]
    public function repair(string $eventId, bool $deliveryQuiesced, string $operationId): RepairOutcome
    {
        // BEFORE ANY DATABASE WORK, both of them. The acknowledgement is an
        // operational precondition rather than a validation rule, and a malformed
        // identifier would otherwise reach a uuid column as a parameter and raise
        // 22P02 - a database error where the honest answer is a refusal.
        if (! $deliveryQuiesced) {
            return $this->refuse(RepairReason::DeliveryNotQuiesced, null, false, $operationId);
        }

        // NOT ECHOED, HERE OR IN THE LOG. Whatever was typed is operator input, and
        // the refusal is diagnosable without repeating it.
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
            // THE MUTATION NEVER BEGINS. A repair whose intent was never recorded
            // would be a change to durable delivery state with no evidence that
            // anyone asked for it.
            return RepairOutcome::failed(RepairReason::IntentAuditFailed, RepairMutationState::None, $eventId);
        }

        $outcome = $this->apply($eventId);

        return $this->recordOutcome($outcome, $operationId);
    }

    /**
     * The transaction, and every way out of it.
     *
     * THE TWO PHASES ARE LOCALS, not questions asked of the connection afterwards,
     * for the reason the relay and the consumer record: a failure before BEGIN and
     * a failure after a successful COMMIT leave identical end states, so a
     * classification read from the connection would report a durable change for
     * work that never started, or a rollback for work already on disk.
     */
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
                // NAMED EXPLICITLY, NOT `(array) $row`, for the reason the audit
                // names them: the decoder reads five keys and this select returns
                // six, so spreading the row would hand it a column it does not read
                // and hide a rename behind a cast.
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
            // AFTER A COMMIT WAS ATTEMPTED, THE OUTCOME IS UNKNOWN AND STAYS SO.
            // Rolling back here would be a lie either way: the transaction may
            // already be durable, and asking the connection cannot distinguish the
            // two. Nothing is retried.
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

    /**
     * Resolve the canonical event by its durable event id, and lock what it finds.
     *
     * THE LOOKUP WITHOUT A CURRENT INDEX. The durable event id lives inside
     * `stored_events.event_properties`, which is JSON with no index and none
     * proposed, so this is a scan of Payments history on every call. That is
     * accepted rather than overlooked: repair is a manual, offline, single-event
     * operator action and the scan is bounded by the statement timeout above. The
     * verifier deliberately avoids this shape; repair cannot, because it is handed
     * an event id and must not infer identity from the very envelope it is
     * repairing.
     *
     * EXCEEDING THAT TIMEOUT IS A FAILURE, NOT A REFUSAL. It leaves exit 2, a
     * `statement_timeout` reason and a mutation state describing what became of the
     * transaction. Nothing is written either way, but an operator must be able to
     * tell "the command declined" from "the command did not finish".
     *
     * LIMIT 2, NOT LIMIT 1, AND THAT IS THE AMBIGUITY CHECK. Asking for one row
     * would make a duplicated event id indistinguishable from a unique one, and the
     * command would silently reconstruct an envelope from whichever history the
     * planner reached first. It also means there is no early exit: proving a match
     * is unique costs the whole scan even when the row is found immediately.
     *
     * NO ORDER BY. With two matches the answer is a refusal whichever order they
     * arrive in, and with one match order is meaningless - so paying for a sort, or
     * for an index scan to avoid one, would buy nothing.
     *
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

    /**
     * Is this aggregate identity already spoken for by a different envelope?
     *
     * CHECKED RATHER THAN CAUGHT. `payment_outbox` is unique on
     * (aggregate_uuid, aggregate_version), so writing over a conflict would raise a
     * constraint violation - but a violation aborts the transaction and arrives as
     * a database failure, when the honest answer is a refusal with nothing written
     * and a reason an operator can act on.
     *
     * THE CONFLICT SET CAN ONLY SHRINK BETWEEN THIS READ AND THE WRITE, which is
     * why the read takes no lock of its own - and could not usefully take one,
     * because FOR UPDATE cannot lock a row that does not exist yet. A repair only
     * ever writes its OWN canonical row's (aggregate_uuid, aggregate_version), and
     * `stored_events` is unique on that pair, so any repair able to move an
     * envelope ONTO this identity is a repair of this same canonical row and is
     * already serialised behind the lock above. No other writer touches those two
     * columns. A stale read therefore costs at most a conservative refusal - never
     * an overwrite, and never a constraint violation reported as a database error.
     *
     * REFUSING IS THE WHOLE ANSWER. Removing or moving the conflicting envelope
     * would be a second corruption committed in the name of repairing the first.
     */
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

    /**
     * The named projector's receipt time, or null.
     *
     * THE PROJECTOR NAME IS PART OF THE QUESTION. A receipt written by some other
     * projector says nothing about whether `payment_details` was built, and
     * treating one as completion evidence would stamp a marker that retires an
     * envelope whose work was never done.
     */
    private function namedReceiptCreatedAt(string $eventId): ?string
    {
        /** @var object{created_at: string}|null $row */
        $row = $this->connection->selectOne(
            'select created_at from payment_projector_receipts where projector_name = ? and event_id = ?::uuid',
            [PaymentProjectionConsumer::PROJECTOR_NAME, $eventId],
        );

        return $row?->created_at;
    }

    /**
     * Write exactly one row: correct the existing envelope, or insert a new one.
     *
     * THE CANONICAL COLUMNS ARE ALL RESTATED ON AN UPDATE even when only some of
     * them drifted. The row is being made to agree with canonical history, and
     * `changedFields` already reports which ones actually differed; writing a subset
     * would make the statement depend on the comparison being exhaustive.
     *
     * Existing delivery history is absent from the UPDATE. `publish_count`, `last_published_at`,
     * `consecutive_transport_failures`, `last_transport_error`,
     * `projection_failure_count`, `last_projection_error`,
     * `projection_failure_generation`, `exhausted_at` and `created_at` are never
     * updated, so transport history, the poison budget, the generation fence and
     * exhaustion survive. INSERT instead uses defaults and fresh creation timestamps.
     */
    private function write(
        string $eventId,
        PaymentInitiatedEnvelope $canonical,
        string $canonicalPayload,
        RepairIntent $intent,
    ): void {
        $occurredAt = $canonical->occurredAt->format(PaymentInitiated::OCCURRED_AT_FORMAT);

        if ($intent->createsRow) {
            // The counters, the errors and `exhausted_at` are omitted so they take
            // their column defaults - zero and null - which is why this result
            // reports that delivery history was reinitialised rather than restored.
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

        // `available_at = now()` is appended only when the contract permits it, so
        // an exhausted or completed envelope keeps the availability it had.
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

    /**
     * Close the transaction after a refusal, and report it.
     *
     * COMMITTED, NOT ROLLED BACK, for the reason the operator retry commits its own
     * refusals: nothing was written, and the lock on canonical history should be
     * released now rather than held to the end of a transaction with no work left.
     */
    private function refuseInTransaction(RepairReason $reason, string $eventId): RepairOutcome
    {
        $this->connection->commit();

        return RepairOutcome::refused($reason, $eventId, true);
    }

    private function refuse(RepairReason $reason, ?string $eventId, bool $quiesced, string $operationId): RepairOutcome
    {
        return $this->recordOutcome(RepairOutcome::refused($reason, $eventId, $quiesced), $operationId);
    }

    /**
     * Record what happened, and say so when that record could not be made.
     *
     * THE COMMIT IS NOT UNDONE BY A LOGGING FAILURE, and the result must not
     * pretend otherwise. A confirmed commit whose audit write failed reports
     * `committed` with `audit_failed`; a no-op in the same position reports
     * `no_changes`. Both exit non-zero, because the operator's record of the change
     * is missing even though the change is not.
     *
     * DATABASE COMMIT AND LOG PERSISTENCE ARE NOT ONE TRANSACTION. Logging
     * successfully is not durable atomic audit retention, and a process death
     * between the two produces no final answer at all - which is why the contract
     * says absence of an answer is not evidence that nothing changed.
     */
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

    /**
     * What a completed repair becomes when only its record failed.
     *
     * Only confirmed Created/Repaired outcomes become Committed. A rollback,
     * uncertainty, refusal or no-op keeps its prior mutation state.
     */
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
            // The rollback itself is not confirmed, so the caller must not claim it.
            return false;
        }
    }

    /** Which refusal a shared canonical decoding failure is. */
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

    /** A stable category for the failure, never the driver's wording. */
    private static function failureReason(Throwable $failure): RepairReason
    {
        return RetryingAtomicTransaction::sqlStateOf($failure) === self::STATEMENT_TIMEOUT_SQLSTATE
            ? RepairReason::StatementTimeout
            : RepairReason::DatabaseError;
    }
}
