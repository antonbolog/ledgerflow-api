<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one path that clears `exhausted_at`.
 *
 *     BEGIN
 *     SELECT ... FROM payment_outbox WHERE event_id = ? FOR UPDATE
 *     <refuse unknown / completed / non-exhausted, without mutation>
 *     UPDATE payment_outbox
 *        SET exhausted_at                  = null,
 *            projection_failure_count       = 0,
 *            last_projection_error          = null,
 *            projection_failure_generation  = projection_failure_generation + 1,
 *            available_at                   = now()
 *     COMMIT
 *
 * AN EXHAUSTED ENVELOPE HAS NO OTHER WAY BACK. The consumer treats one as an
 * observability-only no-op and the recorder refuses to touch it, precisely so that
 * reactivation is a deliberate operator act rather than something the system talks
 * itself into. That makes this command the whole of the reactivation surface.
 *
 * A SELECT ... FOR UPDATE FOLLOWED BY AN UPDATE, rather than the recorder's single
 * guarded statement. The difference is what each needs to report: the recorder only
 * has to know whether it may write, while an operator has to be told WHY a retry
 * was refused — unknown, already projected, or never exhausted are three different
 * situations with three different next steps. Holding the row lock across both
 * statements is what makes the reported reason and the write describe the same
 * instant.
 *
 * THE GENERATION IS INCREMENTED, AND THAT IS THE POINT OF THE RESET RATHER THAN A
 * DETAIL OF IT. Zeroing the counter alone would be indistinguishable from a fresh
 * envelope, so a failure recorder still in flight from the exhausted cycle could
 * land afterwards and spend the first fifth of the new budget. The monotonic fence
 * is what lets that straggler recognise itself as stale.
 *
 * available_at = now() IS THE ONE NARROW EXCEPTION to T7's ownership of that
 * column, recorded as such in the specification. A corrected row must become
 * eligible again in the same transaction that corrected it; leaving it to the next
 * relay pass would mean the reset committed and the row still sat idle behind
 * whatever backoff the old cycle had written.
 *
 * PUBLICATION AND TRANSPORT HISTORY ARE PRESERVED. `publish_count`,
 * `last_published_at`, `consecutive_transport_failures` and `last_transport_error`
 * are absent from the UPDATE, so the record of how the envelope was delivered
 * survives the reset of how it was projected. The historical failure itself
 * survives in the structured logs and the retained failed-job records; this
 * command deliberately destroys the poison state and nothing else.
 */
final class PaymentOutboxRetryCommand extends Command
{
    protected $signature = 'payments:outbox:retry {eventId : The payment_outbox event id to reactivate}';

    protected $description = 'Clear the poison state of one exhausted outbox envelope and make it eligible again';

    /** Refusal reasons, as stable strings: they reach the operator and the log. */
    public const string UNKNOWN = 'unknown';

    public const string ALREADY_PROJECTED = 'already_projected';

    public const string NOT_EXHAUSTED = 'not_exhausted';

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $eventId = (string) $this->argument('eventId');

        $transactionStarted = false;

