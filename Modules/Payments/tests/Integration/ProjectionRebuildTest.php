<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Application\Rebuild\ProjectionRebuild;
use Modules\Payments\Application\Rebuild\RebuildOutcome;
use Modules\Payments\Application\Rebuild\RebuildReason;
use Modules\Payments\Application\Repair\RepairOutcome;
use Modules\Payments\Application\Verification\VerificationReport;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Infrastructure\Console\RebuildPaymentProjectionsCommand;
use Modules\Payments\Infrastructure\Projection\PaymentProjectionConsumer;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Modules\Payments\Infrastructure\Projection\ProjectionGateState;
use Modules\Payments\Infrastructure\Rebuild\DatabaseProjectionRebuild;
use Modules\Payments\Infrastructure\Rebuild\RebuildConfiguration;
use Modules\Payments\Infrastructure\Repair\DatabaseOutboxRepair;
use Modules\Payments\Infrastructure\Verification\DatabaseProjectionVerifier;
use Modules\Payments\Infrastructure\Verification\VerificationConfiguration;
use Modules\Payments\Tests\Integration\IntegrationDatabase;
use Modules\Payments\Tests\Integration\ProjectionGateLocks;
use Modules\Payments\Tests\Support\FailingLogger;
use Psr\Log\LoggerInterface;

function rebuildSeed(?int $merchantId = null, string $occurredAt = '2026-08-21T10:11:12.123456+00:00'): array
{
    $merchantId ??= Merchant::factory()->create()->getKey();
    $eventId = (string) Str::uuid7();
    $paymentId = (string) Str::uuid7();

    $payload = [
        'eventId' => $eventId,
        'paymentId' => $paymentId,
        'merchantId' => $merchantId,
        'amountMinor' => 1_250,
        'currency' => 'USD',
        'occurredAt' => $occurredAt,
    ];

    DB::table('stored_events')->insert([
        'aggregate_uuid' => $paymentId,
        'aggregate_version' => 1,
        'event_version' => 1,
        'event_class' => PaymentInitiated::ALIAS,
        'event_properties' => json_encode($payload),
        'meta_data' => json_encode([]),
        'created_at' => now(),
    ]);

    DB::table('payment_outbox')->insert([
        'event_id' => $eventId,
        'event_type' => PaymentInitiated::ALIAS,
        'schema_version' => 1,
        'aggregate_uuid' => $paymentId,
        'aggregate_version' => 1,
        'payload' => json_encode($payload),
        'occurred_at' => $occurredAt,
        'available_at' => now(),
        'publish_count' => 3,
        'last_published_at' => '2026-08-21T09:00:00+00:00',
        'consecutive_transport_failures' => 1,
        'last_transport_error' => 'redis_transport_rejected',
        'created_at' => '2026-08-21T09:00:00+00:00',
        'updated_at' => '2026-08-21T09:00:00+00:00',
    ]);

    return ['event_id' => $eventId, 'payment_id' => $paymentId, 'merchant_id' => $merchantId];
}

function rebuildForeignGap(): void
{
    DB::table('stored_events')->insert([
        'aggregate_uuid' => (string) Str::uuid7(),
        'aggregate_version' => 1,
        'event_version' => 1,
        'event_class' => 'other_context.something_happened',
        'event_properties' => json_encode(['x' => 1]),
        'meta_data' => json_encode([]),
        'created_at' => now(),
    ]);
}

function rebuildRun(bool $confirm = true): array
{
    $exitCode = Artisan::call('payments:projections:rebuild', $confirm ? ['--confirm-rebuild' => true] : []);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    return [$exitCode, $decoded];
}

function rebuildWithConfig(int $batchSize = 500, int $lockTimeoutMs = 5_000, int $statementTimeoutMs = 5_000): void
{
    app()->bind(ProjectionRebuild::class, fn (): DatabaseProjectionRebuild => new DatabaseProjectionRebuild(
        resolve(DatabaseManager::class)->connection(),
        RebuildConfiguration::fromArray([
            'batch_size' => $batchSize,
            'lock_timeout_ms' => $lockTimeoutMs,
            'statement_timeout_ms' => $statementTimeoutMs,
        ]),
        resolve(LoggerInterface::class),
    ));

    // Artisan caches command instances; re-register so a new attempt uses the new adapter and connection.
    app()->forgetInstance(RebuildPaymentProjectionsCommand::class);
    Artisan::registerCommand(resolve(RebuildPaymentProjectionsCommand::class));
}

function rebuildWithFailingLogger(int $failOnCall, int $batchSize = 500): void
{
    app()->bind(ProjectionRebuild::class, fn (): DatabaseProjectionRebuild => new DatabaseProjectionRebuild(
        resolve(DatabaseManager::class)->connection(),
        RebuildConfiguration::fromArray([
            'batch_size' => $batchSize,
            'lock_timeout_ms' => 5_000,
            'statement_timeout_ms' => 5_000,
        ]),
        new FailingLogger($failOnCall),
    ));
}

function rebuildLockHeld(): bool
{
    $locks = ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID);

    return array_filter($locks, static fn (array $lock): bool => $lock['mode'] === 'ExclusiveLock') !== [];
}

