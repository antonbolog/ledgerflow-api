<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Relay;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\Connection;
use PDOException;
use Psr\Log\LoggerInterface;
use RedisException;
use RuntimeException;
use Throwable;

/**
 * One eligible outbox row, one short PostgreSQL transaction, lock through push.
 *
 *     BEGIN
 *     set local statement_timeout
 *     [T11 acquires the shared projection gate HERE, before eligibility selection]
 *     SELECT ... FOR UPDATE SKIP LOCKED LIMIT 1
 *     push(ProjectPaymentEvent(event_id))        <- inside the open transaction
 *     UPDATE transport state
 *     COMMIT
 *
 * PUBLICATION HAPPENS BEFORE THE COMMIT, DELIBERATELY. That ordering is what makes
 * a crash in the window produce one safe duplicate — Redis keeps the message,
 * PostgreSQL rolls the row back to eligible, and a later pass republishes — rather
 * than a lost projection. It depends on `payments-projection` being configured
 * `after_commit => false` AND on ProjectPaymentEvent opting into nothing, because
 * Queue::shouldDispatchAfterCommit() reads the job first (Queue/Queue.php:396-408).
 *
 * NOT Connection::transaction(). That helper's commit-exception path routes into
 * handleCommitTransactionException(), which sets `transactions = 0` and rethrows
 * while rolling the PDO back only for a concurrency error with attempts remaining —
 * so at one attempt every other commit-boundary failure leaves the SERVER
 * transaction open while transactionLevel() reports 0. T6 measured that and had to
 * repair it after the fact. Here the lifecycle is explicit, so the two phases below
 * are known rather than reconstructed.
 *
 * ONE ATTEMPT. The relay does not retry: its retry is the next scheduled pass, one
 * second later, and its backoff is durable in `available_at` rather than held in a
 * loop counter. A second retry policy here would also be a second owner of the
 * SQLSTATE allowlist that RetryingAtomicTransaction owns.
 *
 * ONLY RedisException IS CAUGHT. Everything else — a PostgreSQL failure, a
 * programming error, a serialisation fault, a lost connection — escapes, rolls the
 * row back and aborts the whole pass. Continuing after a database failure is
 * unsafe: Laravel can retain stale transaction bookkeeping after a connection loss,
 * so the next row would be attempted on a connection nobody can describe.
 *
 * That the transport surface is EXACTLY RedisException was re-derived from installed
 * vendor source and measured against the running stack. The full table — including
 * why RedisClusterException (which extends RuntimeException, NOT RedisException)
 * cannot reach here, and the measured proof that a push Redis accepted before
 * throwing yields a duplicate rather than a loss — is in
 * docs/specifications/payments/outbox-delivery.md, "Transport exception
 * classification". Do not widen this catch without re-reading it.
 */