        try {
            $this->connection->beginTransaction();
            $transactionStarted = true;

            $row = $this->connection->table('payment_outbox')
                ->select('projection_completed_at', 'exhausted_at', 'projection_failure_count',
                    'projection_failure_generation')
                ->where('event_id', $eventId)
                // A bare FOR UPDATE. The consumer holds this same row lock for the
                // length of one delivery, so a retry issued mid-delivery WAITS
                // rather than reading state that is about to change underneath it.
                ->lock('for update')
                ->first();

            $refusal = $this->refusalFor($row);

            if ($refusal !== null) {
                // COMMITTED, NOT ROLLED BACK, because nothing was written and the
                // row lock should be released now rather than at the end of a
                // transaction that has no more work to do.
                $this->connection->commit();

                return $this->refuse($eventId, $refusal);
            }

            $generation = $this->reset($eventId);

            $this->connection->commit();

            $this->logger->warning('Payments outbox: an operator reactivated an exhausted envelope.', [
                'category' => 'projection_operator_retry',
                'event_id' => $eventId,
                'projection_failure_generation' => $generation,
            ]);

            $this->line((string) json_encode([
                'reactivated' => true,
                'event_id' => $eventId,
                'projection_failure_generation' => $generation,
            ], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $failure) {
            $this->abort($failure, $transactionStarted, $eventId);

            throw $failure;
        }
    }

    /**
     * Why this envelope may not be retried, or null if it may.
     *
     * ORDER IS THE OPERATOR'S, not the schema's: "there is no such event" has to be
     * distinguished from "that event is already done", and both from "that event is
     * still being retried automatically". Answering only the last would send an
     * operator hunting for a poisoned row that never existed.
     */
    private function refusalFor(?object $row): ?string
    {
        if ($row === null) {
            return self::UNKNOWN;
        }

        /** @var array<string, mixed> $state */
        $state = (array) $row;

        // COMPLETED WINS OVER EXHAUSTED, and the order matters for the message
        // rather than for safety: a row that is both completed and exhausted got
        // there by a duplicate succeeding after exhaustion, and "already projected"
        // is the fact the operator needs.
        if ($state['projection_completed_at'] !== null) {
            return self::ALREADY_PROJECTED;
        }

        if ($state['exhausted_at'] === null) {
            return self::NOT_EXHAUSTED;
        }

        return null;
    }

    /**
     * Clear the poison state and make the row eligible, returning the new generation.
     *
     * RETURNING, so the value reported to the operator and written to the log is the
     * one this statement produced rather than one a second read might find after
     * another writer moved on.
     */
    private function reset(string $eventId): int
    {
        $updated = $this->connection->selectOne(
            <<<'SQL'
            update payment_outbox
               set exhausted_at                  = null,
                   projection_failure_count      = 0,
                   last_projection_error         = null,
                   projection_failure_generation = projection_failure_generation + 1,
                   available_at                  = now(),
                   updated_at                    = now()
             where event_id = ?
            returning projection_failure_generation
            SQL,
            [$eventId],
        );

        /** @var array<string, mixed> $returned */
        $returned = (array) $updated;
        $generation = $returned['projection_failure_generation'] ?? null;

        // A type conversion made honest. The column is NOT NULL with a non-negative
        // CHECK and the statement above just wrote it, so this is unreachable from a
        // row the SELECT could have returned.
        return is_numeric($generation) ? (int) $generation : 0;
    }

    /** Report the refusal identically to the operator and the log, and mutate nothing. */
    private function refuse(string $eventId, string $reason): int
    {
        $this->logger->info('Payments outbox: refused an operator retry.', [
            'category' => 'projection_operator_retry_refused',
            'reason' => $reason,
            'event_id' => $eventId,
        ]);

        $this->line((string) json_encode([
            'reactivated' => false,
            'event_id' => $eventId,
            'reason' => $reason,
        ], JSON_UNESCAPED_SLASHES));

        return self::FAILURE;
    }

    /**
     * Close the transaction if one is still open, without ever speaking over the
     * failure that caused it.
     *
     * NO COMMIT-OUTCOME CLASSIFICATION HERE, unlike the consumer's. This command is
     * an interactive operator action with a human reading its exit code: an
     * ambiguous commit is answered by running `payments:outbox:status` or the
     * command again, not by a durable category that some later automation would
     * have to interpret.
     */
    private function abort(Throwable $failure, bool $transactionStarted, string $eventId): void
    {
        if ($transactionStarted && $this->connection->transactionLevel() > 0) {
            try {
                $this->connection->rollBack();
            } catch (Throwable) {
                // Never let cleanup mask the incident; the log line below still fires.
            }
        }

        $this->logger->error('Payments outbox: an operator retry failed.', [
            'category' => 'projection_operator_retry_failed',
            'exception_class' => $failure::class,
            'event_id' => $eventId,
        ]);
    }
}
