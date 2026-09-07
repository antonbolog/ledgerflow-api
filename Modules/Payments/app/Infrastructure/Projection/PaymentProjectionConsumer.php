<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

use Illuminate\Database\Connection;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One delivered event id, one deterministic projection transaction.
 *
 *     BEGIN
 *     SELECT pg_try_advisory_xact_lock_shared(...); SELECT state FROM ...   <- the gate
 *     SELECT ... FROM payment_outbox WHERE event_id = ? FOR UPDATE
 *     INSERT INTO payment_projector_receipts ... ON CONFLICT DO NOTHING
 *     INSERT INTO payment_details ...            <- first delivery only
 *     UPDATE payment_outbox SET projection_completed_at = now()
 *     COMMIT
 *
 * The gate runs inside this same transaction on this same connection, before the
 * envelope lock. See ADR 0005 and ProjectionGate.
 *
 * The receipt is the idempotency boundary, not the read row and not the marker:
 * `ON CONFLICT DO NOTHING` returns 1 for a first delivery and 0 for a duplicate.
 * Asking whether a `payment_details` row exists would let two concurrent
 * deliveries both read "no" before either writes.
 *
 * RetryingAtomicTransaction is deliberately not reused: redelivery and the relay's
 * reconciliation grace own recovery here, and a second retry policy would be a
 * second owner of the SQLSTATE allowlist. Connection::transaction() is avoided for
 * the reason OutboxRelay records - its commit-exception path can leave the server
 * transaction open while transactionLevel() reports 0.
 *
 * Failure counting, `last_projection_error` and `exhausted_at` belong to
 * ProjectionFailureRecorder, in its own transaction.
 */
