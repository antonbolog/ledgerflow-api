<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Relay;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\Connection;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use PDOException;
use Psr\Log\LoggerInterface;
use RedisException;
use RuntimeException;
use Throwable;

// Publishes before COMMIT, so a crash yields a safe duplicate rather than a lost projection (ADR 0004).
// Only RedisException is a transport rejection; read outbox-delivery.md, "Transport exception
// classification", before widening that catch.
final readonly class OutboxRelay
{
    public const string TRANSPORT_REJECTED = 'redis_transport_rejected';

    public function __construct(
        private Connection $connection,
        private QueueFactory $queue,
        private RelayConfiguration $config,
        private LoggerInterface $logger,
        private ProjectionGate $gate,
    ) {}

    public function relayOne(): RelayOutcome
    {
        // Phase flags, not connection state: failing before BEGIN and after COMMIT look identical.
        $transactionStarted = false;
        $commitAttempted = false;

        try {
            $this->connection->beginTransaction();
            $transactionStarted = true;

            $this->connection->statement('set local statement_timeout = '.$this->config->statementTimeoutMs);

            if (! $this->gate->inspectPaymentDetails()->isOpen()) {
                $commitAttempted = true;
                $this->connection->commit();

                return RelayOutcome::GateClosed;
            }

            $row = $this->connection->table('payment_outbox')
                ->select('event_id', 'consecutive_transport_failures')
                ->whereNull('projection_completed_at')
                ->whereNull('exhausted_at')
                ->whereRaw('available_at <= now()')
                ->oldest('available_at')
                ->orderBy('event_id')
                ->limit(1)
                // SKIP LOCKED keeps overlapping relay passes safe without a scheduler mutex.
                ->lock('for update skip locked')
                ->first();

            if ($row === null) {
                $commitAttempted = true;
                $this->connection->commit();

                return RelayOutcome::NothingEligible;
            }

            $eventId = $row->event_id;
            $failures = $row->consecutive_transport_failures;

            if (! is_string($eventId) || ! is_numeric($failures)) {
                throw new RuntimeException(
                    'payment_outbox returned a row whose event_id or consecutive_transport_failures '
                    .'is not of its declared type. The schema and the query disagree; refusing to relay.'
                );
            }

            try {
                // push(), never pushRaw(). The connection is resolved inside the try because an
                // unreachable Redis fails while building the client, which is a transport rejection.
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

            throw $failure;
        }
    }

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

    // Committed so the backoff survives. Never writes exhaustion columns or the driver's message.
    private function recordRejection(string $eventId, int $failuresBefore, RedisException $rejection): void
    {
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

        // A failed commit with no transaction left may already be durable, so it is reported as unknown.
        $ambiguous = $commitAttempted && ! $stillOpen;

        $this->logger->error('Payments outbox relay: the pass failed and was aborted.', [
            'category' => $ambiguous ? 'commit_outcome_unknown' : 'relay_failed_before_commit',
            'exception_class' => $failure::class,
            'sql_state' => self::sqlStateOf($failure),
            'transaction_started' => $transactionStarted,
            'commit_attempted' => $commitAttempted,
        ]);
    }

    // errorInfo only, never the message: this value goes into a structured log field.
    private static function sqlStateOf(Throwable $failure): ?string
    {
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