function rebuildControlRow(): array
{
    $row = DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_projection_controls')->where('projection', 'payment_details')->first();

    return (array) $row;
}

function rebuildReadRow(string $paymentId): ?array
{
    $row = DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_details')->where('payment_id', $paymentId)->first();

    return $row === null ? null : (array) $row;
}

beforeEach(function (): void {
    IntegrationDatabase::ensureSchema();
    IntegrationDatabase::register();
    IntegrationDatabase::refuseIfWrapped();
    IntegrationDatabase::truncate();
});

afterEach(function (): void {
    DB::statement('set lock_timeout = default');

    IntegrationDatabase::rollbackOpenTransactions();
    IntegrationDatabase::releaseLocks();
    ProjectionGateLocks::releaseAll(IntegrationDatabase::LOCK);
    ProjectionGateLocks::releaseAll(DB::getDefaultConnection());

    IntegrationDatabase::truncate();
    IntegrationDatabase::disconnectAll();
});

it('refuses without --confirm-rebuild, before touching the database', function (): void {
    $seeded = rebuildSeed();

    [$exitCode, $result] = rebuildRun(confirm: false);

    expect($exitCode)->toBe(RebuildOutcome::EXIT_REFUSED)
        ->and($result['outcome'])->toBe(RebuildOutcome::OUTCOME_REFUSED)
        ->and($result['reason'])->toBe(RebuildReason::ConfirmationMissing->value)
        ->and($result['high_watermark'])->toBeNull();

    expect(rebuildControlRow()['state'])->toBe('ready')
        ->and(rebuildReadRow($seeded['payment_id']))->toBeNull();
});

it('waits for a traffic-shaped shared holder, refuses on lock timeout, and clears nothing', function (): void {
    $seeded = rebuildSeed();

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $gate = new ProjectionGate($lock);
    expect($gate->inspectPaymentDetails()->isOpen())->toBeTrue();

    rebuildWithConfig(lockTimeoutMs: 300);

    $startedAt = hrtime(true);
    [$exitCode, $result] = rebuildRun();
    $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

    expect($elapsedMs)->toBeGreaterThanOrEqual(250);

    expect($exitCode)->toBe(RebuildOutcome::EXIT_REFUSED)
        ->and($result['outcome'])->toBe(RebuildOutcome::OUTCOME_REFUSED)
        ->and($result['reason'])->toBe(RebuildReason::LockTimeout->value);

    $control = rebuildControlRow();
    expect($control)->toBe([
        'projection' => 'payment_details',
        'state' => 'ready',
        'owner_token' => null,
        'owner_pid' => null,
        'started_at' => null,
        'cleared_at' => null,
        'created_at' => $control['created_at'],
        'updated_at' => $control['updated_at'],
    ]);
    expect(rebuildReadRow($seeded['payment_id']))->toBeNull();

    $lock->rollBack();

    expect(fn () => DB::connection()->reconnect())->not->toThrow(RuntimeException::class);

    [$exitCode, $result] = rebuildRun();

    expect($exitCode)->toBe(RebuildOutcome::EXIT_DONE)
        ->and($result['outcome'])->toBe(RebuildOutcome::OUTCOME_COMPLETED);

    expect(fn () => DB::connection()->reconnect())->not->toThrow(RuntimeException::class);
    expect(DB::connection()->selectOne('select 1 as ok')?->ok)->toEqual(1);
});

it('refuses inside an already-open transaction, matching the verifier and repair guard', function (): void {
    DB::connection()->beginTransaction();

    [$exitCode, $result] = rebuildRun();

    expect($exitCode)->toBe(RebuildOutcome::EXIT_FAILED)
        ->and($result['reason'])->toBe(RebuildReason::DatabaseError->value);

    DB::connection()->rollBack();
});

it('replays bounded batches across an ID gap and reproduces the columns the consumer would have written', function (): void {
    $first = rebuildSeed(occurredAt: '2026-08-21T10:11:12.123456+00:00');
    rebuildForeignGap();
    $second = rebuildSeed(occurredAt: '2026-08-21T11:22:33.654321+00:00');

    resolve(PaymentProjectionConsumer::class)->project($first['event_id']);
    resolve(PaymentProjectionConsumer::class)->project($second['event_id']);

    $before = [
        $first['payment_id'] => rebuildReadRow($first['payment_id']),
        $second['payment_id'] => rebuildReadRow($second['payment_id']),
    ];

    expect($before[$first['payment_id']])->not->toBeNull()
        ->and($before[$second['payment_id']])->not->toBeNull();

    rebuildWithConfig(batchSize: 1);

    [$exitCode, $result] = rebuildRun();

    expect($exitCode)->toBe(RebuildOutcome::EXIT_DONE)
        ->and($result['outcome'])->toBe(RebuildOutcome::OUTCOME_COMPLETED)
        ->and($result['reason'])->toBe(RebuildReason::RebuildComplete->value)
        ->and($result['events_replayed'])->toBe(2)
        ->and($result['batches_committed'])->toBe(2);

    expect(rebuildReadRow($first['payment_id']))->toBe($before[$first['payment_id']])
        ->and(rebuildReadRow($second['payment_id']))->toBe($before[$second['payment_id']]);

    $receipts = DB::connection(IntegrationDatabase::OBSERVER)->table('payment_projector_receipts')->get();
    expect($receipts)->toHaveCount(2);
    foreach ($receipts as $receipt) {
        expect($receipt->projector_name)->toBe(PaymentProjectionConsumer::PROJECTOR_NAME);
    }

    expect(rebuildControlRow())->toMatchArray([
        'state' => 'ready',
        'owner_token' => null,
        'owner_pid' => null,
        'started_at' => null,
        'cleared_at' => null,
    ]);
});

