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

/**
 * A read-only JSON health surface: is the scheduler alive, and is the backlog moving.
 *
 * TWO INDEPENDENT SIGNALS, NEVER COLLAPSED INTO ONE. The heartbeat says whether
 * `payments:outbox:relay` is still being scheduled at all; the database aggregate
 * says what PostgreSQL currently holds. An empty backlog and a dead scheduler look
 * identical from the outbox alone, and a Redis outage must not be reported as a
 * stopped scheduler — that confusion is exactly what RelayHeartbeat's five states
 * exist to prevent, so this command reads them and adds nothing of its own.
 *
 * WRITES NOTHING. Unlike OutboxRelay, this never mutates a row, so there is no
 * commit-outcome-unknown phase to classify: any Throwable escaping the aggregate
 * means the database answer is not known, full stop.
 */
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
     * One snapshot-consistent aggregate, index-aligned with payment_outbox_eligible_index.
     *
     * A SHORT READ-ONLY TRANSACTION, bounded by the same statement_timeout_ms the
     * relay's own row transaction uses — reused rather than duplicated, because both
     * are "how long may one bounded PostgreSQL operation hold this connection".
     *
     * eligible_count is the complete relay predicate, INCLUDING available_at <=
     * now(). active_count is the partial-index predicate only, so it also counts
     * rows still waiting out grace or backoff. Both come from one statement so they
     * describe the same instant rather than two queries racing the wall clock.
     *
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

            // TYPES, NOT VALIDATION, same as OutboxRelay's own row read. selectOne()
            // is declared mixed, and an aggregate with no GROUP BY always returns
            // exactly one stdClass row — this branch exists so the cast below is
            // honest rather than suppressed. UNTESTED BY DESIGN: producing it would
            // require a query PostgreSQL cannot answer this way.
            if (! $row instanceof stdClass
                || ! is_numeric($row->active_count)
                || ! is_numeric($row->eligible_count)
                || ($row->oldest_eligible_age_seconds !== null && ! is_numeric($row->oldest_eligible_age_seconds))
            ) {
                throw new RuntimeException(
                    'The payment_outbox aggregate query returned an unexpected shape; refusing to report status.'
                );
            }

            // T11'S GATE DISTINCTION. Read in the SAME transaction as the aggregate
            // above, so the two answers describe one instant. An operator reading
            // this command must never mistake a closed gate for a healthy empty
            // backlog - the same reason RelayOutcome::GateClosed exists as its own
            // case rather than folding into NothingEligible.
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
            // Nothing here ever wrote a row, so there is no ambiguous outcome to
            // classify — only whether the answer is known. A cleanup failure is
            // swallowed for the usual reason: nothing durable is at stake, and a
            // second exception would only obscure the first.
            if ($transactionStarted && $this->connection->transactionLevel() > 0) {
                try {
                    $this->connection->rollBack();
                } catch (Throwable) {
                    // Deliberate.
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
