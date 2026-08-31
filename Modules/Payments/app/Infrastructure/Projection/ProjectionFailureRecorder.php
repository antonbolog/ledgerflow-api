<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

use Illuminate\Database\Connection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one writer of `projection_failure_count`, `last_projection_error` and
 * `exhausted_at`.
 *
 *     BEGIN
 *     UPDATE payment_outbox
 *        SET projection_failure_count = projection_failure_count + 1,
 *            last_projection_error    = <fixed category>,
 *            exhausted_at             = <now() on the fifth, else null>
 *      WHERE event_id = ?
 *        AND projection_completed_at IS NULL
 *        AND exhausted_at IS NULL
 *        AND projection_failure_generation = <captured under the projection lock>
 *     RETURNING ...
 *     COMMIT
 *
 * A SEPARATE, SHORT TRANSACTION, OPENED ONLY AFTER THE PROJECTION TRANSACTION HAS
 * ROLLED BACK. It cannot be the same one: the projection transaction is being
 * discarded precisely because it failed, so anything written inside it would be
 * discarded too. That ordering is the consumer's to enforce, and this class is
 * only ever called once it has.
 *
 * EXHAUSTION IS SET BY THE SAME STATEMENT THAT RAISES THE COUNT TO FIVE. Splitting
 * them across two statements — or two owners — makes the threshold check a race:
 * two recorders could each read four, each write five, and neither would be the
 * one that crossed it.
 *
 * FOUR GUARDS IN ONE WHERE CLAUSE, NOT A SELECT FOLLOWED BY AN UPDATE. The UPDATE
 * takes the row lock itself and re-evaluates every predicate against the committed
 * tuple, so there is no window between checking and writing. Each guard refuses a
 * specific way this recorder can arrive too late:
 *
 * - the row is gone: nothing to charge;
 * - `projection_completed_at` is set: a concurrent duplicate delivery SUCCEEDED, so
 *   charging would poison a row whose work is done;
 * - `exhausted_at` is set: the budget is already spent and only operator retry may
 *   reopen it, so a sixth failure must not overwrite the record of the fifth;
 * - the generation moved: an operator retry has started a NEW cycle, and a failure
 *   from the old one must not consume the fresh budget.
 *
 * The last is the fence, and it is what makes this safe against ABA: a count of
 * zero after a retry looks exactly like a count of zero before the first failure,
 * and only the monotonic generation tells them apart.
 *
 * IT NEVER TOUCHES TRANSPORT STATE. `publish_count`,
 * `consecutive_transport_failures`, `last_transport_error`, `last_published_at`
 * and `available_at` are the relay's, and their absence from the statement IS the
 * ownership boundary — the same way their presence is in the relay's own writer.
 *
 * IF THIS TRANSACTION ITSELF FAILS, the row stays incomplete and non-exhausted, so
 * the envelope remains retryable and is redelivered rather than falsely counted.
 * The failure is logged and swallowed HERE so the caller can rethrow the ORIGINAL
 * exception: replacing a projection failure with "could not record the projection
 * failure" would delete the only information that explains the incident.
 */