it('reconciles the completion marker and touches no other outbox column, row, or unrelated table', function (): void {
    $seeded = rebuildSeed();

    $beforeOutbox = (array) DB::table('payment_outbox')->where('event_id', $seeded['event_id'])->first();
    expect($beforeOutbox['projection_completed_at'])->toBeNull();

    [$exitCode] = rebuildRun();
    expect($exitCode)->toBe(RebuildOutcome::EXIT_DONE);

    $afterOutbox = (array) DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_outbox')->where('event_id', $seeded['event_id'])->first();

    expect($afterOutbox['projection_completed_at'])->not->toBeNull();

    $comparable = $afterOutbox;
    unset($comparable['projection_completed_at'], $comparable['updated_at']);
    $beforeComparable = $beforeOutbox;
    unset($beforeComparable['projection_completed_at'], $beforeComparable['updated_at']);

    expect($comparable)->toBe($beforeComparable);

    expect(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_outbox')->count())->toBe(1);

    expect(DB::connection(IntegrationDatabase::OBSERVER)->table('stored_events')->count())->toBe(1)
        ->and(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_idempotency_receipts')->count())->toBe(0);
});

it('takes the duplicate path harmlessly when a stale delivery arrives after a successful rebuild', function (): void {
    $seeded = rebuildSeed();

    [$exitCode] = rebuildRun();
    expect($exitCode)->toBe(RebuildOutcome::EXIT_DONE);

    $readRowAfterRebuild = rebuildReadRow($seeded['payment_id']);

    $outcome = resolve(PaymentProjectionConsumer::class)->project($seeded['event_id']);

    expect($outcome->value)->toBeIn(['already_projected', 'marker_repaired'])
        ->and(rebuildReadRow($seeded['payment_id']))->toBe($readRowAfterRebuild);
});

it('the lock half of the fence catches a live, un-taken-over session that has lost the lock', function (): void {
    $first = rebuildSeed();
    $second = rebuildSeed();

    rebuildWithConfig(batchSize: 1);

    $capturedToken = null;
    $commits = 0;

    Event::listen(TransactionCommitted::class, function () use (&$commits, &$capturedToken): void {
        $commits++;

        if ($commits !== 3) {
            return;
        }

        $capturedToken = DB::table('payment_projection_controls')
            ->where('projection', 'payment_details')->value('owner_token');

        DB::connection()->select(
            'select pg_advisory_unlock(?, ?)',
            [ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID],
        );
    });

    [$exitCode, $result] = rebuildRun();

    Event::forget(TransactionCommitted::class);

    expect($exitCode)->toBe(RebuildOutcome::EXIT_FAILED)
        ->and($result['outcome'])->toBe(RebuildOutcome::OUTCOME_FAILED)
        ->and($result['reason'])->toBe(RebuildReason::OwnershipLost->value)
        ->and($result['batches_committed'])->toBe(1);

    $control = rebuildControlRow();
    expect($control['state'])->toBe('rebuilding')
        ->and($control['cleared_at'])->not->toBeNull();

    expect(rebuildReadRow($first['payment_id']))->not->toBeNull()
        ->and(rebuildReadRow($second['payment_id']))->toBeNull();

    expect($capturedToken)->not->toBeNull();

    $tokenOnlyStillOwned = DB::table('payment_projection_controls')
        ->where('projection', 'payment_details')
        ->where('state', ProjectionGate::REBUILDING)
        ->where('owner_token', $capturedToken)
        ->exists();

    expect($tokenOnlyStillOwned)->toBeTrue();

    expect(DB::connection()->selectOne('select 1 as ok')?->ok)->toEqual(1);
});

it('checkpoint 2: a takeover between batches is caught by the token check, and the run never sets ready', function (): void {
    $first = rebuildSeed();
    $second = rebuildSeed();

    rebuildWithConfig(batchSize: 1);

    $commits = 0;
    Event::listen(TransactionCommitted::class, function () use (&$commits): void {
        $commits++;

        if ($commits !== 3) {
            return;
        }

        DB::connection(IntegrationDatabase::LOCK)
            ->table('payment_projection_controls')
            ->where('projection', 'payment_details')
            ->update(['owner_token' => (string) Str::uuid7(), 'updated_at' => now()]);
    });

    [$exitCode, $result] = rebuildRun();

    Event::forget(TransactionCommitted::class);

    expect($exitCode)->toBe(RebuildOutcome::EXIT_FAILED)
        ->and($result['outcome'])->toBe(RebuildOutcome::OUTCOME_FAILED)
        ->and($result['reason'])->toBe(RebuildReason::OwnershipLost->value)
        ->and($result['batches_committed'])->toBe(1)
        ->and($result['events_replayed'])->toBe(1);

    $control = rebuildControlRow();
    expect($control['state'])->toBe('rebuilding')
        ->and($control['cleared_at'])->not->toBeNull();

    expect(rebuildReadRow($first['payment_id']))->not->toBeNull()
        ->and(rebuildReadRow($second['payment_id']))->toBeNull();

    expect(fn () => DB::connection()->reconnect())->not->toThrow(RuntimeException::class);
});

it('checkpoint 3: ownership lost before the ready transition never reopens the gate', function (): void {
    rebuildSeed();

    rebuildWithConfig(batchSize: 500);

    $commits = 0;
    Event::listen(TransactionCommitted::class, function () use (&$commits): void {
        $commits++;

        if ($commits !== 3) {
            return;
        }

        DB::connection(IntegrationDatabase::LOCK)
            ->table('payment_projection_controls')
            ->where('projection', 'payment_details')
            ->update(['owner_token' => (string) Str::uuid7(), 'updated_at' => now()]);
    });

    [$exitCode, $result] = rebuildRun();

    Event::forget(TransactionCommitted::class);

    expect($exitCode)->toBe(RebuildOutcome::EXIT_FAILED)
        ->and($result['reason'])->toBe(RebuildReason::OwnershipLost->value);

    expect(rebuildControlRow()['state'])->toBe('rebuilding');
});

it('checkpoint 2: a pinned session terminated between batches aborts the run with no further write', function (): void {
    $first = rebuildSeed();
    $second = rebuildSeed();

    rebuildWithConfig(batchSize: 1);

    $commits = 0;
    Event::listen(TransactionCommitted::class, function () use (&$commits): void {
        $commits++;

        if ($commits !== 3) {
            return;
        }

        // The rebuild's pid comes from the observer, never from the connection under test.
        $observer = DB::connection(IntegrationDatabase::OBSERVER);
        $lockQuery = <<<'SQL'
            select pid from pg_locks
             where locktype = 'advisory' and mode = 'ExclusiveLock' and granted
               and classid = ? and objid = ?
            SQL;
        $lockArgs = [ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID];

        $pid = $observer->selectOne($lockQuery, $lockArgs)?->pid;

        if ($pid === null) {
            return;
        }

        $observer->select('select pg_terminate_backend(?)', [(int) $pid]);

        // Termination is asynchronous, so wait until pg_locks shows the lock gone.
        $deadline = microtime(true) + 5.0;

        while ($observer->selectOne($lockQuery, $lockArgs) !== null) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The terminated rebuild backend never released its exclusive gate lock.');
            }

            Sleep::usleep(10_000);
        }
    });

    [$exitCode, $result] = rebuildRun();

    Event::forget(TransactionCommitted::class);

    expect($exitCode)->toBe(RebuildOutcome::EXIT_FAILED)
        ->and($result['outcome'])->toBe(RebuildOutcome::OUTCOME_FAILED)
        ->and($result['batches_committed'])->toBe(1);

    $control = rebuildControlRow();
    expect($control['state'])->toBe('rebuilding')
        ->and($control['cleared_at'])->not->toBeNull();

    expect(rebuildReadRow($first['payment_id']))->not->toBeNull()
        ->and(rebuildReadRow($second['payment_id']))->toBeNull();

    // The default connection was killed; purge it for later tests.
    DB::purge();
});