final readonly class OutboxRelay
{
    /** The only value ever written to last_transport_error. See recordRejection(). */
    public const string TRANSPORT_REJECTED = 'redis_transport_rejected';

    public function __construct(
        private Connection $connection,
        private QueueFactory $queue,
        private RelayConfiguration $config,
        private LoggerInterface $logger,
    ) {}

    public function relayOne(): RelayOutcome
    {
        // THE TWO PHASES, AND WHY THEY ARE LOCAL VARIABLES RATHER THAN QUESTIONS
        // ASKED OF THE CONNECTION AFTERWARDS. A failure before BEGIN and a failure
        // after a successful COMMIT leave IDENTICAL end states — transactionLevel()
        // 0 and an idle PDO — so any classification read from the connection would
        // report a durable commit for work that never started, or a rollback for
        // work that is already on disk.
        $transactionStarted = false;
        $commitAttempted = false;

        try {
            $this->connection->beginTransaction();
            $transactionStarted = true;

            // Bounds how long this transaction can hold its row lock if a statement
            // stalls. SET LOCAL, so it dies with the transaction instead of leaking
            // into whatever the connection does next.
            $this->connection->statement('set local statement_timeout = '.$this->config->statementTimeoutMs);

            $row = $this->connection->table('payment_outbox')
                ->select('event_id', 'consecutive_transport_failures')
                ->whereNull('projection_completed_at')
                ->whereNull('exhausted_at')
                // now() is transaction_timestamp(): one instant for eligibility and
                // for every timestamp written below, so the grace and the backoff
                // are arithmetic rather than a race with the wall clock.
                ->whereRaw('available_at <= now()')
                // The same order as payment_outbox_eligible_index, so the partial
                // index serves the hot path rather than merely existing.
                ->oldest('available_at')
                ->orderBy('event_id')
                ->limit(1)
                // Laravel has no skipLocked() helper; PostgresGrammar::compileLock()
                // passes a string through verbatim. SKIP LOCKED, not a bare FOR
                // UPDATE, is what makes overlapping invocations safe without a
                // scheduler mutex: a second relay steps over a locked row instead
                // of queueing behind it until the statement timeout fires.
                ->lock('for update skip locked')
                ->first();

            if ($row === null) {
                $commitAttempted = true;
                $this->connection->commit();

                return RelayOutcome::NothingEligible;
            }

            $eventId = $row->event_id;
            $failures = $row->consecutive_transport_failures;

            // TYPES, NOT VALIDATION. The query builder returns stdClass, so every
            // column is `mixed` to static analysis. Both columns are NOT NULL — a
            // uuid primary key and an integer counter with a zero default — so
            // neither branch is reachable from a row this query can return. They
            // exist so the conversions are honest rather than suppressed, and they
            // refuse loudly rather than relaying an empty identifier or computing a
            // backoff from nothing. UNTESTED BY DESIGN: producing either state would
            // require a schema this migration set cannot create.
            if (! is_string($eventId) || ! is_numeric($failures)) {
                throw new RuntimeException(
                    'payment_outbox returned a row whose event_id or consecutive_transport_failures '
                    .'is not of its declared type. The schema and the query disagree; refusing to relay.'
                );
            }

            try {
                // push(), never pushRaw(): pushRaw() skips enqueueUsing() entirely
                // and is unconditionally immediate, so it could not be held to the
                // after-commit contract even if the connection config were wrong.
                //
                // The connection is resolved INSIDE the try on purpose. When Redis
                // is unreachable, PhpRedisConnector::connect() fails while building
                // the client — before any command is sent — and that is a transport
                // rejection, not a programming failure.
                $this->queue->connection($this->config->queueConnection)->push(
                    new ProjectPaymentEvent($eventId),
                    '',
                    $this->config->queue,
                );
            } catch (RedisException $rejection) {
                $this->recordRejection($eventId, (int) $failures, $rejection);

                $commitAttempted = true;
                $this->connection->commit();

                return RelayOutcome::TransportRejected;
            }

            $this->recordAcceptance($eventId);

            $commitAttempted = true;
            $this->connection->commit();

            return RelayOutcome::Published;
        } catch (Throwable $failure) {
            $this->abort($failure, $transactionStarted, $commitAttempted);

            // THE ORIGINAL EXCEPTION, unwrapped. The caller aborts the pass on it,
            // and replacing it with a relay-specific type would delete the only
            // information that explains what went wrong.
            throw $failure;
        }
    }

    /**
     * Redis accepted the message.
     *
     * Both counters are written as SQL expressions over the row this transaction
     * holds locked, never as values computed in PHP, so the result is correct
     * regardless of what was read. `available_at` moves to now() + grace, which is
     * the whole of T7's reconciliation contract: if T8's consumer has not written
     * projection_completed_at by then, the row re-enters the partial index and is
     * redelivered. T7 needs no knowledge of projector receipts to do that.
     */
    private function recordAcceptance(string $eventId): void
    {
        $this->connection->update(
            <<<'SQL'
            update payment_outbox
               set publish_count                  = publish_count + 1,
                   last_published_at              = now(),
                   consecutive_transport_failures = 0,
                   last_transport_error           = null,
                   available_at                   = now() + (interval '1 second' * ?::int),
                   updated_at                     = now()
             where event_id = ?
            SQL,
            [$this->config->reconciliationGraceSeconds, $eventId],
        );
    }

    /**
     * Redis refused. Retryable transport state, committed.
     *
     * COMMITTED, NOT ROLLED BACK. A refusal from Redis says nothing about
     * PostgreSQL, and discarding this write would lose the backoff and let the row
     * hammer a dead transport once a second for as long as the outage lasted.
     *
     * `last_published_at`, `publish_count` and every column T8 owns are absent from
     * the statement. That absence IS the ownership boundary: an arbitrarily long
     * transport outage produces an arbitrarily large failure count and never
     * approaches exhaustion, because nothing here can write exhausted_at.
     *
     * A FIXED CATEGORY, NEVER THE EXCEPTION MESSAGE. last_transport_error is
     * durable, is read by operators and will be rendered by later tooling; a driver
     * message is written by the transport and can carry a DSN, a password or
     * whatever the server echoed back. The class name goes to the log, where the
     * allowlist is enforced; the message goes nowhere.
     */
    private function recordRejection(string $eventId, int $failuresBefore, RedisException $rejection): void
    {
        // The value the column is ABOUT TO hold: the statement below increments it,
        // and the delay must match the new count, not the old one. Taken from the
        // row this transaction already holds locked, so no second read is needed and
        // nothing can change between the two.
        $delay = $this->config->backoff()->secondsAfter($failuresBefore + 1);

        $this->connection->update(
            <<<'SQL'
            update payment_outbox
               set consecutive_transport_failures = consecutive_transport_failures + 1,
                   last_transport_error           = ?,
                   available_at                   = now() + (interval '1 second' * ?::int),
                   updated_at                     = now()
             where event_id = ?
            SQL,
            [self::TRANSPORT_REJECTED, $delay, $eventId],
        );

        $this->logger->warning('Payments outbox relay: transport rejected the publication.', [
            'category' => self::TRANSPORT_REJECTED,
            'exception_class' => $rejection::class,
            'event_id' => $eventId,
            'backoff_seconds' => $delay,
        ]);
    }

    /**
     * Close the transaction, if one is still open, and say what is known about it.
     *
     * The rollback is attempted ONLY while the connection still reports an active
     * level. Calling it unconditionally would, after a successful commit, roll back
     * whatever transaction happened to be open next.
     *
     * A CLEANUP FAILURE NEVER REPLACES THE PRIMARY ONE. A caller that saw "rollback
     * failed" instead of the exception that caused the rollback has lost the only
     * information that explains the incident.
     */
    private function abort(Throwable $failure, bool $transactionStarted, bool $commitAttempted): void
    {
        $stillOpen = $transactionStarted && $this->connection->transactionLevel() > 0;

        if ($stillOpen) {
            try {
                $this->connection->rollBack();
            } catch (Throwable $cleanup) {
                $this->logger->warning('Payments outbox relay: rollback cleanup failed after a relay failure.', [
                    'category' => 'relay_rollback_cleanup_failed',
                    'exception_class' => $cleanup::class,
                ]);
            }
        }

        // THE CLASSIFICATION, from the phases rather than from the end state.
        //
        // Not yet committing, or committing with the transaction still open: the
        // work is definitely not durable. Commit attempted and no transaction left:
        // Connection::commit() fires `committed` AFTER getPdo()->commit() has
        // already succeeded, so a failure here can sit on either side of a durable
        // write and the relay cannot tell which. It says so instead of guessing —
        // and it must not republish in this pass, because the next scheduled pass
        // reads canonical row state: either the row is unchanged and republishes
        // safely, or the committed grace holds it back.
        $ambiguous = $commitAttempted && ! $stillOpen;

        $this->logger->error('Payments outbox relay: the pass failed and was aborted.', [
            'category' => $ambiguous ? 'commit_outcome_unknown' : 'relay_failed_before_commit',
            'exception_class' => $failure::class,
            'sql_state' => self::sqlStateOf($failure),
            'transaction_started' => $transactionStarted,
            'commit_attempted' => $commitAttempted,
        ]);
    }

    /**
     * The driver's SQLSTATE, when the failure carries one.
     *
     * Read from `errorInfo` only, and only when it is a five-character string. The
     * exception MESSAGE is never touched: it is the one field a driver fills with
     * arbitrary server text, and this value goes into a structured log field that
     * is expected to be safe.
     */
    private static function sqlStateOf(Throwable $failure): ?string
    {
        // The exception itself first: QueryException extends PDOException and carries
        // the driver's errorInfo directly. The previous one second, for the wrappers
        // that construct from (message, code, previous) and leave errorInfo empty —
        // the shape T3 measured on DeadlockException.
        foreach ([$failure, $failure->getPrevious()] as $candidate) {
            if (! $candidate instanceof PDOException || ! is_array($candidate->errorInfo)) {
                continue;
            }

            $state = $candidate->errorInfo[0] ?? null;

            if (is_string($state) && strlen($state) === 5) {
                return $state;
            }
        }

        return null;
    }
}
