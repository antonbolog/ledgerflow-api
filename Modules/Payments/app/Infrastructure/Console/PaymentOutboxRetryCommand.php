<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Psr\Log\LoggerInterface;
use Throwable;

final class PaymentOutboxRetryCommand extends Command
{
    protected $signature = 'payments:outbox:retry {eventId : The payment_outbox event id to reactivate}';

    protected $description = 'Clear the poison state of one exhausted outbox envelope and make it eligible again';

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
                // Bare FOR UPDATE: wait for an in-flight delivery instead of reading state it is changing.
                ->lock('for update')
                ->first();

            $refusal = $this->refusalFor($row);

            if ($refusal !== null) {
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

    private function refusalFor(?object $row): ?string
    {
        if ($row === null) {
            return self::UNKNOWN;
        }

        /** @var array<string, mixed> $state */
        $state = (array) $row;

        if ($state['projection_completed_at'] !== null) {
            return self::ALREADY_PROJECTED;
        }

        if ($state['exhausted_at'] === null) {
            return self::NOT_EXHAUSTED;
        }

        return null;
    }

    // The generation increment fences off failure recorders still in flight from the exhausted cycle.
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

        return is_numeric($generation) ? (int) $generation : 0;
    }

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