it('reports a controlled failure when the connection is lost right after acquiring the exclusive lock, before any control-row write', function (): void {
    $seeded = rebuildSeed();

    $armed = true;
    DB::listen(function ($query) use (&$armed): void {
        if (! $armed || ! str_contains($query->sql, 'pg_backend_pid')) {
            return;
        }

        $armed = false;

        throw new RuntimeException('simulated connection loss right after acquiring the exclusive lock');
    });

    [$exitCode, $result] = rebuildRun();

    Event::forget(QueryExecuted::class);

    expect($exitCode)->toBe(RebuildOutcome::EXIT_FAILED)
        ->and($result['outcome'])->toBe(RebuildOutcome::OUTCOME_FAILED)
        ->and($result['reason'])->toBe(RebuildReason::DatabaseError->value)
        ->and($result['high_watermark'])->toBeNull()
        ->and($result['events_replayed'])->toBeNull()
        ->and($result['batches_committed'])->toBeNull();

    $control = rebuildControlRow();
    expect($control['state'])->toBe('ready')
        ->and($control['owner_token'])->toBeNull()
        ->and($control['owner_pid'])->toBeNull()
        ->and($control['started_at'])->toBeNull()
        ->and($control['cleared_at'])->toBeNull();
    expect(rebuildReadRow($seeded['payment_id']))->toBeNull();

    expect(rebuildLockHeld())->toBeFalse();

    expect(fn () => DB::connection()->reconnect())->not->toThrow(RuntimeException::class);
});