final readonly class ProjectionFailureRecorder
{
    /**
     * Confirmed failures an envelope may accumulate before it is exhausted.
     *
     * The FIFTH sets `exhausted_at` in the same statement that writes the count, so
     * the envelope leaves the relay's partial index and only
     * `payments:outbox:retry` can bring it back.
     */
    public const int POISON_THRESHOLD = 5;

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {}

    /**
     * Charge one confirmed deterministic failure against this envelope.
     *
     * @param  int  $failureGeneration  the value read under the projection transaction's
     *                                  row lock, never re-read afterwards
     */
    public function record(string $eventId, int $failureGeneration, string $category): void
    {
        $transactionStarted = false;

        try {
            $this->connection->beginTransaction();
            $transactionStarted = true;

            // RETURNING, so the outcome is read from the row the statement actually
            // wrote rather than from a second query that would see a later state.
            $updated = $this->connection->selectOne(
                <<<'SQL'
                update payment_outbox
                   set projection_failure_count = projection_failure_count + 1,
                       last_projection_error    = ?,
                       exhausted_at             = case
                                                    when projection_failure_count + 1 >= ?
                                                    then now()
                                                  end,
                       updated_at               = now()
                 where event_id                     = ?
                   and projection_completed_at is null
                   and exhausted_at            is null
                   and projection_failure_generation = ?
                returning projection_failure_count, exhausted_at
                SQL,
                [$category, self::POISON_THRESHOLD, $eventId, $failureGeneration],
            );

            if ($updated === null) {
                $this->logStale($eventId, $failureGeneration, $category);

                $this->connection->commit();

                return;
            }

            // RETURNING gives back a stdClass, so both columns are `mixed` to static
            // analysis. Both are written by the statement above from NOT NULL
            // columns, so these conversions are honest rather than defensive.
            $returned = (array) $updated;
            $count = self::integerOf($returned['projection_failure_count'] ?? null);
            $exhausted = ($returned['exhausted_at'] ?? null) !== null;

            $this->connection->commit();

            $this->logger->warning('Payments projection: recorded a confirmed delivery failure.', [
                'category' => $exhausted ? 'projection_exhausted' : 'projection_failure_recorded',
                'failure_category' => $category,
                'event_id' => $eventId,
                'projection_failure_count' => $count,
                'projection_failure_generation' => $failureGeneration,
            ]);
        } catch (Throwable $failure) {
            $this->abort($failure, $transactionStarted, $eventId, $failureGeneration);
        }
    }

    /**
     * The row moved on before this recorder reached it.
     *
     * READ INSIDE THE SAME TRANSACTION, so the reason reported is the state the
     * guarded UPDATE actually saw. It is observability only — nothing is written on
     * this path, which is the entire point of it.
     */
    private function logStale(string $eventId, int $failureGeneration, string $category): void
    {
        $row = $this->connection->table('payment_outbox')
            ->select('projection_completed_at', 'exhausted_at', 'projection_failure_generation')
            ->where('event_id', $eventId)
            ->first();

        /** @var array<string, mixed> $state */
        $state = $row === null ? [] : (array) $row;

        $reason = match (true) {
            $row === null => 'envelope_absent',
            $state['projection_completed_at'] !== null => 'already_projected',
            $state['exhausted_at'] !== null => 'already_exhausted',
            self::integerOf($state['projection_failure_generation'] ?? null) !== $failureGeneration => 'generation_advanced',
            // Unreachable while this runs under the guarded UPDATE's own lock, and
            // reported rather than assumed away.
            default => 'unknown',
        };

        $this->logger->info('Payments projection: declined to record a stale delivery failure.', [
            'category' => 'projection_failure_stale',
            'reason' => $reason,
            'failure_category' => $category,
            'event_id' => $eventId,
            'projection_failure_generation' => $failureGeneration,
        ]);
    }

    /**
     * A counter column read back as an int, without casting `mixed`.
     *
     * PostgreSQL integers arrive as strings or ints through PDO depending on the
     * driver's emulation setting, and static analysis sees only `mixed`. Every
     * column read this way is NOT NULL with a zero default, so the fallback is
     * unreachable from a row these statements can return — it exists so the
     * conversion is honest rather than suppressed.
     */
    private static function integerOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Give up on the metadata without ever speaking over the failure that caused it.
     *
     * SWALLOWED, NOT RETHROWN. The caller is already holding the projection failure
     * that brought it here and must rethrow THAT: an operator who saw "could not
     * record the failure" instead of the failure has lost the incident. The row is
     * left incomplete and non-exhausted, so the envelope stays retryable and the
     * next delivery reaches the same conclusion or a better one.
     */
    private function abort(Throwable $failure, bool $transactionStarted, string $eventId, int $generation): void
    {
        if ($transactionStarted && $this->connection->transactionLevel() > 0) {
            try {
                $this->connection->rollBack();
            } catch (Throwable) {
                // Never let cleanup mask the incident; the log line below still fires.
            }
        }

        // THE CLASS, NOT THE MESSAGE, for the reason every other log in this module
        // gives: a driver fills the message with arbitrary server text.
        $this->logger->error('Payments projection: could not record the delivery failure.', [
            'category' => 'projection_failure_not_recorded',
            'exception_class' => $failure::class,
            'event_id' => $eventId,
            'projection_failure_generation' => $generation,
        ]);
    }
}
