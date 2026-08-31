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
 *     [T11 acquires the shared projection gate HERE, before the envelope lock]
 *     SELECT ... FROM payment_outbox WHERE event_id = ? FOR UPDATE
 *     INSERT INTO payment_projector_receipts ... ON CONFLICT DO NOTHING
 *     INSERT INTO payment_details ...            <- first delivery only
 *     UPDATE payment_outbox SET projection_completed_at = now()
 *     COMMIT
 *
 * THE TRANSACTION OPENS ON THE ENVELOPE ROW LOCK, NOT ON A GATE, and that absence
 * is deliberate. `payment_projection_controls`, its advisory-key derivation and
 * consumer deferral are T11's; a provisional key or control table added here would
 * have to be unpicked. T11 PREPENDS its gate to this same transaction, on this same
 * connection, before the lock below. See ADR 0004.
 *
 * THE RECEIPT IS THE IDEMPOTENCY BOUNDARY, not the read row and not the marker.
 * `INSERT ... ON CONFLICT DO NOTHING` returns 1 for a first delivery and 0 for a
 * duplicate, and that single number is what the two paths branch on. Asking
 * "does a payment_details row already exist?" instead would be a different question
 * with a different answer under a concurrent duplicate, because two transactions
 * can both read "no" before either writes.
 *
 * ONE APPLICATION ATTEMPT. RetryingAtomicTransaction is deliberately NOT reused: it
 * belongs to the write path, which owns an idempotency receipt and can safely
 * rebuild an aggregate per attempt. Here recovery is owned by redelivery and by the
 * relay's reconciliation grace, and a second retry policy would be a second owner
 * of the SQLSTATE allowlist.
 *
 * NOT Connection::transaction(), for the reason OutboxRelay records: that helper's
 * commit-exception path can leave the SERVER transaction open while
 * transactionLevel() reports 0. The lifecycle here is explicit so the two phases
 * below are known rather than reconstructed from an end state that cannot
 * distinguish "never started" from "already durable".
 *
 * NO STATEMENT TIMEOUT YET, deliberately. The 2-second projection-local bound and
 * the ordering rule that keeps it below Horizon's worker timeout and the queue's
 * `retry_after` arrive with the Horizon configuration that owns all three; a value
 * hardcoded here would become a second owner of it. Until then the only unbounded
 * wait is the envelope lock, which a concurrent duplicate holds for the length of
 * one short transaction.
 *
 * WHAT THIS CLASS DOES NOT DO: it does not count failures, write
 * `last_projection_error`, or set `exhausted_at`. A deterministic refusal escapes
 * as ProjectionEnvelopeRejected and the separate failure-metadata transaction is
 * the recorder's, in its own slice. The generation captured under the lock below is
 * logged now and is what that recorder will be handed.
 */