it('never begins the mutation when operator intent cannot be recorded, and still releases the lock', function (): void {
    $seeded = rebuildSeed();

    rebuildWithFailingLogger(failOnCall: 1);

    [$exitCode, $result] = rebuildRun();

    expect($exitCode)->toBe(RebuildOutcome::EXIT_FAILED)
        ->and($result['outcome'])->toBe(RebuildOutcome::OUTCOME_FAILED)
        ->and($result['reason'])->toBe(RebuildReason::IntentAuditFailed->value)
        ->and($result['high_watermark'])->toBeNull()
        ->and($result['events_replayed'])->toBeNull()
        ->and($result['batches_committed'])->toBeNull();

    expect(rebuildControlRow()['state'])->toBe('ready')
        ->and(rebuildReadRow($seeded['payment_id']))->toBeNull();

    expect(rebuildLockHeld())->toBeFalse();

    expect(fn () => DB::connection()->reconnect())->not->toThrow(RuntimeException::class);
});

it('reports a completed rebuild whose own completion record failed, never as a database error', function (): void {
    $seeded = rebuildSeed();

    rebuildWithFailingLogger(failOnCall: 2);

    [$exitCode, $result] = rebuildRun();

    expect($exitCode)->toBe(RebuildOutcome::EXIT_FAILED)
        ->and($result['outcome'])->toBe(RebuildOutcome::OUTCOME_FAILED)
        ->and($result['reason'])->toBe(RebuildReason::AuditFailed->value)
        ->and($result['batches_committed'])->toBe(1)
        ->and($result['events_replayed'])->toBe(1);

    expect(rebuildControlRow())->toMatchArray([
        'state' => 'ready',
        'owner_token' => null,
        'owner_pid' => null,
        'started_at' => null,
        'cleared_at' => null,
    ]);
    expect(rebuildReadRow($seeded['payment_id']))->not->toBeNull();

    expect(rebuildLockHeld())->toBeFalse();
    expect(fn () => DB::connection()->reconnect())->not->toThrow(RuntimeException::class);
});

/**
 * Callers must forget the TransactionCommitted listener and purge the connection afterwards.
 */
function killAfterCommit(int $targetCommit): void
{
    $commits = 0;
    $connection = DB::connection();
    $pid = (int) $connection->selectOne('select pg_backend_pid() as pid')->pid;

    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use (&$commits, $targetCommit, $connection, $pid): void {
        if ($event->connection !== $connection) {
            return;
        }

        $commits++;

        if ($commits !== $targetCommit) {
            return;
        }

        $observer = DB::connection(IntegrationDatabase::OBSERVER);
        $lockQuery = <<<'SQL'
            select pid from pg_locks
             where locktype = 'advisory' and mode = 'ExclusiveLock' and granted
               and database = (select oid from pg_database where datname = current_database())
               and classid = ? and objid = ? and objsubid = 2 and pid = ?
            SQL;
        $lockArgs = [ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID, $pid];

        if ($observer->selectOne($lockQuery, $lockArgs) === null) {
            throw new RuntimeException('The target rebuild backend does not hold the exclusive gate lock.');
        }

        $observer->select('select pg_terminate_backend(?)', [(int) $pid]);

        $deadline = microtime(true) + 5.0;

        while ($observer->selectOne($lockQuery, $lockArgs) !== null) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The terminated rebuild backend never released its exclusive gate lock.');
            }

            Sleep::usleep(10_000);
        }
    });
}

function markDeadRebuildWithClearedAt(?string $clearedAt): string
{
    $token = (string) Str::uuid7();

    DB::table('payment_projection_controls')->where('projection', 'payment_details')->update([
        'state' => 'rebuilding',
        'owner_token' => $token,
        'owner_pid' => 999_999,
        'started_at' => now(),
        'cleared_at' => $clearedAt,
        'updated_at' => now(),
    ]);

    return $token;
}

function expectAbandonedRebuildGateClosed(): void
{
    expect(rebuildLockHeld())->toBeFalse();
    $observer = DB::connection(IntegrationDatabase::OBSERVER);
    $state = $observer->transaction(fn (): ProjectionGateState => new ProjectionGate($observer)->inspectPaymentDetails());
    expect($state)->toBe(ProjectionGateState::ClosedByControlState);
}

it('guarded recovery sets ready without replay when a takeover finds cleared_at still null', function (): void {
    $seeded = rebuildSeed();
    resolve(PaymentProjectionConsumer::class)->project($seeded['event_id']);
    $projectedBefore = rebuildReadRow($seeded['payment_id']);
    $receiptsBefore = DB::table('payment_projector_receipts')->get()->all();
    $outboxBefore = DB::table('payment_outbox')->get()->all();
    expect($projectedBefore)->not->toBeNull();

    killAfterCommit(1);
    [$exit1] = rebuildRun();
    Event::forget(TransactionCommitted::class);
    DB::purge();

    expect($exit1)->toBe(RebuildOutcome::EXIT_FAILED);

    $afterRun1 = rebuildControlRow();
    expect($afterRun1['state'])->toBe('rebuilding')
        ->and($afterRun1['cleared_at'])->toBeNull();
    expectAbandonedRebuildGateClosed();

    rebuildWithConfig();
    [$exit2, $result2] = rebuildRun();

    expect($exit2)->toBe(RebuildOutcome::EXIT_DONE)
        ->and($result2['outcome'])->toBe(RebuildOutcome::OUTCOME_COMPLETED)
        ->and($result2['guarded_recovery'])->toBeTrue()
        ->and($result2['events_replayed'])->toBe(0)
        ->and($result2['batches_committed'])->toBe(0)
        ->and($result2['high_watermark'])->toBeNull();

    $afterRun2 = rebuildControlRow();
    expect($afterRun2['state'])->toBe('ready')
        ->and($afterRun2['cleared_at'])->toBeNull()
        ->and($afterRun2['owner_token'])->toBeNull();

    expect(rebuildReadRow($seeded['payment_id']))->toBe($projectedBefore);
    expect(DB::table('payment_projector_receipts')->get()->all())->toEqual($receiptsBefore)
        ->and(DB::table('payment_outbox')->get()->all())->toEqual($outboxBefore);
});

