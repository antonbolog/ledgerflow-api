<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

use Illuminate\Database\Connection;
use Psr\Log\LoggerInterface;
use Throwable;

// Called only after the projection transaction rolled back. One guarded UPDATE writes the count and exhaustion
// together, so concurrent recorders cannot race the threshold; the generation guard rejects stale cycles.
final readonly class ProjectionFailureRecorder
{
    public const int POISON_THRESHOLD = 5;

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param  int  $failureGeneration  the value read under the projection transaction's
     *                                  row lock, never re-read afterwards
     */
    public function record(string $eventId, int $failureGeneration, string $category): void
    {
        $transactionStarted = false;

        try {
            $this->connection->beginTransaction();
            $transactionStarted = true;

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

    private static function integerOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    // Swallowed, not rethrown: the caller rethrows the original projection failure, and the row stays retryable.
    private function abort(Throwable $failure, bool $transactionStarted, string $eventId, int $generation): void
    {
        if ($transactionStarted && $this->connection->transactionLevel() > 0) {
            try {
                $this->connection->rollBack();
            } catch (Throwable) {
                // Never let cleanup mask the incident; the log line below still fires.
            }
        }

        $this->logger->error('Payments projection: could not record the delivery failure.', [
            'category' => 'projection_failure_not_recorded',
            'exception_class' => $failure::class,
            'event_id' => $eventId,
            'projection_failure_generation' => $generation,
        ]);
    }
}
