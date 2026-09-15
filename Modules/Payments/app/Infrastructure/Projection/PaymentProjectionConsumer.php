<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

use Illuminate\Database\Connection;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Psr\Log\LoggerInterface;
use Throwable;

// Owns its transaction explicitly: Connection::transaction() can leave the server transaction
// open after a failed commit. Retries belong to outbox redelivery, not to this class.
final readonly class PaymentProjectionConsumer
{
    // Part of the receipt primary key: renaming it re-projects every delivered event.
    public const string PROJECTOR_NAME = 'payment_details';

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
        // Phase flags, not connection state: failing before BEGIN and after COMMIT look identical.
        $transactionStarted = false;
        $commitAttempted = false;

        // Captured under the row lock, so the recorder can detect an operator retry in between.
        $failureGeneration = null;

        try {
            $this->connection->beginTransaction();
            $transactionStarted = true;

            // Before the row lock: waiting on a duplicate must end as a classifiable 57014, not a worker kill.
            $this->connection->statement($this->config->statementTimeoutStatement());

            // A closed gate commits and returns: with tries = 1, an exception would reach failed_jobs (ADR 0005).
            if (! $this->gate->inspectPaymentDetails()->isOpen()) {
                $commitAttempted = true;
                $this->connection->commit();

                return ProjectionOutcome::GateDeferred;
            }

            $row = $this->connection->table('payment_outbox')
                ->where('event_id', $eventId)
                // Never SKIP LOCKED: a locked row is a duplicate delivery of this event still in flight.
                ->lock('for update')
                ->first();

            if ($row === null) {
                throw new ProjectionEnvelopeMissing($eventId);
            }

            /** @var array<string, mixed> $envelopeRow */
            $envelopeRow = (array) $row;

            $failureGeneration = $this->generationOf($envelopeRow);

            if ($envelopeRow['exhausted_at'] !== null) {
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

            // Decode before any write, so a refusal leaves no receipt behind.
            $envelope = PaymentInitiatedEnvelope::fromOutboxRow($envelopeRow);

            $outcome = $this->claim($envelope) === 1
                ? $this->projectFirstDelivery($envelope)
                : $this->reconcileDuplicate($envelope);

            $commitAttempted = true;
            $this->connection->commit();

            return $outcome;
        } catch (Throwable $failure) {
            // Roll back before charging: metadata written inside the failed transaction would be lost.
            $ambiguous = $this->abort($failure, $transactionStarted, $commitAttempted, $eventId, $failureGeneration);

            $this->chargeIfConfirmed($failure, $ambiguous, $eventId, $failureGeneration);

            throw $failure;
        }
    }

    // The receipt insert is the idempotency boundary; a read-then-write would let concurrent deliveries both proceed.
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

    // No upsert: the unique payment_id and source_event_id keys must reject contradictory history.
    private function projectFirstDelivery(PaymentInitiatedEnvelope $envelope): ProjectionOutcome
    {
        $this->connection->table('payment_details')->insert([
            'payment_id' => $envelope->paymentId,
            'source_event_id' => $envelope->eventId,
            'merchant_id' => $envelope->merchantId,
            'amount_minor' => $envelope->amountMinor,
            'currency' => $envelope->currency,
            'status' => self::INITIATED_STATUS,
            'aggregate_version' => $envelope->aggregateVersion,
            // Never bind a DateTimeInterface: the grammar's binding format drops microseconds.
            'initiated_at' => $envelope->occurredAt->format(PaymentInitiated::OCCURRED_AT_FORMAT),
        ]);

        $this->markCompleted($envelope->eventId);

        return ProjectionOutcome::Projected;
    }

    // Verify by source_event_id; the only repair a duplicate may make is a missing completion marker.
    private function reconcileDuplicate(PaymentInitiatedEnvelope $envelope): ProjectionOutcome
    {
        $projectedPaymentId = $this->connection->table('payment_details')
            ->where('source_event_id', $envelope->eventId)
            ->value('payment_id');

        if ($projectedPaymentId === null) {
            throw ProjectionEnvelopeRejected::readRowMissing($envelope->eventId, $envelope->paymentId);
        }

        if ($projectedPaymentId !== $envelope->paymentId) {
            throw ProjectionEnvelopeRejected::readRowMismatch($envelope->eventId, $envelope->paymentId);
        }

        return $this->markCompleted($envelope->eventId) === 1
            ? ProjectionOutcome::MarkerRepaired
            : ProjectionOutcome::AlreadyProjected;
    }

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

    // Charge only failures provably attributable to this envelope: an unknown commit may already be durable.
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

    // Unanswerable means false: callers only look for proof that a commit did not land.
    private function serverTransactionOpen(): bool
    {
        try {
            return $this->connection->getPdo()->inTransaction();
        } catch (Throwable) {
            return false;
        }
    }

    /**
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

    private function abort(
        Throwable $failure,
        bool $transactionStarted,
        bool $commitAttempted,
        string $eventId,
        ?int $failureGeneration,
    ): bool {
        // Ask PDO before rolling back: transactionLevel() still reports 1 after a throwing COMMIT.
        // An open server transaction is the only proof the commit did not land.
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

        // Never log the exception message: driver text can carry a DSN, a credential or payload values.
        $this->logger->error('Payments projection: the delivery failed and was aborted.', [
            'category' => $ambiguous ? 'projection_commit_outcome_unknown' : 'projection_failed_before_commit',
            'failure_category' => match (true) {
                $failure instanceof ProjectionEnvelopeRejected => $failure->category,
                $failure instanceof ProjectionEnvelopeMissing => ProjectionEnvelopeMissing::CATEGORY,
                default => null,
            },
            'exception_class' => $failure::class,
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
