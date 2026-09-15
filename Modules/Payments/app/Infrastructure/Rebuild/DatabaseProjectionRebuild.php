<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Rebuild;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Payments\Application\Rebuild\ProjectionRebuild;
use Modules\Payments\Application\Rebuild\RebuildOutcome;
use Modules\Payments\Application\Rebuild\RebuildReason;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Infrastructure\Projection\PaymentInitiatedEnvelope;
use Modules\Payments\Infrastructure\Projection\PaymentProjectionConsumer;
use Modules\Payments\Infrastructure\Projection\ProjectionEnvelopeRejected;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Override;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;
use Throwable;

// One pinned connection with reconnection disabled for the whole run. The exclusive lock waits out every
// traffic transaction holding the shared gate, and replay reads stored_events only, never the outbox (ADR 0005).
final readonly class DatabaseProjectionRebuild implements ProjectionRebuild
{
    private const string PAYMENTS_ALIAS_PREFIX = 'payments.';

    private const string LOCK_TIMEOUT_SQLSTATE = '55P03';

    private const string STATEMENT_TIMEOUT_SQLSTATE = '57014';

    public function __construct(
        private Connection $connection,
        private RebuildConfiguration $config,
        private LoggerInterface $logger,
    ) {}

    #[Override]
    public function rebuild(bool $confirmRebuild, string $operationId): RebuildOutcome
    {
        if (! $confirmRebuild) {
            return RebuildOutcome::refused(RebuildReason::ConfirmationMissing);
        }

        // A savepoint cannot hold this command's session lock or its own commit boundaries.
        if ($this->connection->transactionLevel() !== 0) {
            return RebuildOutcome::failed(RebuildReason::DatabaseError);
        }

        $originalReconnector = $this->disableReconnection();

        // Every exit, including a refused lock acquisition, must restore the reconnector.
        try {
            return $this->runUnderExclusiveLock($operationId);
        } finally {
            $this->restoreReconnection($originalReconnector);
        }
    }

    private function runUnderExclusiveLock(string $operationId): RebuildOutcome
    {
        try {
            $this->acquireExclusiveLock();
        } catch (Throwable $failure) {
            $reason = RetryingAtomicTransaction::sqlStateOf($failure) === self::LOCK_TIMEOUT_SQLSTATE
                ? RebuildReason::LockTimeout
                : RebuildReason::DatabaseError;

            return $reason === RebuildReason::LockTimeout
                ? RebuildOutcome::refused($reason)
                : RebuildOutcome::failed($reason);
        }

        try {
            return $this->runClaimedRebuild($operationId);
        } finally {
            $this->releaseLockQuietly();
        }
    }

    private function runClaimedRebuild(string $operationId): RebuildOutcome
    {
        try {
            $token = (string) Str::uuid7();
            $pid = $this->backendPid();
        } catch (Throwable) {
            return RebuildOutcome::failed(RebuildReason::DatabaseError);
        }

        try {
            $this->logger->warning('Payments projection rebuild: an operator started a rebuild.', [
                'category' => 'rebuild_intent',
                'operation_id' => $operationId,
                'owner_token' => $token,
            ]);
        } catch (Throwable) {
            return RebuildOutcome::failed(RebuildReason::IntentAuditFailed);
        }

        $highWatermark = null;
        $eventsReplayed = null;
        $batchesCommitted = null;
        $guardedRecovery = false;

        try {
            /** @var array{previousState: string, clearedAt: string|null} $claim */
            $claim = $this->runStep(fn (): array => $this->claimOwnership($token, $pid));

            // Guarded recovery requires a takeover of a rebuilding row with cleared_at still null (ADR 0005, D7).
            // A fresh start from ready also reads null, so previousState is part of the test.
            if ($claim['previousState'] === ProjectionGate::REBUILDING && $claim['clearedAt'] === null) {
                $guardedRecovery = true;
                $eventsReplayed = 0;
                $batchesCommitted = 0;

                $this->runStep(function () use ($token): void {
                    $this->transitionToReady($token);
                });
            } else {
                $highWatermark = $this->runStep(fn (): int => $this->clearAndCaptureWatermark($token));

                $eventsReplayed = 0;
                $batchesCommitted = 0;
                $cursor = 0;

                while (true) {
                    /** @var array{count: int, cursor: int} $batch */
                    $batch = $this->runStep(fn (): array => $this->replayBatch($token, $cursor, $highWatermark));

                    $eventsReplayed += $batch['count'];

                    if ($batch['count'] === 0) {
                        break;
                    }

                    $batchesCommitted++;
                    $cursor = $batch['cursor'];

                    if ($batch['count'] < $this->config->batchSize) {
                        break;
                    }
                }

                $this->runStep(function () use ($token): void {
                    $this->transitionToReady($token);
                });
            }
        } catch (RebuildStepFailed $failed) {
            $this->logSafely(function () use ($operationId, $token, $failed, $highWatermark, $eventsReplayed, $batchesCommitted): void {
                $this->logger->error('Payments projection rebuild: aborted.', [
                    'category' => 'rebuild_aborted',
                    'operation_id' => $operationId,
                    'owner_token' => $token,
                    'reason' => $failed->reason->value,
                    'exception_class' => ($failed->getPrevious() ?? $failed)::class,
                    'high_watermark' => $highWatermark,
                    'events_replayed' => $eventsReplayed,
                    'batches_committed' => $batchesCommitted,
                ]);
            });

            return RebuildOutcome::failed($failed->reason, $highWatermark, $eventsReplayed, $batchesCommitted, $guardedRecovery);
        } catch (Throwable $failure) {
            $this->logSafely(function () use ($operationId, $token, $failure, $highWatermark, $eventsReplayed, $batchesCommitted): void {
                $this->logger->error('Payments projection rebuild: aborted.', [
                    'category' => 'rebuild_aborted',
                    'operation_id' => $operationId,
                    'owner_token' => $token,
                    'reason' => RebuildReason::DatabaseError->value,
                    'exception_class' => $failure::class,
                    'high_watermark' => $highWatermark,
                    'events_replayed' => $eventsReplayed,
                    'batches_committed' => $batchesCommitted,
                ]);
            });

            return RebuildOutcome::failed(RebuildReason::DatabaseError, $highWatermark, $eventsReplayed, $batchesCommitted, $guardedRecovery);
        }

        // The ready transition has committed: a logging failure is AuditFailed, never DatabaseError.
        try {
            $this->logger->info('Payments projection rebuild: completed.', [
                'category' => 'rebuild_completed',
                'operation_id' => $operationId,
                'owner_token' => $token,
                'high_watermark' => $highWatermark,
                'events_replayed' => $eventsReplayed,
                'batches_committed' => $batchesCommitted,
                'guarded_recovery' => $guardedRecovery,
            ]);
        } catch (Throwable) {
            return RebuildOutcome::failed(RebuildReason::AuditFailed, $highWatermark, $eventsReplayed, $batchesCommitted, $guardedRecovery);
        }

        return $guardedRecovery
            ? RebuildOutcome::guardedRecovery()
            : RebuildOutcome::completed($highWatermark, $eventsReplayed, $batchesCommitted);
    }

    /**
     * @param  Closure(): void  $logCall
     */
    private function logSafely(Closure $logCall): void
    {
        try {
            $logCall();
        } catch (Throwable) {
            // Logging must not replace an outcome the caller already decided.
        }
    }

    // A silent reconnect would continue on a backend holding no advisory lock. Connection has no reconnector
    // getter, so the original is read by reflection and restored after the run.
    /**
     * @return callable(Connection): mixed
     */
    private function disableReconnection(): callable
    {
        $property = new ReflectionProperty($this->connection, 'reconnector');
        $original = $property->getValue($this->connection);

        if (! is_callable($original)) {
            throw new RuntimeException(
                'Payments projection rebuild: the pinned connection has no reconnector installed to '
                .'restore afterwards; refusing to disable it.'
            );
        }

        $this->connection->setReconnector(function (): never {
            throw new RuntimeException(
                'Payments projection rebuild: reconnection is disabled on the pinned connection for the '
                .'duration of this run. A silent reconnect would move to a new backend holding no '
                .'advisory lock.'
            );
        });

        return $original;
    }

    private function restoreReconnection(callable $original): void
    {
        $this->connection->setReconnector($original);
    }

    private function acquireExclusiveLock(): void
    {
        $this->connection->statement($this->config->lockTimeoutStatement());

        try {
            $this->connection->select(
                'select pg_advisory_lock(?, ?)',
                [ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID],
            );
        } finally {
            $this->connection->statement('set lock_timeout = default');
        }
    }

    // Never writes cleared_at: a takeover must inherit it unchanged. Only the clear and the ready transition write it.
    /**
     * @return array{previousState: string, clearedAt: string|null}
     */
    private function claimOwnership(string $token, int $pid): array
    {
        /** @var object{state: string, cleared_at: string|null}|null $row */
        $row = $this->connection->selectOne(
            'select state, cleared_at from payment_projection_controls where projection = ? for update',
            [ProjectionGate::PAYMENT_DETAILS],
        );

        if ($row === null) {
            throw new RuntimeException(
                'payment_projection_controls has no row for payment_details; refusing to claim it.'
            );
        }

        $this->connection->update(
            <<<'SQL'
            update payment_projection_controls
               set state = ?, owner_token = ?, owner_pid = ?, started_at = now(), updated_at = now()
             where projection = ?
            SQL,
            [ProjectionGate::REBUILDING, $token, $pid, ProjectionGate::PAYMENT_DETAILS],
        );

        return ['previousState' => $row->state, 'clearedAt' => $row->cleared_at];
    }

    // cleared_at and the clear commit together; coalesce keeps the first clear since the last completed rebuild.
    private function clearAndCaptureWatermark(string $token): int
    {
        $this->assertOwnership($token, 'before the destructive clear');

        /** @var object{watermark: int|string} $row */
        $row = $this->connection->selectOne('select coalesce(max(id), 0) as watermark from stored_events');
        $watermark = (int) $row->watermark;

        $this->connection->update(
            <<<'SQL'
            update payment_projection_controls
               set cleared_at = coalesce(cleared_at, now()), updated_at = now()
             where projection = ?
            SQL,
            [ProjectionGate::PAYMENT_DETAILS],
        );

        $this->connection->delete('delete from payment_details');
        $this->connection->delete(
            'delete from payment_projector_receipts where projector_name = ?',
            [PaymentProjectionConsumer::PROJECTOR_NAME],
        );

        return $watermark;
    }

    /**
     * @return array{count: int, cursor: int}
     */
    private function replayBatch(string $token, int $cursor, int $watermark): array
    {
        $this->assertOwnership($token, 'at the start of a replay batch');

        /** @var list<object{id: int|string, aggregate_uuid: string, aggregate_version: int|string, event_version: int|string, event_class: string, event_properties: string|null}> $rows */
        $rows = $this->connection->select(
            <<<'SQL'
            select id, aggregate_uuid, aggregate_version, event_version, event_class, event_properties
              from stored_events
             where event_class like ?
               and id > ?
               and id <= ?
             order by id
             limit ?
            SQL,
            [self::PAYMENTS_ALIAS_PREFIX.'%', $cursor, $watermark, $this->config->batchSize],
        );

        $lastId = $cursor;

        foreach ($rows as $row) {
            $envelope = PaymentInitiatedEnvelope::fromStoredEventRow([
                'aggregate_uuid' => $row->aggregate_uuid,
                'aggregate_version' => $row->aggregate_version,
                'event_version' => $row->event_version,
                'event_class' => $row->event_class,
                'event_properties' => $row->event_properties,
            ]);

            $this->connection->affectingStatement(
                <<<'SQL'
                insert into payment_projector_receipts (projector_name, event_id, created_at)
                values (?, ?, now())
                on conflict (projector_name, event_id) do nothing
                SQL,
                [PaymentProjectionConsumer::PROJECTOR_NAME, $envelope->eventId],
            );

            $this->connection->table('payment_details')->insert([
                'payment_id' => $envelope->paymentId,
                'source_event_id' => $envelope->eventId,
                'merchant_id' => $envelope->merchantId,
                'amount_minor' => $envelope->amountMinor,
                'currency' => $envelope->currency,
                'status' => PaymentProjectionConsumer::INITIATED_STATUS,
                'aggregate_version' => $envelope->aggregateVersion,
                'initiated_at' => $envelope->occurredAt->format(PaymentInitiated::OCCURRED_AT_FORMAT),
            ]);

            $this->connection->update(
                <<<'SQL'
                update payment_outbox
                   set projection_completed_at = now(), updated_at = now()
                 where event_id = ?
                   and projection_completed_at is null
                SQL,
                [$envelope->eventId],
            );

            $lastId = (int) $row->id;
        }

        return ['count' => count($rows), 'cursor' => $lastId];
    }

    private function transitionToReady(string $token): void
    {
        $this->assertOwnership($token, 'in the transaction that sets ready');

        $this->connection->update(
            <<<'SQL'
            update payment_projection_controls
               set state = ?, owner_token = null, owner_pid = null, started_at = null, cleared_at = null,
                   updated_at = now()
             where projection = ?
            SQL,
            [ProjectionGate::READY, ProjectionGate::PAYMENT_DETAILS],
        );
    }

    // Lock possession comes from pg_locks, not a stored pid, which pid reuse would defeat.
    private function assertOwnership(string $token, string $checkpoint): void
    {
        $row = $this->connection->selectOne(
            <<<'SQL'
            select 1 as ok
              from payment_projection_controls
             where projection = ? and state = ? and owner_token = ?
             for update
            SQL,
            [ProjectionGate::PAYMENT_DETAILS, ProjectionGate::REBUILDING, $token],
        );

        if ($row === null) {
            throw new RebuildFenceFailed($checkpoint);
        }

        $lock = $this->connection->selectOne(
            <<<'SQL'
            select 1 as ok
              from pg_locks
             where locktype = 'advisory'
               and classid = ?
               and objid = ?
               and mode = 'ExclusiveLock'
               and granted
               and database = (select oid from pg_database where datname = current_database())
               and pid = pg_backend_pid()
            SQL,
            [ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID],
        );

        if ($lock === null) {
            throw new RebuildFenceFailed($checkpoint);
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    private function runStep(Closure $work): mixed
    {
        $transactionStarted = false;
        $commitAttempted = false;

        try {
            $this->connection->beginTransaction();
            $transactionStarted = true;

            $this->connection->statement($this->config->statementTimeoutStatement());

            $result = $work();

            $commitAttempted = true;
            $this->connection->commit();

            return $result;
        } catch (Throwable $failure) {
            throw new RebuildStepFailed(
                $this->classifyAbort($failure, $transactionStarted, $commitAttempted),
                $failure,
            );
        }
    }

    private function classifyAbort(Throwable $failure, bool $transactionStarted, bool $commitAttempted): RebuildReason
    {
        $serverTransactionOpen = $this->serverTransactionOpen();

        $stillOpen = $transactionStarted
            && ($serverTransactionOpen || $this->connection->transactionLevel() > 0);

        if ($stillOpen) {
            try {
                $this->connection->rollBack();
            } catch (Throwable $cleanup) {
                $this->logger->warning('Payments projection rebuild: rollback cleanup failed after a failed step.', [
                    'category' => 'rebuild_rollback_cleanup_failed',
                    'exception_class' => $cleanup::class,
                ]);
            }
        }

        if ($failure instanceof RebuildFenceFailed) {
            return RebuildReason::OwnershipLost;
        }

        if ($failure instanceof ProjectionEnvelopeRejected) {
            return RebuildReason::CanonicalEventUndecodable;
        }

        // Only an open server transaction proves an attempted commit did not land.
        if ($commitAttempted && ! $serverTransactionOpen) {
            return RebuildReason::CommitOutcomeUnknown;
        }

        return match (RetryingAtomicTransaction::sqlStateOf($failure)) {
            self::STATEMENT_TIMEOUT_SQLSTATE => RebuildReason::StatementTimeout,
            default => RebuildReason::DatabaseError,
        };
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

    private function backendPid(): int
    {
        /** @var object{pid: int|string} $row */
        $row = $this->connection->selectOne('select pg_backend_pid() as pid');

        return (int) $row->pid;
    }

    private function releaseLockQuietly(): void
    {
        try {
            $this->connection->select(
                'select pg_advisory_unlock(?, ?)',
                [ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID],
            );
        } catch (Throwable $cleanup) {
            $this->logger->warning('Payments projection rebuild: releasing the exclusive gate lock failed.', [
                'category' => 'rebuild_lock_release_failed',
                'exception_class' => $cleanup::class,
            ]);
        }
    }
}