it('never takes the guarded-recovery shortcut when cleared_at is non-null, even if the read model looks complete', function (): void {
    $seeded = rebuildSeed();
    resolve(PaymentProjectionConsumer::class)->project($seeded['event_id']);
    $projectedBefore = rebuildReadRow($seeded['payment_id']);

    markDeadRebuildWithClearedAt(now()->toIso8601String());

    rebuildWithConfig();
    [$exitCode, $result] = rebuildRun();

    expect($exitCode)->toBe(RebuildOutcome::EXIT_DONE)
        ->and($result['guarded_recovery'])->toBeFalse()
        ->and($result['events_replayed'])->toBe(1)
        ->and($result['batches_committed'])->toBe(1);

    expect(rebuildReadRow($seeded['payment_id']))->toBe($projectedBefore);

    $control = rebuildControlRow();
    expect($control['state'])->toBe('ready')
        ->and($control['cleared_at'])->toBeNull();
});

it('the non-vacuous control: resetting cleared_at on a takeover would wrongly permit guarded recovery', function (): void {
    $seeded = rebuildSeed();

    markDeadRebuildWithClearedAt(now()->toIso8601String());
    expect(rebuildReadRow($seeded['payment_id']))->toBeNull();

    DB::table('payment_projection_controls')->where('projection', 'payment_details')
        ->update(['cleared_at' => null]);

    rebuildWithConfig();
    [$exitCode, $result] = rebuildRun();

    expect($exitCode)->toBe(RebuildOutcome::EXIT_DONE)
        ->and($result['guarded_recovery'])->toBeTrue()
        ->and(rebuildReadRow($seeded['payment_id']))->toBeNull();
});

it('a takeover that fails before its own clear leaves the inherited cleared_at intact, and a third run is still refused the shortcut', function (): void {
    $seeded = rebuildSeed();

    killAfterCommit(2);
    [$exit1] = rebuildRun();
    Event::forget(TransactionCommitted::class);
    DB::purge();

    expect($exit1)->toBe(RebuildOutcome::EXIT_FAILED);

    $afterRun1 = rebuildControlRow();
    $clearedAtAfterRun1 = $afterRun1['cleared_at'];
    expect($clearedAtAfterRun1)->not->toBeNull();
    expectAbandonedRebuildGateClosed();

    rebuildWithConfig();
    killAfterCommit(1);
    [$exit2] = rebuildRun();
    Event::forget(TransactionCommitted::class);
    DB::purge();

    expect($exit2)->toBe(RebuildOutcome::EXIT_FAILED);

    $afterRun2 = rebuildControlRow();
    expect($afterRun2['state'])->toBe('rebuilding')
        ->and($afterRun2['cleared_at'])->toBe($clearedAtAfterRun1)
        ->and($afterRun2['owner_token'])->not->toBeNull()
        ->and($afterRun2['owner_token'])->not->toBe($afterRun1['owner_token'])
        ->and($afterRun2['owner_pid'])->not->toBe($afterRun1['owner_pid'])
        ->and($afterRun2['started_at'])->not->toBe($afterRun1['started_at']);
    expectAbandonedRebuildGateClosed();

    rebuildWithConfig();
    [$exit3, $result3] = rebuildRun();

    expect($exit3)->toBe(RebuildOutcome::EXIT_DONE)
        ->and($result3['guarded_recovery'])->toBeFalse()
        ->and($result3['events_replayed'])->toBe(1);

    expect(rebuildControlRow())->toMatchArray([
        'state' => 'ready',
        'cleared_at' => null,
    ]);
});

