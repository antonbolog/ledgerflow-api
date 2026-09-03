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

/**
 * The bounded batch rebuild, in D6's three phases plus the replay work they
 * guard.
 *
 *     [pin the connection; disable silent reconnection]
 *     SET lock_timeout; SELECT pg_advisory_lock(...)         <- phase 1, session-level, blocking
 *     SET lock_timeout = default
 *     BEGIN; SET LOCAL statement_timeout
 *     SELECT state, cleared_at ... FOR UPDATE; UPDATE ... rebuilding <- phase 2, claims ownership
 *     COMMIT
 *     if takeover AND cleared_at was null:            <- D7 guarded recovery
 *       BEGIN; SET LOCAL statement_timeout
 *       <fence checkpoint 3>; UPDATE ... ready
 *       COMMIT
 *     else:                                            <- fresh start, or a non-null inheritance
 *       BEGIN; SET LOCAL statement_timeout
 *       <fence checkpoint 1>; capture watermark; set cleared_at; DELETE payment_details / receipts
 *       COMMIT
 *       loop:
 *         BEGIN; SET LOCAL statement_timeout
 *         <fence checkpoint 2>; SELECT canonical batch; INSERT receipt+row per event; reconcile marker
 *         COMMIT
 *       BEGIN; SET LOCAL statement_timeout
 *       <fence checkpoint 3>; UPDATE ... ready, cleared_at = null
 *       COMMIT
 *     SELECT pg_advisory_unlock(...)
 *
 * ONE PINNED CONNECTION FOR THE WHOLE RUN, WITH RECONNECTION DISABLED (F3). A
 * command process, not a pooled request: nothing else in this PHP process needs
 * the default connection to reconnect while a rebuild is in progress, and an
 * attempt now fails loudly instead of silently moving to a backend holding no
 * advisory lock - see RebuildFenceFailed and the "surviving process" case in
 * verification-and-rebuild.md.
 *
 * PHASE 1 IS WHY "the rebuild waits for a consumer transaction that started
 * before it, and clears nothing until that transaction ends" NEEDS NO EXPLICIT
 * WAIT LOOP OF ITS OWN. Every traffic path holds ProjectionGate's shared
 * advisory lock for the WHOLE of its own transaction, not merely for the
 * instant of the check; PostgreSQL's exclusive `pg_advisory_lock` call here
 * cannot be granted until every such shared holder has released it. By the
 * time phase 1 returns, no in-flight traffic transaction can still be reading
 * or writing `payment_details`.
 *
 * THE OWNERSHIP FENCE IS RE-VERIFIED AT THREE CHECKPOINTS - immediately before
 * the destructive clear, at the start of every batch, and inside the
 * transaction that sets `ready` - and each one asserts BOTH the control row
 * (this run's token, read FOR UPDATE) and this backend's own possession of the
 * exclusive lock, read from `pg_locks`. Lock possession is asked directly
 * rather than inferred from a stored pid, because a pid comparison is a proxy
 * defeated by pid reuse; see assertOwnership().
 *
 * `cleared_at` HAS EXACTLY TWO WRITERS HERE (D7): clearAndCaptureWatermark()
 * sets it, in the same transaction as the clear, only while still null;
 * transitionToReady() nulls it, in the same transaction as the `ready`
 * transition, after a complete successful replay. claimOwnership() never
 * touches it, so a takeover inherits whatever a previous run left - byte for
 * byte, across any number of takeovers, aborts and re-invocations.
 *
 * GUARDED RECOVERY (D7) is the one shortcut this class takes, and it is
 * narrow on purpose: only when a takeover finds the row already `rebuilding`
 * with `cleared_at` still null - proof that no destructive clear has
 * committed by anyone since the last completed rebuild - does the run skip
 * straight to the `ready` transition, replaying nothing. A fresh start from
 * `ready` also reads `cleared_at` as null (the CHECK constraint requires it),
 * which is exactly why that fact alone is never the test: only
 * claimOwnership()'s own previous-state read distinguishes "nothing to redo"
 * from "this is the first attempt". Every other combination - a fresh start,
 * or a takeover whose inherited `cleared_at` is already set - is a mandatory
 * full clear and replay, even when the read model happens to look complete.
 * See runClaimedRebuild().
 *
 * REPLAY READS ONLY `stored_events`, THROUGH THE CONSUMER'S OWN DECODER
 * (PaymentInitiatedEnvelope::fromStoredEventRow()). The outbox is derived
 * state this whole feature exists to detect corruption in; deriving the read
 * model from it instead would launder that corruption back in. It never
 * rehydrates PaymentAggregate, dispatches an event, or invokes Spatie replay,
 * the consumer, or PaymentOutboxWriter.
 *
 * WHAT THIS NEVER TOUCHES: `payment_outbox` rows (never created or deleted -
 * only `projection_completed_at` is reconciled per event, exactly as a first
 * delivery would set it), `stored_events`, `snapshots`, and
 * `payment_idempotency_receipts`.
 */