final readonly class PaymentProjectionConsumer
{
    /**
     * The projector this consumer is, as stored in every receipt it writes.
     *
     * PART OF THE PRIMARY KEY, so it is a durable identifier rather than a label:
     * changing it makes every existing receipt invisible and every delivered event
     * project a second time. It names the read model this projector maintains.
     */
    public const string PROJECTOR_NAME = 'payment_details';

    /** The only first-slice lifecycle value `payment_details.status` takes. */
    public const string INITIATED_STATUS = 'initiated';

    public function __construct(
        private Connection $connection,
        private ProjectionFailureRecorder $recorder,
        private LoggerInterface $logger,
    ) {}

    public function project(string $eventId): ProjectionOutcome
    {
        // THE TWO PHASES, AS LOCALS RATHER THAN QUESTIONS ASKED OF THE CONNECTION
        // AFTERWARDS. A failure before BEGIN and a failure after a successful COMMIT
        // leave identical end states — transactionLevel() 0 and an idle PDO — so a
        // classification read from the connection would report a durable projection
        // for work that never started, or a rollback for work already on disk.
        $transactionStarted = false;
        $commitAttempted = false;

        // CAPTURED UNDER THE LOCK, and null until then. The failure recorder may
        // only write metadata while the row is still on this generation: an operator
        // retry that ran in between increments it, and a recorder carrying the old
        // value must then decline rather than charge the fresh cycle. Reading it
        // afterwards would read whatever the row holds by then, which is precisely
        // the value that cannot be trusted.
        $failureGeneration = null;

        try {
            $this->connection->beginTransaction();
            $transactionStarted = true;

            $row = $this->connection->table('payment_outbox')
                ->where('event_id', $eventId)
                // A bare FOR UPDATE, never SKIP LOCKED. The relay skips a locked row
                // because another relay is already handling it; here a locked row
                // means a concurrent duplicate of THIS event is mid-flight, and
                // skipping would return "nothing to do" for work that has not
                // happened yet. Waiting is what serialises the two deliveries.
                ->lock('for update')
                ->first();

            if ($row === null) {
                // NOT a commit and not a no-op: there is no row to charge and none
                // may be created to hold failure metadata.
                throw new ProjectionEnvelopeMissing($eventId);
            }

            // The builder returns stdClass, so every column is `mixed` from here on.
            // Casting once and annotating the shape is what lets the envelope read
            // columns by name and refuse them honestly, rather than each read being
            // an unchecked property access.
            /** @var array<string, mixed> $envelopeRow */
            $envelopeRow = (array) $row;

            $failureGeneration = $this->generationOf($envelopeRow);

            if ($envelopeRow['exhausted_at'] !== null) {
                // OBSERVABILITY ONLY. No projection, no counter, no marker: an
                // exhausted row is out of the delivery budget and operator retry is
                // the sole reactivation path. Committing rather than rolling back
                // because nothing was written and the lock should be released now.
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

            // DECODED BEFORE ANY WRITE. A refusal here must leave the receipt
            // uninserted, or a redelivery would take the duplicate path and find no
            // read row — turning a malformed payload into reported corruption.
            $envelope = PaymentInitiatedEnvelope::fromOutboxRow($envelopeRow);

            $outcome = $this->claim($envelope) === 1
                ? $this->projectFirstDelivery($envelope)
                : $this->reconcileDuplicate($envelope);

            $commitAttempted = true;
            $this->connection->commit();

            return $outcome;
        } catch (Throwable $failure) {
            // ROLLED BACK FIRST, ALWAYS. abort() closes the projection transaction
            // and returns what is known about it; only then may a second, separate
            // transaction be opened for the metadata. Recording inside the failed
            // transaction would write metadata that the rollback then discards.
            $ambiguous = $this->abort($failure, $transactionStarted, $commitAttempted, $eventId, $failureGeneration);

            $this->chargeIfConfirmed($failure, $ambiguous, $eventId, $failureGeneration);

            // THE ORIGINAL EXCEPTION, unwrapped, for the reason the relay records:
            // replacing it would delete the only information that explains the
            // incident — including when the recorder above could not persist.
            throw $failure;
        }
    }

    /**
     * Claim this (projector, event) pair, and say whether the claim was ours.
     *
     * `ON CONFLICT DO NOTHING` rather than a read-then-write: two concurrent
     * deliveries can both read "no receipt" before either inserts, so a check-first
     * version would let both proceed to insert `payment_details` and one would die
     * on its primary key. Here PostgreSQL arbitrates, and the LOSER is told it lost
     * by an affected-row count of zero rather than by an exception.
     *
     * now(), not a PHP clock: `created_at` is an operational timestamp saying when
     * this projector consumed the event, and transaction_timestamp() ties it to the
     * transaction that did.
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
     * NO UPSERT, AND THAT IS THE CONTRACT. `payment_id` and `source_event_id` are
     * both unique, so a second, disagreeing event claiming either one raises 23505
     * and the whole transaction rolls back. An upsert would silently overwrite a
     * projected payment with contradictory history, which is the one outcome an
     * event-sourced read model must never produce.
     *
     * `initiated_at` COMES FROM THE ENVELOPE, every other timestamp from now(). The
     * read row must be a function of the event alone, so that a rebuild reproduces
     * it byte for byte; processing time belongs only on the operational marker.
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
            // FORMATTED EXPLICITLY, NEVER BOUND AS A DateTimeInterface. MEASURED:
            // Connection::prepareBindings() converts a DateTimeInterface with
            // $grammar->getDateFormat(), which is 'Y-m-d H:i:s' for PostgreSQL — no
            // sub-second component at all. Binding the object silently TRUNCATED
            // business time to whole seconds into a timestamptz(6) column, and the
            // only symptom was a read row that looked entirely plausible. The
            // durable format carries microseconds and the offset.
            'initiated_at' => $envelope->occurredAt->format(PaymentInitiated::OCCURRED_AT_FORMAT),
        ]);

        $this->markCompleted($envelope->eventId);

        return ProjectionOutcome::Projected;
    }

    /**
     * Duplicate delivery: verify, repair only a missing marker, write nothing else.
     *
     * THE READ ROW IS VERIFIED BY source_event_id, not by payment_id. The receipt
     * says THIS EVENT was projected, and the row it wrote is the one whose
     * `source_event_id` is this event; a row found by payment id could have been
     * written by a different event for the same payment, and would satisfy the check
     * while proving nothing about this receipt.
     *
     * BUSINESS FIELDS ARE NEVER TOUCHED — there is no statement here that could,
     * and `payment_details` deliberately carries no mutable column for one to write
     * to. The only permitted repair is the outbox completion marker, which lives on
     * a different table and describes delivery rather than the payment.
     */
    private function reconcileDuplicate(PaymentInitiatedEnvelope $envelope): ProjectionOutcome
    {
        $projectedPaymentId = $this->connection->table('payment_details')
            ->where('source_event_id', $envelope->eventId)
            ->value('payment_id');

        if ($projectedPaymentId === null) {
            throw ProjectionEnvelopeRejected::readRowMissing($envelope->eventId, $envelope->paymentId);
        }

        // THE VALUE IS CHECKED, NOT MERELY PROBED FOR NULL. A read row carrying this
        // event id but a DIFFERENT payment id is reachable by exactly the routes the
        // missing-row case is — an external repair, a partial rebuild — and it is
        // strictly worse: without this the delivery would report AlreadyProjected
        // and stamp the completion marker over a row describing a different payment,
        // permanently retiring the envelope against work that was never done.
        if ($projectedPaymentId !== $envelope->paymentId) {
            throw ProjectionEnvelopeRejected::readRowMismatch($envelope->eventId, $envelope->paymentId);
        }

        // THE MARKER STATEMENT DECIDES, rather than a branch on the value read
        // earlier. It writes only where the marker is still null, so its affected-row
        // count IS the answer to "was there a marker to repair?" — one statement
        // instead of a read plus a conditional write, and the `is null` predicate
        // becomes load-bearing rather than defensive.
        //
        // THE ONE STATE A DUPLICATE MAY REPAIR: receipt and read row committed, and
        // the marker missing. It is not hypothetical — it is what a crash between
        // this transaction's COMMIT and the relay's next read would leave, and
        // without the repair the row stays eligible forever and is redelivered on
        // every reconciliation pass.
        return $this->markCompleted($envelope->eventId) === 1
            ? ProjectionOutcome::MarkerRepaired
            : ProjectionOutcome::AlreadyProjected;
    }

    /**
     * The completion marker, written only while it is still missing.
     *
     * The `is null` predicate makes the statement idempotent AT THE DATABASE, so no
     * caller can move an existing marker forward and rewrite when the projection
     * actually completed. It returns the affected-row count because the duplicate
     * path reads that count as its outcome.
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
     * THREE CONDITIONS, AND ALL THREE ARE REFUSALS OF THE SAME KIND: charge only
     * what is provably attributable to this envelope.
     *
     * AN UNKNOWN COMMIT OUTCOME WRITES NOTHING AT ALL. The projection may already
     * be on disk; counting it would poison a row that succeeded, and five such
     * failures would exhaust an envelope whose work is complete. The next delivery
     * rereads canonical receipt, read-row and marker state and decides — which it
     * can do correctly, and this cannot.
     *
     * A NULL GENERATION means the lock never returned a row, so there is no
     * envelope to charge and no generation to charge it on. In practice that is the
     * missing-envelope case, which the classification also refuses by type; both
     * guards exist because they are true for different reasons.
     *
     * A NULL CATEGORY is the classification declining, which is the ordinary
     * non-countable path: a lock timeout, a lost connection, a serialisation
     * failure.
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
     * Wrapped, because getPdo() is exactly the call that fails when the connection
     * is the thing that broke — and it may also RECONNECT, which correctly reports
     * no open transaction because the new backend has none. Either way an
     * unanswerable question is `false`: this method is only ever used to look for
     * PROOF that the commit did not land, and the absence of proof is what "unknown"
     * is made of.
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
     * A type conversion made honest, not validation: the column is NOT NULL with a
     * zero default and a non-negative CHECK, so this is unreachable from a row the
     * query above can return.
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
     * The rollback is attempted ONLY while the connection still reports an active
     * level; calling it unconditionally would, after a successful commit, roll back
     * whatever transaction happened to be open next. A cleanup failure never
     * replaces the primary one.
     *
     * THE CLASSIFICATION COMES FROM THE PHASES, and it is what decides whether a
     * failure may be counted at all. Connection::commit() fires `committed` AFTER
     * getPdo()->commit() has already succeeded, so a failure with the commit
     * attempted and no transaction left can sit on either side of a durable write.
     * The consumer says so instead of guessing — and an unknown COMMIT outcome must
     * write NO failure metadata, because the projection may already be on disk and
     * charging it would poison a row that succeeded. The next delivery rereads
     * canonical receipt, read-row and marker state and decides.
     *
     * RETURNS whether the outcome is ambiguous, so the caller decides about the
     * failure budget from the same value that was logged. Recomputing it there
     * would be a second copy of this reasoning, free to drift from this one.
     */
    private function abort(
        Throwable $failure,
        bool $transactionStarted,
        bool $commitAttempted,
        string $eventId,
        ?int $failureGeneration,
    ): bool {
        // ASKED OF THE PDO, AND ASKED BEFORE THE ROLLBACK. Both halves matter.
        //
        // BEFORE, because a successful rollback closes the server transaction and
        // every failure would then look identical to a commit that may have landed.
        //
        // THE PDO RATHER THAN transactionLevel(), because MEASURED against the
        // installed framework the two disagree exactly where it counts:
        // Connection::commit() calls getPdo()->commit() and only THEN decrements
        // $this->transactions (ManagesTransactions.php:203-219). So when the COMMIT
        // itself throws — a connection lost while the client was reading the
        // response — transactionLevel() still reports 1. A classification built on
        // it alone calls that "failed before commit", which is a COUNTABLE
        // deterministic outcome, for the one condition the specification names as
        // explicitly non-countable and requires to write no failure metadata at all.
        // The recorder would then charge, and eventually exhaust, a row whose
        // projection is already on disk.
        //
        // An OPEN SERVER TRANSACTION is the only thing that PROVES the commit did
        // not land. Its absence after an attempted commit is not proof of success —
        // it is the absence of proof either way, which is what "unknown" means.
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

        // THE EXCEPTION CLASS AND ITS CATEGORY, NEVER ITS MESSAGE. A driver fills
        // the message with arbitrary server text, which can carry a DSN, a
        // credential or an echoed payload; the class name and the module's own fixed
        // category are safe by construction.
        $this->logger->error('Payments projection: the delivery failed and was aborted.', [
            'category' => $ambiguous ? 'projection_commit_outcome_unknown' : 'projection_failed_before_commit',
            // BOTH typed failures, not just the countable one. The missing-envelope
            // category was declared and never read, so its abort line logged a null
            // where the one fact an operator needs — that PostgreSQL does not know
            // this event id — was already in hand.
            'failure_category' => match (true) {
                $failure instanceof ProjectionEnvelopeRejected => $failure->category,
                $failure instanceof ProjectionEnvelopeMissing => ProjectionEnvelopeMissing::CATEGORY,
                default => null,
            },
            'exception_class' => $failure::class,
            // THE SQLSTATE, read with the same classifier the write path uses. The
            // classification rules require database failures to be told apart by
            // SQLSTATE and owned constraint identity rather than by exception text,
            // so an operator triaging this line must be able to distinguish 23503
            // from 40001 from 55P03 — a class name alone cannot.
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
