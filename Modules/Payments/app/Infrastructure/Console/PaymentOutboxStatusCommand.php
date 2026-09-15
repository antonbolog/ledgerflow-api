<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Modules\Payments\Infrastructure\Relay\RelayConfiguration;
use Modules\Payments\Infrastructure\Relay\RelayHeartbeat;
use RuntimeException;
use stdClass;
use Throwable;

final class PaymentOutboxStatusCommand extends Command
{
    protected $signature = 'payments:outbox:status';

    protected $description = 'Report the scheduler heartbeat and PostgreSQL outbox backlog as JSON';

    public function __construct(
        private readonly RelayHeartbeat $heartbeat,
        private readonly Connection $connection,
        private readonly RelayConfiguration $config,
        private readonly ProjectionGate $gate,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $heartbeat = $this->heartbeat->read();
        $database = $this->databaseStatus();

        $healthy = $heartbeat['state'] === 'fresh' && $database['state'] === 'ok';

        $this->line((string) json_encode([
            'healthy' => $healthy,
            'heartbeat' => $heartbeat,
            'database' => $database,
        ], JSON_UNESCAPED_SLASHES));

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{state: string, eligible_count: int|null, oldest_eligible_age_seconds: int|null, active_count: int|null, gate: string|null}
     */
    private function databaseStatus(): array
    {
        $transactionStarted = false;

        try {
            $this->connection->beginTransaction();
            $transactionStarted = true;

            $this->connection->statement('set local statement_timeout = '.$this->config->statementTimeoutMs);
            $this->connection->statement('set transaction read only');

            $row = $this->connection->selectOne(<<<'SQL'
                select
                    count(*) filter (
                        where projection_completed_at is null and exhausted_at is null
                    ) as active_count,
                    count(*) filter (
                        where projection_completed_at is null and exhausted_at is null
                          and available_at <= now()
                    ) as eligible_count,
                    extract(epoch from (now() - min(available_at) filter (
                        where projection_completed_at is null and exhausted_at is null
                          and available_at <= now()
                    )))::bigint as oldest_eligible_age_seconds
                from payment_outbox
                SQL);

            if (! $row instanceof stdClass
                || ! is_numeric($row->active_count)
                || ! is_numeric($row->eligible_count)
                || ($row->oldest_eligible_age_seconds !== null && ! is_numeric($row->oldest_eligible_age_seconds))
            ) {
                throw new RuntimeException(
                    'The payment_outbox aggregate query returned an unexpected shape; refusing to report status.'
                );
            }

            $gate = $this->gate->inspectPaymentDetails()->value;

            $this->connection->commit();

            return [
                'state' => 'ok',
                'eligible_count' => (int) $row->eligible_count,
                'oldest_eligible_age_seconds' => $row->oldest_eligible_age_seconds === null
                    ? null
                    : (int) $row->oldest_eligible_age_seconds,
                'active_count' => (int) $row->active_count,
                'gate' => $gate,
            ];
        } catch (Throwable) {
            if ($transactionStarted && $this->connection->transactionLevel() > 0) {
                try {
                    $this->connection->rollBack();
                } catch (Throwable) {
                    // Read-only: nothing durable is at stake, and the answer is already unavailable.
                }
            }

            return [
                'state' => 'unavailable',
                'eligible_count' => null,
                'oldest_eligible_age_seconds' => null,
                'active_count' => null,
                'gate' => null,
            ];
        }
    }
}