final readonly class PaymentProjectionConsumer
{
    /**
     * Part of the receipt primary key, so it is a durable identifier rather than a
     * label: changing it makes every existing receipt invisible and every delivered
     * event project a second time.
     */
    public const string PROJECTOR_NAME = 'payment_details';

    /** The only first-slice lifecycle value `payment_details.status` takes. */
    public const string INITIATED_STATUS = 'initiated';

    public function __construct(
        private Connection $connection,
        private ProjectionConfiguration $config,
        private ProjectionFailureRecorder $recorder,
        private LoggerInterface $logger,
        private ProjectionGate $gate,
    ) {}

    public function project(string $eventId): ProjectionOutcome
    {
        // Locals, not questions asked of the connection afterwards: a failure before
        // BEGIN and one after a successful COMMIT leave identical end states, so a
        // classification read from the connection cannot tell them apart.
        $transactionStarted = false;
        $commitAttempted = false;

        // Captured under the lock, and null until then. An operator retry in between
        // increments the generation, and the recorder must decline rather than charge
        // the fresh cycle; a value read afterwards could not detect that.
        $failureGeneration = null;

        try {
            $this->connection->beginTransaction();
            $transactionStarted = true;

            // SET LOCAL, before the lock is taken: a concurrent duplicate holding the
            // row is the one arbitrarily long wait, and this bound is what cuts it
            // short as a classifiable 57014 instead of the worker's alarm killing the
            // process mid-transaction. ProjectionConfiguration owns the ordering chain
            // statement_timeout < worker timeout < retry_after.
            $this->connection->statement($this->config->statementTimeoutStatement());

            // A closed gate commits this empty transaction and returns rather than
            // throwing: ProjectPaymentEvent has tries = 1 and no try/catch, so an
            // exception here would reach failed_jobs instead of being redelivered. No
            // row is locked, so no counter or transport column can move. ADR 0005, D5.
            if (! $this->gate->inspectPaymentDetails()->isOpen()) {
                $commitAttempted = true;
                $this->connection->commit();

                return ProjectionOutcome::GateDeferred;
            }

            $row = $this->connection->table('payment_outbox')
                ->where('event_id', $eventId)
                // Never SKIP LOCKED: here a locked row means a concurrent duplicate of
                // THIS event is mid-flight, and skipping would report "nothing to do"
                // for work that has not happened. Waiting serialises the two.
                ->lock('for update')
                ->first();

            if ($row === null) {
                // NOT a commit and not a no-op: there is no row to charge and none
                // may be created to hold failure metadata.
                throw new ProjectionEnvelopeMissing($eventId);
            }

            /** @var array<string, mixed> $envelopeRow */
            $envelopeRow = (array) $row;

            $failureGeneration = $this->generationOf($envelopeRow);

            if ($envelopeRow['exhausted_at'] !== null) {
                // No projection, no counter, no marker: operator retry is the sole
                // reactivation path for an exhausted row. Committed rather than rolled
                // back because nothing was written and the lock should be released.
                $this->logger->info('Payments projection: skipped an exhausted envelope.', [
                    'category' => 'projection_skipped_exhausted',
                    'event_id' => $eventId,
                    'projector_name' => self::PROJECTOR_NAME,
                    'projection_failure_generation' => $failureGeneration,
                ]);

                $commitAttempted = true;
                $this->connection->commit();

                return ProjectionOutcome::SkippedExhausted;
            }

            // Decoded before any write: a refusal must leave the receipt uninserted,
            // or redelivery takes the duplicate path, finds no read row, and a
            // malformed payload is reported as corruption.
            $envelope = PaymentInitiatedEnvelope::fromOutboxRow($envelopeRow);

            $outcome = $this->claim($envelope) === 1
                ? $this->projectFirstDelivery($envelope)
                : $this->reconcileDuplicate($envelope);

            $commitAttempted = true;
            $this->connection->commit();

            return $outcome;
        } catch (Throwable $failure) {
            // Rolled back first, always: metadata recorded inside the failed
            // transaction would be discarded by the rollback.
            $ambiguous = $this->abort($failure, $transactionStarted, $commitAttempted, $eventId, $failureGeneration);

            $this->chargeIfConfirmed($failure, $ambiguous, $eventId, $failureGeneration);

            // Unwrapped, so the incident keeps the only information that explains it.
            throw $failure;
        }
    }

    /**
     * Claim this (projector, event) pair, and say whether the claim was ours.
     *
     * `ON CONFLICT DO NOTHING` rather than read-then-write: two concurrent deliveries
     * can both read "no receipt" before either inserts. PostgreSQL arbitrates and the
     * loser learns it lost from an affected-row count of zero, not an exception.
     */
    private function claim(PaymentInitiatedEnvelope $envelope): int
    {
        return $this->connection->affectingStatement(
            <<<'SQL'
            insert into payment_projector_receipts (projector_name, event_id, created_at)
            values (?, ?, now())
            on conflict (projector_name, event_id) do nothing
            SQL,
            [self::PROJECTOR_NAME, $envelope->eventId],
        );
    }

    /**
     * First delivery: the read row and the completion marker, in this transaction.
     *
     * No upsert, deliberately: `payment_id` and `source_event_id` are both unique, so
     * a disagreeing event raises 23505 and rolls the transaction back. An upsert would
     * silently overwrite a projected payment with contradictory history.
     *
     * `initiated_at` comes from the envelope, every other timestamp from now(), so a
     * rebuild reproduces the read row byte for byte.
     */
    private function projectFirstDelivery(PaymentInitiatedEnvelope $envelope): ProjectionOutcome
    {
        $this->connection->table('payment_details')->insert([
            'payment_id' => $envelope->paymentId,
            'source_event_id' => $envelope->eventId,
            'merchant_id' => $envelope->merchantId,
            'amount_minor' => $envelope->amountMinor,
            'currency' => $envelope->currency,
            'status' => self::INITIATED_STATUS,
            // Retained so a later lifecycle event can detect out-of-order delivery
            // without re-reading the outbox.
            'aggregate_version' => $envelope->aggregateVersion,
            // Never bound as a DateTimeInterface. Measured: prepareBindings() formats
            // it with the PostgreSQL grammar's 'Y-m-d H:i:s', silently truncating
            // business time to whole seconds in a timestamptz(6) column.
            'initiated_at' => $envelope->occurredAt->format(PaymentInitiated::OCCURRED_AT_FORMAT),
        ]);

        $this->markCompleted($envelope->eventId);

        return ProjectionOutcome::Projected;
    }

    /**
     * Duplicate delivery: verify, repair only a missing marker, write nothing else.
     *
     * Verified by `source_event_id`, not `payment_id`: a row found by payment id could
     * have been written by a different event for the same payment, satisfying the
     * check while proving nothing about this receipt.
     *
     * The only permitted repair is the outbox completion marker, which describes
     * delivery rather than the payment.
     */
    private function reconcileDuplicate(PaymentInitiatedEnvelope $envelope): ProjectionOutcome
    {
        $projectedPaymentId = $this->connection->table('payment_details')
            ->where('source_event_id', $envelope->eventId)
            ->value('payment_id');

        if ($projectedPaymentId === null) {
            throw ProjectionEnvelopeRejected::readRowMissing($envelope->eventId, $envelope->paymentId);
        }

        // Checked, not merely probed for null. Without this, a row carrying this event
        // id but a different payment id would be reported AlreadyProjected and stamped
        // complete, retiring the envelope against work that was never done.
        if ($projectedPaymentId !== $envelope->paymentId) {
            throw ProjectionEnvelopeRejected::readRowMismatch($envelope->eventId, $envelope->paymentId);
        }

        // The statement decides, not a branch on an earlier read: it writes only where
        // the marker is still null, so its affected-row count answers "was there a
        // marker to repair?". That state is what a crash between this transaction's
        // COMMIT and the relay's next read leaves; unrepaired, the row is redelivered
        // on every reconciliation pass forever.
        return $this->markCompleted($envelope->eventId) === 1
            ? ProjectionOutcome::MarkerRepaired
            : ProjectionOutcome::AlreadyProjected;
    }

    /**
     * The completion marker, written only while it is still missing.
     *
     * The `is null` predicate makes the statement idempotent at the database, so no
     * caller can move an existing marker forward.
     */
    private function markCompleted(string $eventId): int
    {
        return $this->connection->update(
            <<<'SQL'
            update payment_outbox
               set projection_completed_at = now(),
                   updated_at              = now()
             where event_id = ?
               and projection_completed_at is null
            SQL,
            [$eventId],
        );
    }

    /**
     * Charge this failure against the envelope's poison budget, if it may be.
     *
     * All three guards refuse the same thing: charge only what is provably
     * attributable to this envelope. An unknown commit outcome writes nothing, because
     * the projection may already be on disk and five such failures would exhaust an
     * envelope whose work is complete. A null generation means the lock never returned
     * a row. A null category is the classification declining - a lock timeout, a lost
     * connection, a serialisation failure.
     */
    private function chargeIfConfirmed(
        Throwable $failure,
        bool $ambiguous,
        string $eventId,
        ?int $failureGeneration,
    ): void {
        if ($ambiguous || $failureGeneration === null) {
            return;
        }

        $category = ProjectionFailureClassification::categoryFor($failure);

        if ($category === null) {
            return;
        }

        $this->recorder->record($eventId, $failureGeneration, $category);
    }

    /**
     * Whether PostgreSQL still has a transaction open on this connection.
     *
     * Wrapped because getPdo() is the call that fails when the connection is what
     * broke, and it may also reconnect. An unanswerable question is `false`: this is
     * only ever used to look for proof that the commit did not land.
     */
    private function serverTransactionOpen(): bool
    {
        try {
            return $this->connection->getPdo()->inTransaction();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The generation fence from the locked row.
     *
     * A type conversion, not validation: the column is NOT NULL with a zero default
     * and a non-negative CHECK, so the throw is unreachable from a row the query above
     * can return.
     *
     * @param  array<string, mixed>  $row
     */
    private function generationOf(array $row): int
    {
        $value = $row['projection_failure_generation'] ?? null;

        if (! is_numeric($value)) {
            throw ProjectionEnvelopeRejected::malformedEnvelope('projection_failure_generation');
        }

        return (int) $value;
    }

    /**
     * Close the transaction, if one is still open, and say what is known about it.
     *
     * The rollback is attempted only while the connection reports an active level;
     * unconditionally it would, after a successful commit, roll back whatever
     * transaction happened to be open next. A cleanup failure never replaces the
     * primary one.
     *
     * Returns whether the outcome is ambiguous, so the caller decides the failure
     * budget from the same value that was logged rather than recomputing it.
     */
    private function abort(
        Throwable $failure,
        bool $transactionStarted,
        bool $commitAttempted,
        string $eventId,
        ?int $failureGeneration,
    ): bool {
        // Asked of the PDO, and before the rollback. Before, because a rollback closes
        // the server transaction and every failure would then look like a commit that
        // may have landed. The PDO rather than transactionLevel() because, measured
        // against the installed framework, Connection::commit() decrements
        // $this->transactions only after getPdo()->commit() succeeds
        // (ManagesTransactions.php:203-219) - so a throwing COMMIT still reports level
        // 1, and a classification built on it would count a non-countable failure and
        // eventually exhaust a row whose projection is already on disk.
        //
        // An open server transaction is the only proof the commit did not land; its
        // absence is not proof of success.
        $serverTransactionOpen = $this->serverTransactionOpen();

        $stillOpen = $transactionStarted
            && ($serverTransactionOpen || $this->connection->transactionLevel() > 0);

        if ($stillOpen) {
            try {
                $this->connection->rollBack();
            } catch (Throwable $cleanup) {
                $this->logger->warning('Payments projection: rollback cleanup failed after a failed delivery.', [
                    'category' => 'projection_rollback_cleanup_failed',
                    'exception_class' => $cleanup::class,
                    'event_id' => $eventId,
                ]);
            }
        }

        $ambiguous = $commitAttempted && ! $serverTransactionOpen;

        // The class and category, never the message: a driver fills the message with
        // server text that can carry a DSN, a credential or an echoed payload.
        $this->logger->error('Payments projection: the delivery failed and was aborted.', [
            'category' => $ambiguous ? 'projection_commit_outcome_unknown' : 'projection_failed_before_commit',
            // Both typed failures, not just the countable one: the missing-envelope
            // category would otherwise log a null where the fact an operator needs is
            // already in hand.
            'failure_category' => match (true) {
                $failure instanceof ProjectionEnvelopeRejected => $failure->category,
                $failure instanceof ProjectionEnvelopeMissing => ProjectionEnvelopeMissing::CATEGORY,
                default => null,
            },
            'exception_class' => $failure::class,
            // The same classifier the write path uses. Database failures are told
            // apart by SQLSTATE, never by exception text, and a class name alone
            // cannot separate 23503 from 40001 from 55P03.
            'sql_state' => RetryingAtomicTransaction::sqlStateOf($failure),
            'event_id' => $eventId,
            'projector_name' => self::PROJECTOR_NAME,
            'projection_failure_generation' => $failureGeneration,
            'transaction_started' => $transactionStarted,
            'commit_attempted' => $commitAttempted,
        ]);

        return $ambiguous;
    }
}