it('preserves cleared_at across a chain of clear, kill, takeover, kill, takeover, until a replay completes', function (): void {
    $seeded = rebuildSeed();
    $second = rebuildSeed();
    $third = rebuildSeed();

    rebuildWithConfig(batchSize: 1);
    killAfterCommit(3);
    [$exit1, $result1] = rebuildRun();
    Event::forget(TransactionCommitted::class);
    DB::purge();

    expect($exit1)->toBe(RebuildOutcome::EXIT_FAILED)
        ->and($result1['reason'])->toBe(RebuildReason::DatabaseError->value)
        ->and($result1['events_replayed'])->toBe(1)
        ->and($result1['batches_committed'])->toBe(1)
        ->and(rebuildReadRow($seeded['payment_id']))->not->toBeNull()
        ->and(rebuildReadRow($second['payment_id']))->toBeNull();
    expectAbandonedRebuildGateClosed();

    $afterRun1 = rebuildControlRow();
    expect($afterRun1['state'])->toBe('rebuilding')
        ->and($afterRun1['cleared_at'])->not->toBeNull();

    $clearedAtAfterRun1 = $afterRun1['cleared_at'];

    rebuildWithConfig(batchSize: 1);
    killAfterCommit(2);
    [$exit2] = rebuildRun();
    Event::forget(TransactionCommitted::class);
    DB::purge();

    expect($exit2)->toBe(RebuildOutcome::EXIT_FAILED);

    $afterRun2 = rebuildControlRow();
    expect($afterRun2['state'])->toBe('rebuilding')
        ->and($afterRun2['cleared_at'])->not->toBeNull()
        ->and($afterRun2['cleared_at'])->toBe($clearedAtAfterRun1)
        ->and($afterRun2['owner_token'])->not->toBe($afterRun1['owner_token'])
        ->and(rebuildReadRow($seeded['payment_id']))->toBeNull();
    expectAbandonedRebuildGateClosed();

    rebuildWithConfig(batchSize: 1);
    $commits = 0;
    $markers = [];
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use (&$commits, &$markers, $seeded, $second): void {
        if ($event->connection !== DB::connection()) {
            return;
        }

        $commits++;
        $markers[] = rebuildControlRow()['cleared_at'];

        if ($commits === 3) {
            expect(rebuildReadRow($seeded['payment_id']))->not->toBeNull()
                ->and(rebuildReadRow($second['payment_id']))->toBeNull();
        }
    });
    [$exit3, $result3] = rebuildRun();
    Event::forget(TransactionCommitted::class);

    expect($exit3)->toBe(RebuildOutcome::EXIT_DONE)
        ->and($result3['outcome'])->toBe(RebuildOutcome::OUTCOME_COMPLETED)
        ->and($result3['guarded_recovery'])->toBeFalse()
        ->and($result3['reason'])->toBe(RebuildReason::RebuildComplete->value)
        ->and($result3['events_replayed'])->toBe(3)
        ->and($result3['batches_committed'])->toBe(3)
        ->and($markers)->toBe([...array_fill(0, 6, $clearedAtAfterRun1), null]);

    $afterRun3 = rebuildControlRow();
    expect($afterRun3['state'])->toBe('ready')
        ->and($afterRun3['cleared_at'])->toBeNull()
        ->and($afterRun3['owner_token'])->toBeNull();

    foreach ([$seeded, $second, $third] as $event) {
        expect(rebuildReadRow($event['payment_id']))->not->toBeNull();
    }
});

it('refuses a second rebuild against a live owner, never reaching phase 2, and the live owners token survives', function (): void {
    rebuildSeed();

    $liveOwner = DB::connection(IntegrationDatabase::LOCK);
    $liveOwner->select(
        'select pg_advisory_lock(?, ?)',
        [ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID],
    );

    $liveToken = (string) Str::uuid7();
    DB::table('payment_projection_controls')->where('projection', 'payment_details')->update([
        'state' => 'rebuilding',
        'owner_token' => $liveToken,
        'owner_pid' => ProjectionGateLocks::pid(IntegrationDatabase::LOCK),
        'started_at' => now(),
        'updated_at' => now(),
    ]);
    $before = rebuildControlRow();

    rebuildWithConfig(lockTimeoutMs: 300);

    $startedAt = hrtime(true);
    [$exitCode, $result] = rebuildRun();
    $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

    expect($elapsedMs)->toBeGreaterThanOrEqual(250);
    expect($exitCode)->toBe(RebuildOutcome::EXIT_REFUSED)
        ->and($result['reason'])->toBe(RebuildReason::LockTimeout->value);

    $control = rebuildControlRow();
    expect($control['state'])->toBe('rebuilding')
        ->and($control['owner_token'])->toBe($liveToken)
        ->and($control)->toBe($before)
        ->and(rebuildLockHeld())->toBeTrue();
});

it('guarded recovery keeps the ready fence and cannot reopen traffic after losing its lock', function (): void {
    rebuildSeed();
    markDeadRebuildWithClearedAt(null);

    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
        if ($event->connection === DB::connection()) {
            $event->connection->select('select pg_advisory_unlock(?, ?)', [
                ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID,
            ]);
        }
    });

    [$exitCode, $result] = rebuildRun();
    Event::forget(TransactionCommitted::class);

    expect($exitCode)->toBe(RebuildOutcome::EXIT_FAILED)
        ->and($result['reason'])->toBe(RebuildReason::OwnershipLost->value)
        ->and($result['guarded_recovery'])->toBeTrue()
        ->and(rebuildControlRow()['state'])->toBe('rebuilding')
        ->and(rebuildControlRow()['cleared_at'])->toBeNull();
    expectAbandonedRebuildGateClosed();
});