final readonly class DatabaseProjectionRebuild implements ProjectionRebuild
{
    private const string PAYMENTS_ALIAS_PREFIX = 'payments.';

    /** `lock_not_available` - phase 1's bounded wait expired; a live rebuild holds the gate. */
    private const string LOCK_TIMEOUT_SQLSTATE = '55P03';

    /** `query_canceled` - a later statement's own bound fired. */
    private const string STATEMENT_TIMEOUT_SQLSTATE = '57014';

    /**
     * NO IN-PROCESS WORK BUDGET, unlike the verifier's. Every bound here is
     * already a PostgreSQL-side timeout - the exclusive lock's `lock_timeout`
     * and each transaction's `statement_timeout` - and a bounded batch loop
     * checks progress by row count, not by elapsed wall time.
     */
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

        // A savepoint could not establish this command's session-level lock or
        // durable commit boundaries - the same guard targeted repair and the
        // verifier both carry.
        if ($this->connection->transactionLevel() !== 0) {
            return RebuildOutcome::failed(RebuildReason::DatabaseError);
        }

        $originalReconnector = $this->disableReconnection();

        // "FOR THE DURATION OF THE RUN" MEANS EVERY EXIT FROM HERE, not only a
        // completed one: a refused lock acquisition restores it exactly as a
        // completed or aborted run does. Nothing below this point may return
        // without passing through this `finally`.
        try {
            return $this->runUnderExclusiveLock($operationId);
        } finally {
            $this->restoreReconnection($originalReconnector);
        }
    }

    /**
     * Phase 1, and everything the exclusive lock guards.
     *
     * THE LOCK RELEASE IS UNCONDITIONAL ON EVERY EXIT FROM runClaimedRebuild(),
     * including a failure in the intent log itself - the very first thing that
     * runs after the lock is confirmed held. A `finally` here, rather than a
     * release at the end of the happy path plus another in a catch block, is
     * what makes that true regardless of which of runClaimedRebuild()'s own
     * exits is taken, including one this class did not anticipate.
     */
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

    /**
     * Everything from token minting onward, run while phase 1's exclusive
     * lock is held.
     *
     * NOTHING HERE MAY ESCAPE UNCAUGHT. Both blocks below run before
     * claimOwnership() ever does, so a failure in either leaves the control
     * row exactly as claimOwnership() found it - untouched - and reports a
     * classified `RebuildOutcome` rather than propagating past
     * runUnderExclusiveLock()'s lock-release `finally` and rebuild()'s
     * reconnector-restoring one uncaught, which would skip the console
     * command's JSON contract entirely.
     *
     * THE CONNECTION READ IS CAUGHT SEPARATELY FROM THE LOG, and deliberately
     * first: a lost connection immediately after acquiring the exclusive lock
     * is a realistic failure - PostgreSQL can drop a session at any point,
     * not only mid-transaction - and it is a database fact, not an audit-sink
     * fact, so it is reported as DatabaseError rather than being folded into
     * IntentAuditFailed. Neither classification touches runStep()'s own
     * commit-uncertainty logic below: nothing here has opened a transaction
     * yet, so there is no commit outcome to be uncertain about.
     *
     * A LOGGING FAILURE NEVER MISREPORTS THE OUTCOME, in either direction.
     * Before claimOwnership() runs, a broken sink means the mutation never
     * begins (IntentAuditFailed, matching RepairReason's own case exactly).
     * After transitionToReady() has committed, a broken sink must not turn a
     * real success into a reported failure state, nor silently claim a clean
     * success when the run's own audit trail is unreliable - AuditFailed
     * reports the completed progress while still being a failure exit, for the
     * same reason targeted repair's own AuditFailed is.
     */
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

            // GUARDED RECOVERY (D7). Only a TAKEOVER of an already-`rebuilding`
            // row is eligible, never a fresh start from `ready` - the CHECK
            // constraint guarantees `ready` also reads `cleared_at` as null, so
            // that fact alone cannot be the test, or a fresh start would wrongly
            // skip the very work it exists to do. Eligible only when BOTH hold:
            // this run took over (previousState is REBUILDING) AND the durable
            // row's cleared_at is still null - proof that no destructive clear
            // has committed, by anyone, since the last completed rebuild. Every
            // other combination - a fresh start, or a takeover whose inherited
            // cleared_at is already set - is a mandatory full clear and replay,
            // even when the read model happens to look complete.
            if ($claim['previousState'] === ProjectionGate::REBUILDING && $claim['clearedAt'] === null) {
                $guardedRecovery = true;
                $eventsReplayed = 0;
                $batchesCommitted = 0;

                // CHECKPOINT 3 STILL APPLIES, UNCHANGED: transitionToReady()
                // re-verifies ownership before it may reopen the gate, exactly
                // as it does on the full-replay path below. Checkpoints 1 and 2
                // do not apply here because there is no clear and no batch to
                // guard - not because they were skipped.
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
            // ANYTHING runStep() DID NOT ALREADY CLASSIFY - in practice only
            // reachable from a bug, since every database statement above runs
            // inside runStep(). Reported the same way rather than escaping
            // uncaught, which would skip both the JSON contract and the lock
            // release this method's caller depends on.
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

        // THE WORK LANDED: transitionToReady()'s own transaction already
        // committed. From here, a logging failure reports AuditFailed with the
        // real final progress attached - never DatabaseError, which would
        // wrongly suggest the projection is still `rebuilding`.
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
     * Log best-effort: a logging failure here must never replace or mask an
     * outcome already decided by the caller - the same rule releaseLockQuietly()
     * follows for the lock itself.
     *
     * @param  Closure(): void  $logCall
     */
    private function logSafely(Closure $logCall): void
    {
        try {
            $logCall();
        } catch (Throwable) {
            // Deliberate: see the call sites.
        }
    }

    /**
     * F3's defence, in code. Any statement issued outside a transaction while
     * this connection believes it has none open would otherwise be silently
     * retried on a freshly reconnected backend - one holding no advisory lock -
     * per Connection::tryAgainIfCausedByLostConnection(). Turning that into a
     * loud failure is strictly additional to the ownership fence, which is the
     * actual proof; a defence that depends on framework configuration alone is
     * not one.
     *
     * "FOR THE DURATION OF THIS RUN", NOT PERMANENTLY. This connection is the
     * SAME shared default object every other Payments adapter resolves - a
     * standalone `payments:projections:rebuild` process owns it exclusively for
     * its own lifetime and disabling reconnection for good would be harmless
     * there, but this class also has to be safe to call twice in one PHP
     * process (Integration tests do exactly that), so the original reconnector
     * is captured here and handed back to rebuild()'s own `finally`, which
     * restores it via restoreReconnection() on every exit - refused,
     * completed, or aborted alike.
     *
     * READ BY REFLECTION, DELIBERATELY, RATHER THAN GUESSED. Connection
     * exposes setReconnector() but no getter; reconstructing what
     * DatabaseManager would have installed risks restoring a DIFFERENT
     * closure from whatever was actually there - including one a test itself
     * installed. Reading the live property back is the only way "restore
     * exactly what was there before" is a fact rather than an assumption.
     *
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

    /** The other half of disableReconnection(): restore exactly what was captured. */
    private function restoreReconnection(callable $original): void
    {
        $this->connection->setReconnector($original);
    }

    /**
     * Phase 1: the session-level exclusive lock, bounded and blocking.
     *
     * SESSION-LEVEL lock_timeout, not SET LOCAL: no transaction is open yet for
     * a local setting to be scoped to. Reset unconditionally in `finally`, so
     * phase 1's bound can never leak into a later short transaction regardless
     * of whether the lock was granted or the wait timed out.
     */
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

    /**
     * Phase 2: claim ownership, preserving `cleared_at` exactly as D7 requires,
     * and report what was found so the caller can decide guarded recovery.
     *
     * `cleared_at` IS NOT NAMED IN THIS STATEMENT, ON PURPOSE. Whatever a
     * previous run left - null after a clean start, non-null after a partial
     * clear - passes through untouched. Only clearAndCaptureWatermark() and
     * transitionToReady() may ever write that column. This method claims
     * UNCONDITIONALLY, whether the row was `ready` (a fresh start) or already
     * `rebuilding` (a takeover of an abandoned run). The session-level exclusive
     * gate serialises rebuild owners; FOR UPDATE protects this row transition.
     * CHECK constraints enforce row shape, not preservation of historical clear
     * evidence, which depends on these writers leaving the marker untouched.
     *
     * THE PREVIOUS STATE IS RETURNED, NOT ONLY `cleared_at`, because guarded
     * recovery (D7) is conditioned on BOTH: a fresh start from `ready` also
     * reads `cleared_at` as null - the CHECK constraint requires it - so that
     * fact alone cannot be the test, or a fresh start would wrongly skip the
     * very work it exists to do. Only a genuine takeover of an already
     * `rebuilding` row, found with `cleared_at` still null, may take the
     * shortcut.
     *
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
            // Unreachable from the seeded migration; refused rather than assumed.
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

    /**
     * Fence checkpoint 1, the watermark, and the atomic clear (D6 step 3-4).
     *
     * `cleared_at` AND THE CLEAR COMMIT IN ONE TRANSACTION, so a lost commit
     * response leaves the two either both landed or neither did - never one
     * without the other. `coalesce(cleared_at, now())` is what makes this
     * write idempotent across a takeover of a run that already cleared: it
     * only ever records the FIRST clear since the last completed rebuild.
     */
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
     * Fence checkpoint 2, one keyset batch of canonical events, replayed.
     *
     * DERIVED FROM stored_events THROUGH THE CONSUMER'S OWN DECODER, never
     * from `payment_outbox`. A row that fails to decode raises
     * ProjectionEnvelopeRejected, which aborts the whole run rather than
     * skipping the row: a rebuild that quietly stepped over corrupt canonical
     * history would report `ready` over a projection it did not fully build.
     *
     * THE RECEIPT, THE READ ROW, AND THE MARKER RECONCILIATION, per event, in
     * this transaction - the same three writes projectFirstDelivery() makes,
     * because every event in a rebuild IS a first delivery against the
     * just-cleared tables. `on conflict do nothing` on the receipt is
     * defensive rather than expected: nothing but this run's own clear could
     * have left one behind.
     *
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
            // NAMED EXPLICITLY, NOT `(array) $row` - the decoder reads five
            // keys and this select returns six; spreading the row would hand
            // it `id`, a column it does not read.
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

            // D9's marker reconciliation: set only where this batch just
            // created the receipt that completes it, and only while the
            // marker is still null. Every other payment_outbox column is
            // untouched.
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

    /**
     * Fence checkpoint 3: the transition that reopens the gate.
     *
     * The only path permitted to null `cleared_at` (D7), and it does so in the
     * same transaction as the state transition itself - so a lost commit
     * response leaves both landed or neither, exactly as the clear does.
     */
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

    /**
     * The ownership fence: both assertions, neither redundant (see the class
     * docblock and verification-and-rebuild.md, "Ownership fence").
     *
     * LOCK POSSESSION IS READ FROM pg_locks, NOT COMPARED AGAINST A STORED PID.
     * A pid comparison is a proxy for "is this still my session" and pid
     * reuse defeats it; asking pg_locks whether THIS backend currently holds
     * the exclusive gate lock is the direct question. This is a runtime
     * self-check on the session in question, not the affinity EVIDENCE T4's
     * rule forbids a holder from self-reporting - that rule governs test
     * evidence, and tests for this fence still witness from a third
     * connection.
     */
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
     * Run one transaction: `SET LOCAL` the bound, the caller's work, commit -
     * classifying an ambiguous commit exactly as the relay, the consumer and
     * targeted repair each do at this same boundary.
     *
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

    /**
     * The classification, from the phases rather than from the end state -
     * the same reasoning OutboxRelay::abort() and PaymentProjectionConsumer::abort()
     * record. A fence refusal or an undecodable canonical event are never
     * ambiguous: both are raised from a plain read, before this step ever
     * attempted its COMMIT.
     */
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

        // An OPEN SERVER TRANSACTION is the only thing that PROVES the commit
        // did not land; its absence after an attempted commit is the absence
        // of proof either way, which is what "unknown" means.
        if ($commitAttempted && ! $serverTransactionOpen) {
            return RebuildReason::CommitOutcomeUnknown;
        }

        return match (RetryingAtomicTransaction::sqlStateOf($failure)) {
            self::STATEMENT_TIMEOUT_SQLSTATE => RebuildReason::StatementTimeout,
            default => RebuildReason::DatabaseError,
        };
    }

    /**
     * Whether PostgreSQL still has a transaction open on this connection.
     *
     * Wrapped, because getPdo() is exactly the call that fails when the
     * connection is the thing that broke. An unanswerable question is
     * `false`: this is only ever used to look for PROOF the commit did not
     * land, and the absence of proof is what "unknown" is made of.
     */
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

    /**
     * Release the exclusive lock, or say why not, but never let cleanup
     * replace the failure that led here.
     *
     * SAFE TO CALL WHETHER OR NOT THIS SESSION STILL HOLDS IT: PostgreSQL's
     * `pg_advisory_unlock` returns false rather than raising when the caller
     * does not hold the lock, which is exactly the state a fence failure or a
     * lost session can leave this connection in.
     */
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