it('reports completed guarded recovery accurately when its completion audit fails', function (): void {
    $seeded = rebuildSeed();
    resolve(PaymentProjectionConsumer::class)->project($seeded['event_id']);
    $before = rebuildReadRow($seeded['payment_id']);
    markDeadRebuildWithClearedAt(null);
    rebuildWithFailingLogger(2);

    [$exitCode, $result] = rebuildRun();

    expect($exitCode)->toBe(RebuildOutcome::EXIT_FAILED)
        ->and($result['reason'])->toBe(RebuildReason::AuditFailed->value)
        ->and($result['guarded_recovery'])->toBeTrue()
        ->and($result['high_watermark'])->toBeNull()
        ->and($result['events_replayed'])->toBe(0)
        ->and($result['batches_committed'])->toBe(0)
        ->and(rebuildControlRow()['state'])->toBe('ready')
        ->and(rebuildReadRow($seeded['payment_id']))->toBe($before)
        ->and(rebuildLockHeld())->toBeFalse();
});

it('gates verification and repair during a real rebuild and verifies the rebuilt canonical state', function (): void {
    $seeded = rebuildSeed();
    resolve(PaymentProjectionConsumer::class)->project($seeded['event_id']);
    DB::table('payment_details')->where('payment_id', $seeded['payment_id'])->update(['amount_minor' => 9_999]);
    expect(Artisan::call('payments:projections:verify'))->toBe(VerificationReport::EXIT_INCONSISTENT);

    $observed = [];
    $commits = 0;
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use (&$commits, &$observed, $seeded): void {
        if ($event->connection !== DB::connection()) {
            return;
        }

        $commits++;

        if ($commits !== 2) {
            return;
        }

        $connection = DB::connection(IntegrationDatabase::LOCK);
        $observed['verify'] = new DatabaseProjectionVerifier(
            $connection, resolve(VerificationConfiguration::class), static fn (): int => (int) hrtime(true),
        )->verify();
        $observed['repair'] = new DatabaseOutboxRepair($connection, resolve(LoggerInterface::class))
            ->repair($seeded['event_id'], true, (string) Str::uuid7());
        $observed['row'] = rebuildReadRow($seeded['payment_id']);
    });

    [$exitCode] = rebuildRun();
    Event::forget(TransactionCommitted::class);
    expect($exitCode)->toBe(RebuildOutcome::EXIT_DONE)
        ->and($observed['row'])->toBeNull()
        ->and($observed['verify']->exitCode())->toBe(VerificationReport::EXIT_INCOMPLETE)
        ->and($observed['verify']->reason)->toBe('rebuild_in_progress')
        ->and($observed['verify']->projectionControlState)->toBe('rebuilding')
        ->and($observed['verify']->scanned)->toBe([])
        ->and($observed['repair']->exitCode())->toBe(RepairOutcome::EXIT_REFUSED)
        ->and($observed['repair']->reason->value)->toBe('rebuild_in_progress')
        ->and($observed['repair']->changedFields)->toBe([])
        ->and(Artisan::call('payments:projections:verify'))->toBe(VerificationReport::EXIT_CONSISTENT);
});

it('reports an uncertain commit without discarding durable recovery evidence', function (int $failedCommit, bool $guarded): void {
    $seeded = rebuildSeed();
    resolve(PaymentProjectionConsumer::class)->project($seeded['event_id']);

    if ($guarded) {
        markDeadRebuildWithClearedAt(null);
    }

    $commits = 0;
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use (&$commits, $failedCommit): void {
        if ($event->connection !== DB::connection()) {
            return;
        }

        $commits++;

        if ($commits === $failedCommit) {
            throw new RuntimeException('Simulated missing commit reply: private diagnostic');
        }
    });

    [$exitCode, $result] = rebuildRun();
    Event::forget(TransactionCommitted::class);
    $control = rebuildControlRow();
    $readyCommitted = $guarded || $failedCommit === 4;

    expect($exitCode)->toBe(RebuildOutcome::EXIT_FAILED)
        ->and($result['reason'])->toBe(RebuildReason::CommitOutcomeUnknown->value)
        ->and(json_encode($result))->not->toContain('private diagnostic')
        ->and($control['state'])->toBe($readyCommitted ? 'ready' : 'rebuilding')
        ->and($control['cleared_at'] === null)->toBe($readyCommitted || $failedCommit === 1)
        ->and(rebuildLockHeld())->toBeFalse();

    if (! $readyCommitted) {
        expectAbandonedRebuildGateClosed();
    }

    rebuildWithConfig();
    [$recovered, $recovery] = rebuildRun();
    expect($recovered)->toBe(RebuildOutcome::EXIT_DONE)
        ->and($recovery['guarded_recovery'])->toBe(! $guarded && $failedCommit === 1)
        ->and(rebuildControlRow()['state'])->toBe('ready')
        ->and(rebuildControlRow()['cleared_at'])->toBeNull()
        ->and(rebuildReadRow($seeded['payment_id']))->not->toBeNull();
})->with([
    'claim commit' => [1, false],
    'clear commit' => [2, false],
    'batch commit' => [3, false],
    'ready commit' => [4, false],
    'guarded recovery ready commit' => [2, true],
]);
