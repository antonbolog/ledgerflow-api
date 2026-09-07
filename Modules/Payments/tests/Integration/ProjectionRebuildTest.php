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

/*
|--------------------------------------------------------------------------
| The bounded batch rebuild — `payments:projections:rebuild`
|--------------------------------------------------------------------------
|
| IN THE INTEGRATION SUITE, FOR THE SAME REASON EVERY OTHER COMMIT-BOUNDARY
| SUITE IS. The rebuild pins one connection across a real session-level
| advisory lock and several real transactions; under RefreshDatabase every
| commit becomes a savepoint, the session-level lock would outlive nothing it
| is supposed to, and every fence and kill scenario below would be vacuous.
|
| THE COMMAND IS INVOKED THROUGH Artisan, so the signature, the option, the
| container bindings and the registration are all exercised, matching
| PaymentOutboxRepairTest's own rule.
|
*/

/** One canonical event with its outbox envelope, exactly as T6 leaves them. */
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

/** A non-Payments canonical row, so the replay walk must step over an ID gap. */
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

/** The rebuild as an operator runs it: exit code plus the one JSON object it printed. */
function rebuildRun(bool $confirm = true): array
{
    $exitCode = Artisan::call('payments:projections:rebuild', $confirm ? ['--confirm-rebuild' => true] : []);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    return [$exitCode, $decoded];
}

/** Rebind the port with a small batch size and short bounds, for tests that need multiple batches or a fast timeout. */
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

    // Artisan caches command instances. A new operator attempt after DB::purge()
    // needs the new adapter and connection, as a fresh CLI process would have.
    app()->forgetInstance(RebuildPaymentProjectionsCommand::class);
    Artisan::registerCommand(resolve(RebuildPaymentProjectionsCommand::class));
}

/** Rebind the port with a logger that throws on its $failOnCall'th record. */
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

/** Whether ANY session currently holds the gate's exclusive lock, witnessed from the OBSERVER. */
function rebuildLockHeld(): bool
{
    $locks = ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID);

    return array_filter($locks, static fn (array $lock): bool => $lock['mode'] === 'ExclusiveLock') !== [];
}

/** The durable control row, from the OBSERVER so only committed state counts. */
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

    DB::table('payment_projection_controls')->where('projection', 'payment_details')->update([
        'state' => 'ready',
        'owner_token' => null,
        'owner_pid' => null,
        'started_at' => null,
        'cleared_at' => null,
        'updated_at' => now(),
    ]);
});

afterEach(function (): void {
    DB::statement('set lock_timeout = default');

    IntegrationDatabase::rollbackOpenTransactions();
    IntegrationDatabase::releaseLocks();
    ProjectionGateLocks::releaseAll(IntegrationDatabase::LOCK);
    ProjectionGateLocks::releaseAll(DB::getDefaultConnection());

    // UNCONDITIONAL, per every other T11 suite's own rule: a test that closes
    // the gate and fails before reopening it must not leak `rebuilding` into a
    // later test.
    DB::table('payment_projection_controls')->where('projection', 'payment_details')->update([
        'state' => 'ready',
        'owner_token' => null,
        'owner_pid' => null,
        'started_at' => null,
        'cleared_at' => null,
        'updated_at' => now(),
    ]);

    IntegrationDatabase::truncate();
    IntegrationDatabase::disconnectAll();
});

/*
|--------------------------------------------------------------------------
| Refusals — nothing written
|--------------------------------------------------------------------------
*/

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
    // A ROW-SHAPED FIXTURE EXISTS, so a rebuild that proceeded regardless would
    // be observable: it would clear this row.
    $seeded = rebuildSeed();

    // A shared holder, exactly as an in-flight POST/GET/relay/consumer
    // transaction would be, on a SEPARATE connection from the one the rebuild
    // pins.
    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $gate = new ProjectionGate($lock);
    expect($gate->inspectPaymentDetails()->isOpen())->toBeTrue();

    rebuildWithConfig(lockTimeoutMs: 300);

    $startedAt = hrtime(true);
    [$exitCode, $result] = rebuildRun();
    $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

    // A REAL BLOCKING WAIT, not an instant refusal: phase 1 genuinely waited
    // close to the configured bound before giving up.
    expect($elapsedMs)->toBeGreaterThanOrEqual(250);

    expect($exitCode)->toBe(RebuildOutcome::EXIT_REFUSED)
        ->and($result['outcome'])->toBe(RebuildOutcome::OUTCOME_REFUSED)
        ->and($result['reason'])->toBe(RebuildReason::LockTimeout->value);

    // NO CONTROL-ROW COLUMN WRITTEN AT ALL, and nothing cleared.
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

    // THE MUTATION: with the holder released, the identical invocation
    // succeeds - proving the refusal above was genuine contention rather than
    // an unconditional one.
    $lock->rollBack();

    [$exitCode, $result] = rebuildRun();

    expect($exitCode)->toBe(RebuildOutcome::EXIT_DONE)
        ->and($result['outcome'])->toBe(RebuildOutcome::OUTCOME_COMPLETED);
});

it('refuses inside an already-open transaction, matching the verifier and repair guard', function (): void {
    DB::connection()->beginTransaction();

    [$exitCode, $result] = rebuildRun();

    expect($exitCode)->toBe(RebuildOutcome::EXIT_FAILED)
        ->and($result['reason'])->toBe(RebuildReason::DatabaseError->value);

    DB::connection()->rollBack();
});

/*
|--------------------------------------------------------------------------
| The happy path: clear, replay, and business-column equality with the consumer
|--------------------------------------------------------------------------
*/

it('replays bounded batches across an ID gap and reproduces the columns the consumer would have written', function (): void {
    $first = rebuildSeed(occurredAt: '2026-08-21T10:11:12.123456+00:00');
    rebuildForeignGap();
    $second = rebuildSeed(occurredAt: '2026-08-21T11:22:33.654321+00:00');

    // PROJECT VIA THE REAL CONSUMER FIRST, so the comparison is against actual
    // production output rather than a hand-built expectation.
    resolve(PaymentProjectionConsumer::class)->project($first['event_id']);
    resolve(PaymentProjectionConsumer::class)->project($second['event_id']);

    $before = [
        $first['payment_id'] => rebuildReadRow($first['payment_id']),
        $second['payment_id'] => rebuildReadRow($second['payment_id']),
    ];

    expect($before[$first['payment_id']])->not->toBeNull()
        ->and($before[$second['payment_id']])->not->toBeNull();

    // ONE EVENT PER BATCH, so this run genuinely walks more than one batch
    // across the ID gap the foreign row leaves.
    rebuildWithConfig(batchSize: 1);

    [$exitCode, $result] = rebuildRun();

    expect($exitCode)->toBe(RebuildOutcome::EXIT_DONE)
        ->and($result['outcome'])->toBe(RebuildOutcome::OUTCOME_COMPLETED)
        ->and($result['reason'])->toBe(RebuildReason::RebuildComplete->value)
        ->and($result['events_replayed'])->toBe(2)
        ->and($result['batches_committed'])->toBe(2);

    // BYTE-FOR-BYTE EQUAL TO WHAT THE CONSUMER PRODUCED, including
    // initiated_at at microsecond precision.
    expect(rebuildReadRow($first['payment_id']))->toBe($before[$first['payment_id']])
        ->and(rebuildReadRow($second['payment_id']))->toBe($before[$second['payment_id']]);

    // Receipts re-created for exactly the named projector.
    $receipts = DB::connection(IntegrationDatabase::OBSERVER)->table('payment_projector_receipts')->get();
    expect($receipts)->toHaveCount(2);
    foreach ($receipts as $receipt) {
        expect($receipt->projector_name)->toBe(PaymentProjectionConsumer::PROJECTOR_NAME);
    }

    // The gate reopened: ready, no owner, no clear marker.
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

    // EVERY OTHER COLUMN, byte for byte: transport history, failure counters
    // and every timestamp T7/T8 own are untouched.
    $comparable = $afterOutbox;
    unset($comparable['projection_completed_at'], $comparable['updated_at']);
    $beforeComparable = $beforeOutbox;
    unset($beforeComparable['projection_completed_at'], $beforeComparable['updated_at']);

    expect($comparable)->toBe($beforeComparable);

    // No envelope created or deleted: exactly the one row seeded.
    expect(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_outbox')->count())->toBe(1);

    // stored_events, snapshots and idempotency receipts: never touched.
    expect(DB::connection(IntegrationDatabase::OBSERVER)->table('stored_events')->count())->toBe(1)
        ->and(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_idempotency_receipts')->count())->toBe(0);
});

it('takes the duplicate path harmlessly when a stale delivery arrives after a successful rebuild', function (): void {
    $seeded = rebuildSeed();

    [$exitCode] = rebuildRun();
    expect($exitCode)->toBe(RebuildOutcome::EXIT_DONE);

    $readRowAfterRebuild = rebuildReadRow($seeded['payment_id']);

    // A queued duplicate delivered after the rebuild: the receipt it inserted
    // conflicts with the recreated one, and it repairs at most the marker,
    // which is already set.
    $outcome = resolve(PaymentProjectionConsumer::class)->project($seeded['event_id']);

    expect($outcome->value)->toBeIn(['already_projected', 'marker_repaired'])
        ->and(rebuildReadRow($seeded['payment_id']))->toBe($readRowAfterRebuild);
});

/*
|--------------------------------------------------------------------------
| The ownership fence
|--------------------------------------------------------------------------
*/

it('the lock half of the fence catches a live, un-taken-over session that has lost the lock', function (): void {
    // ISOLATES THE LOCK CHECK FROM EVERYTHING ELSE THAT COULD CATCH A PROBLEM
    // HERE. State and token are untouched throughout - no takeover happens,
    // so the row-level half of the fence would pass on its own. The
    // connection is never killed or reconnected, so F3's reconnector defence
    // never fires either: the ONLY thing wrong at the moment of the second
    // batch's fence check is that this exact session's exclusive lock is
    // gone. If the lock check were removed, this run would proceed to write
    // the second row on a session holding no lock.
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

        // Captured before releasing the lock, so the non-vacuity control
        // below asks the identical question the real fence asked - minus the
        // lock half - at the identical moment.
        $capturedToken = DB::table('payment_projection_controls')
            ->where('projection', 'payment_details')->value('owner_token');

        // RELEASE THIS SESSION'S OWN LOCK, on the SAME connection the
        // rebuild is pinned to - not by killing anything. The connection
        // stays perfectly healthy for the very next statement.
        DB::connection()->select(
            'select pg_advisory_unlock(?, ?)',
            [ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID],
        );
    });

    [$exitCode, $result] = rebuildRun();

    Event::forget(TransactionCommitted::class);

    // THE FULL FENCE REFUSES. Never CommitOutcomeUnknown or DatabaseError:
    // the connection is healthy and the reconnector was never invoked, so
    // this is genuinely the lock-possession check, not F3's defence or a
    // classification accident.
    expect($exitCode)->toBe(RebuildOutcome::EXIT_FAILED)
        ->and($result['outcome'])->toBe(RebuildOutcome::OUTCOME_FAILED)
        ->and($result['reason'])->toBe(RebuildReason::OwnershipLost->value)
        ->and($result['batches_committed'])->toBe(1);

    $control = rebuildControlRow();
    expect($control['state'])->toBe('rebuilding')
        ->and($control['cleared_at'])->not->toBeNull();

    expect(rebuildReadRow($first['payment_id']))->not->toBeNull()
        ->and(rebuildReadRow($second['payment_id']))->toBeNull();

    // THE NON-VACUOUS CONTROL: a token-only fence, asked the identical
    // question at the identical moment, would have answered "still owned" -
    // state was still `rebuilding` and the token still matched. This is
    // exactly what justifies the lock-possession half rather than a token
    // check alone.
    expect($capturedToken)->not->toBeNull();

    $tokenOnlyStillOwned = DB::table('payment_projection_controls')
        ->where('projection', 'payment_details')
        ->where('state', ProjectionGate::REBUILDING)
        ->where('owner_token', $capturedToken)
        ->exists();

    expect($tokenOnlyStillOwned)->toBeTrue();

    // The connection is provably still usable - the reconnector was never
    // exercised - which is what makes the refusal above the lock check's
    // doing and not F3's.
    expect(DB::connection()->selectOne('select 1 as ok')?->ok)->toEqual(1);
});

it('checkpoint 2: a takeover between batches is caught by the token check, and the run never sets ready', function (): void {
    $first = rebuildSeed();
    $second = rebuildSeed();

    rebuildWithConfig(batchSize: 1);

    // AFTER THE THIRD COMMIT - claim ownership, clear, then the FIRST replay
    // batch - a second session simulates a takeover by overwriting the owner
    // token directly: the effect D6 phase 2 of a real takeover would have,
    // without needing Slice 4's own takeover code to prove this run's fence
    // refuses to write past it. The second batch's own beginTransaction() is
    // the fourth commit attempt, and its fence check runs before anything else
    // in it.
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
        // The first batch's progress is reported even though the run failed.
        ->and($result['batches_committed'])->toBe(1)
        ->and($result['events_replayed'])->toBe(1);

    // NEVER `ready`, and the row still shows the takeover's own token - this
    // run wrote nothing further once it lost the fence.
    $control = rebuildControlRow();
    expect($control['state'])->toBe('rebuilding')
        ->and($control['cleared_at'])->not->toBeNull();

    // The first batch's row survives (already committed); the second was
    // never reached.
    expect(rebuildReadRow($first['payment_id']))->not->toBeNull()
        ->and(rebuildReadRow($second['payment_id']))->toBeNull();
});

it('checkpoint 3: ownership lost before the ready transition never reopens the gate', function (): void {
    rebuildSeed();

    rebuildWithConfig(batchSize: 500);

    // ONE EVENT FITS IN ONE BATCH, so the sequence is: claim (1), clear (2),
    // the single replay batch (3), then ready (4). Firing on the third commit
    // mutates the token after the last write before the ready transition,
    // whose own fence check is the very next statement.
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

    // AFTER THE THIRD COMMIT - claim, clear, the first replay batch -
    // terminate the pinned connection's own backend, so the second batch's
    // beginTransaction() runs on a dead socket.
    $commits = 0;
    Event::listen(TransactionCommitted::class, function () use (&$commits): void {
        $commits++;

        if ($commits !== 3) {
            return;
        }

        // The rebuild's own backend pid, read from the OBSERVER - never
        // self-reported by the connection under test.
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

        // THE BARRIER IS pg_locks, NOT A SLEEP - the same discipline
        // AdvisoryExclusiveWaiter uses. Termination is asynchronous, so the
        // next batch's beginTransaction() must not run until the observer
        // confirms this backend's session-level lock is genuinely gone.
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

    // Reconnection is disabled on the pinned connection, so the killed backend
    // surfaces as a database failure rather than a silent, unnoticed
    // reconnect - either way the run must abort and never reach `ready`.
    expect($exitCode)->toBe(RebuildOutcome::EXIT_FAILED)
        ->and($result['outcome'])->toBe(RebuildOutcome::OUTCOME_FAILED)
        ->and($result['batches_committed'])->toBe(1);

    $control = rebuildControlRow();
    expect($control['state'])->toBe('rebuilding')
        ->and($control['cleared_at'])->not->toBeNull();

    expect(rebuildReadRow($first['payment_id']))->not->toBeNull()
        ->and(rebuildReadRow($second['payment_id']))->toBeNull();

    // The default connection was killed out from under this test process;
    // purge it so later tests in this file resolve a fresh one.
    DB::purge();
});

/*
|--------------------------------------------------------------------------
| Failure after the exclusive acquisition, including the intent logger
|--------------------------------------------------------------------------
|
| PHASE 1 SUCCEEDS IN EVERY TEST BELOW - the failure is deliberately staged
| AFTER the exclusive lock is genuinely held, which is exactly the window a
| bare try/catch around the transactional steps alone would not cover: the
| intent log runs before claimOwnership() ever does, outside runStep()
| entirely.
*/

it('reports a controlled failure when the connection is lost right after acquiring the exclusive lock, before any control-row write', function (): void {
    // REALISTIC, NOT CONTRIVED: PostgreSQL can drop a session at any point,
    // including the instant between a successful pg_advisory_lock() and the
    // very next statement - here, backendPid()'s own read, the first thing
    // runClaimedRebuild() does. Injected via DB::listen() rather than a real
    // kill, so the failure lands deterministically on this exact statement
    // and nowhere else.
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

    // THE CONTROL ROW IS UNTOUCHED: this fails before claimOwnership() ever
    // runs, so there is no owner_token, no state change, nothing.
    $control = rebuildControlRow();
    expect($control['state'])->toBe('ready')
        ->and($control['owner_token'])->toBeNull()
        ->and($control['owner_pid'])->toBeNull()
        ->and($control['started_at'])->toBeNull()
        ->and($control['cleared_at'])->toBeNull();
    expect(rebuildReadRow($seeded['payment_id']))->toBeNull();

    // THE LOCK WAS RELEASED, witnessed from the OBSERVER: this failure still
    // passes through runUnderExclusiveLock()'s own `finally`.
    expect(rebuildLockHeld())->toBeFalse();

    // AND THE RECONNECTOR WAS RESTORED: this exit passes through rebuild()'s
    // own `finally` exactly as every other one does.
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

    // NOTHING WRITTEN: phase 1 acquired the lock (nothing else could have
    // raised this failure), but claimOwnership() never ran.
    expect(rebuildControlRow()['state'])->toBe('ready')
        ->and(rebuildReadRow($seeded['payment_id']))->toBeNull();

    // THE LOCK WAS RELEASED, witnessed from the OBSERVER rather than assumed:
    // a failure raised before any transactional step still passes through
    // runUnderExclusiveLock()'s own `finally`.
    expect(rebuildLockHeld())->toBeFalse();

    // AND THE RECONNECTOR WAS RESTORED: this exit passes through rebuild()'s
    // own `finally` exactly as every other one does.
    expect(fn () => DB::connection()->reconnect())->not->toThrow(RuntimeException::class);
});

it('reports a completed rebuild whose own completion record failed, never as a database error', function (): void {
    $seeded = rebuildSeed();

    rebuildWithFailingLogger(failOnCall: 2);

    [$exitCode, $result] = rebuildRun();

    expect($exitCode)->toBe(RebuildOutcome::EXIT_FAILED)
        ->and($result['outcome'])->toBe(RebuildOutcome::OUTCOME_FAILED)
        ->and($result['reason'])->toBe(RebuildReason::AuditFailed->value)
        // THE REAL, FINAL PROGRESS - proof the run actually completed even
        // though the reported outcome is a failure.
        ->and($result['batches_committed'])->toBe(1)
        ->and($result['events_replayed'])->toBe(1);

    // THE WORK REALLY LANDED: this test would be lying about "completed" if
    // the ready transition had not actually committed.
    expect(rebuildControlRow())->toMatchArray([
        'state' => 'ready',
        'owner_token' => null,
        'owner_pid' => null,
        'started_at' => null,
        'cleared_at' => null,
    ]);
    expect(rebuildReadRow($seeded['payment_id']))->not->toBeNull();

    // The lock was released here too - the SAME `finally` covers this exit.
    expect(rebuildLockHeld())->toBeFalse();
    expect(fn () => DB::connection()->reconnect())->not->toThrow(RuntimeException::class);
});

/*
|--------------------------------------------------------------------------
| Reconnection suppression (F3) — for the DURATION of the run, and no longer
|--------------------------------------------------------------------------
|
| "For the duration of this run" is a claim about the START and the END of
| suppression, not only the start. The `checkpoint 2: a pinned session
| terminated between batches...` test above already proves it is genuinely
| ACTIVE mid-run: a killed backend surfaces as an immediate failure rather than
| a silent reconnect. The tests below prove the other half - that it does not
| outlive the run - on every family of exit: refused, completed, and aborted.
|
*/

it('restores the original reconnector after a run refused on the lock', function (): void {
    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    new ProjectionGate($lock)->inspectPaymentDetails();

    rebuildWithConfig(lockTimeoutMs: 300);

    [$exitCode, $result] = rebuildRun();

    $lock->rollBack();

    expect($exitCode)->toBe(RebuildOutcome::EXIT_REFUSED)
        ->and($result['reason'])->toBe(RebuildReason::LockTimeout->value);

    // NOT DISABLED: this run never reached the point of pinning anything
    // (phase 1 itself refused), so the reconnector must already be exactly
    // what it was before rebuild() was ever called.
    expect(fn () => DB::connection()->reconnect())->not->toThrow(RuntimeException::class);
});

it('restores the original reconnector after a completed run', function (): void {
    rebuildSeed();

    [$exitCode] = rebuildRun();
    expect($exitCode)->toBe(RebuildOutcome::EXIT_DONE);

    // NOT DISABLED ANYMORE: the run has ended, so a genuine reconnect must
    // behave normally again rather than throwing DatabaseProjectionRebuild's
    // own message - "for the duration of the run" has to mean something, or
    // every later use of this shared connection in the same PHP process
    // would be silently broken by a rebuild that ran earlier.
    expect(fn () => DB::connection()->reconnect())->not->toThrow(RuntimeException::class);

    // And the restored connection is genuinely usable, not merely non-throwing.
    expect(DB::connection()->selectOne('select 1 as ok')?->ok)->toEqual(1);
});

it('restores the original reconnector after an aborted run', function (): void {
    rebuildSeed();
    rebuildSeed();

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
        ->and($result['reason'])->toBe(RebuildReason::OwnershipLost->value);

    // NOT DISABLED: an aborted run restores it exactly as a completed one
    // does - the SAME `finally` in rebuild() covers both.
    expect(fn () => DB::connection()->reconnect())->not->toThrow(RuntimeException::class);
});

/*
|--------------------------------------------------------------------------
| Recovery and takeover (D6 phase 2, D7) - Slice 4
|--------------------------------------------------------------------------
|
| EVERY FENCE AND COMMIT-UNCERTAINTY GUARANTEE FROM SLICE 3 IS UNCHANGED
| BELOW. Guarded recovery still calls transitionToReady(), which still
| re-verifies ownership before it may reopen the gate (checkpoint 3);
| checkpoints 1 and 2 do not apply to a guarded recovery because there is no
| clear and no batch to guard, not because either was skipped. Every step
| still runs through runStep(), so a lost COMMIT response is still classified
| as `commit_outcome_unknown` rather than inferred as a rollback.
*/

/**
 * Kill the pinned rebuild connection's own backend after its Nth commit,
 * confirmed released through a `pg_locks` barrier rather than a sleep - the
 * same discipline AdvisoryExclusiveWaiter uses, and the same technique
 * Slice 3's own "checkpoint 2: a pinned session terminated between
 * batches..." test already established. The caller must still
 * `Event::forget(TransactionCommitted::class)` and `DB::purge()` afterwards.
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

/** Mark the projection rebuilding directly, as a dead run that already cleared would leave it. */
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

/** With no exclusive holder left, the durable row alone must still close traffic. */
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

    // RUN 1: killed right after CLAIMING ownership (commit #1), before its own
    // clear ever runs - cleared_at stays null, exactly the state guarded
    // recovery exists for.
    killAfterCommit(1);
    [$exit1] = rebuildRun();
    Event::forget(TransactionCommitted::class);
    DB::purge();

    expect($exit1)->toBe(RebuildOutcome::EXIT_FAILED);

    $afterRun1 = rebuildControlRow();
    expect($afterRun1['state'])->toBe('rebuilding')
        ->and($afterRun1['cleared_at'])->toBeNull();
    expectAbandonedRebuildGateClosed();

    // RUN 2 (a takeover): finds cleared_at still null - nothing was ever
    // destroyed - so guarded recovery applies: straight to ready, with no
    // clear and no replay at all.
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

    // THE PROJECTION WAS NEVER TOUCHED: guarded recovery skipped the clear
    // entirely, so the row a real consumer wrote before any of this started
    // survives byte for byte. If it had cleared without replaying, this row
    // would be gone; the batches/events counts above already prove no replay
    // ran either.
    expect(rebuildReadRow($seeded['payment_id']))->toBe($projectedBefore);
    expect(DB::table('payment_projector_receipts')->get()->all())->toEqual($receiptsBefore)
        ->and(DB::table('payment_outbox')->get()->all())->toEqual($outboxBefore);
});

it('never takes the guarded-recovery shortcut when cleared_at is non-null, even if the read model looks complete', function (): void {
    $seeded = rebuildSeed();
    resolve(PaymentProjectionConsumer::class)->project($seeded['event_id']);
    $projectedBefore = rebuildReadRow($seeded['payment_id']);

    // A dead run that reached its clear, exactly as claimOwnership() is
    // required to leave it - even though, in this fixture, payment_details
    // still looks entirely correct and complete.
    markDeadRebuildWithClearedAt(now()->toIso8601String());

    rebuildWithConfig();
    [$exitCode, $result] = rebuildRun();

    expect($exitCode)->toBe(RebuildOutcome::EXIT_DONE)
        ->and($result['guarded_recovery'])->toBeFalse()
        // A REAL REPLAY RAN, not a shortcut: the read model looking complete
        // never licenses skipping the work.
        ->and($result['events_replayed'])->toBe(1)
        ->and($result['batches_committed'])->toBe(1);

    // Replay is deterministic, so the row ends up identical either way - the
    // counts above are what prove genuine work happened rather than the row
    // merely surviving untouched.
    expect(rebuildReadRow($seeded['payment_id']))->toBe($projectedBefore);

    $control = rebuildControlRow();
    expect($control['state'])->toBe('ready')
        ->and($control['cleared_at'])->toBeNull();
});

it('the non-vacuous control: resetting cleared_at on a takeover would wrongly permit guarded recovery', function (): void {
    // Model lost clear evidence after a destructive run. This is a control
    // fixture, not a claim that production claimOwnership() was mutated.
    $seeded = rebuildSeed();

    markDeadRebuildWithClearedAt(now()->toIso8601String());
    expect(rebuildReadRow($seeded['payment_id']))->toBeNull();

    // Simulate a claimOwnership() that wrongly reset cleared_at to null on
    // takeover.
    DB::table('payment_projection_controls')->where('projection', 'payment_details')
        ->update(['cleared_at' => null]);

    rebuildWithConfig();
    [$exitCode, $result] = rebuildRun();

    // WITH THE BUG SIMULATED, guarded recovery fires - proving the branch is
    // reachable and meaningful, and that claimOwnership()'s real refusal to
    // touch cleared_at (proven above) is what stands between this and
    // silently skipping a rebuild the projection actually needed.
    expect($exitCode)->toBe(RebuildOutcome::EXIT_DONE)
        ->and($result['guarded_recovery'])->toBeTrue()
        // The corrupted marker falsely licenses ready over a missing projection.
        ->and(rebuildReadRow($seeded['payment_id']))->toBeNull();
});

it('a takeover that fails before its own clear leaves the inherited cleared_at intact, and a third run is still refused the shortcut', function (): void {
    $seeded = rebuildSeed();

    // RUN 1: reaches its own clear and is killed right after (commit #2:
    // claim, then clear).
    killAfterCommit(2);
    [$exit1] = rebuildRun();
    Event::forget(TransactionCommitted::class);
    DB::purge();

    expect($exit1)->toBe(RebuildOutcome::EXIT_FAILED);

    $afterRun1 = rebuildControlRow();
    $clearedAtAfterRun1 = $afterRun1['cleared_at'];
    expect($clearedAtAfterRun1)->not->toBeNull();
    expectAbandonedRebuildGateClosed();

    // RUN 2 (a takeover): killed right after CLAIMING ownership (commit #1),
    // before it ever reaches its own clear step.
    rebuildWithConfig();
    killAfterCommit(1);
    [$exit2] = rebuildRun();
    Event::forget(TransactionCommitted::class);
    DB::purge();

    expect($exit2)->toBe(RebuildOutcome::EXIT_FAILED);

    $afterRun2 = rebuildControlRow();
    expect($afterRun2['state'])->toBe('rebuilding')
        // PROVEN NOTHING FROM RUN 2's OWN CLEAR RAN, because it never reached
        // clearAndCaptureWatermark() at all: cleared_at is byte-identical to
        // what run 1 alone left.
        ->and($afterRun2['cleared_at'])->toBe($clearedAtAfterRun1)
        // A genuinely new token: run 2 really did take over, it did not
        // resume run 1's own attempt.
        ->and($afterRun2['owner_token'])->not->toBeNull()
        ->and($afterRun2['owner_token'])->not->toBe($afterRun1['owner_token'])
        ->and($afterRun2['owner_pid'])->not->toBe($afterRun1['owner_pid'])
        ->and($afterRun2['started_at'])->not->toBe($afterRun1['started_at']);
    expectAbandonedRebuildGateClosed();

    // RUN 3: still refused the guarded-recovery shortcut - cleared_at is
    // still the ORIGINAL non-null value inherited across two takeovers now -
    // so a mandatory full clear+replay runs, and this one completes.
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

    // Claim, clear, and one committed batch; two events remain unreplayed.
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

    // RUN 2 (a takeover of run 1's abandoned row): not eligible for guarded
    // recovery either - cleared_at is already non-null - so a mandatory full
    // clear+replay runs. Its own clear step's `coalesce(cleared_at, now())`
    // must leave the INHERITED timestamp untouched. Killed right after its
    // own clear commits too (commit #2 of this run).
    rebuildWithConfig(batchSize: 1);
    killAfterCommit(2);
    [$exit2] = rebuildRun();
    Event::forget(TransactionCommitted::class);
    DB::purge();

    expect($exit2)->toBe(RebuildOutcome::EXIT_FAILED);

    $afterRun2 = rebuildControlRow();
    expect($afterRun2['state'])->toBe('rebuilding')
        ->and($afterRun2['cleared_at'])->not->toBeNull()
        // BYTE-IDENTICAL TO RUN 1's OWN VALUE: coalesce() over an
        // already-non-null column returns that value unchanged - it is not a
        // new timestamp, even though run 2's clear statement genuinely ran.
        ->and($afterRun2['cleared_at'])->toBe($clearedAtAfterRun1)
        ->and($afterRun2['owner_token'])->not->toBe($afterRun1['owner_token'])
        // Run 2 discarded the committed partial batch rather than resuming it.
        ->and(rebuildReadRow($seeded['payment_id']))->toBeNull();
    expectAbandonedRebuildGateClosed();

    // RUN 3 (a second takeover): still not eligible - cleared_at is still the
    // ORIGINAL value inherited across two takeovers now - so a third
    // mandatory full clear+replay runs. This one completes.
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
            // The first new batch starts at the first canonical event again.
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
        // Claim, clear, three batches, empty terminal batch, then ready.
        ->and($markers)->toBe([...array_fill(0, 6, $clearedAtAfterRun1), null]);

    // ONLY NOW - after one replay actually completed - is cleared_at nulled,
    // in the same transaction that sets `ready`.
    $afterRun3 = rebuildControlRow();
    expect($afterRun3['state'])->toBe('ready')
        ->and($afterRun3['cleared_at'])->toBeNull()
        ->and($afterRun3['owner_token'])->toBeNull();

    // THE READ MODEL IS CORRECT DESPITE TWO ABORTED ATTEMPTS: replay always
    // derives from canonical stored_events, never from whatever a killed run
    // left behind.
    foreach ([$seeded, $second, $third] as $event) {
        expect(rebuildReadRow($event['payment_id']))->not->toBeNull();
    }
});

it('refuses a second rebuild against a live owner, never reaching phase 2, and the live owners token survives', function (): void {
    rebuildSeed();

    // A LIVE rebuild-shaped owner: a genuine session-level EXCLUSIVE lock,
    // held by a second connection - unlike the "waits for a traffic-shaped
    // shared holder" test above, which holds only the shared half traffic
    // takes.
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

    // THE LIVE OWNER'S TOKEN SURVIVES UNTOUCHED: this run never reached phase
    // 2 (claimOwnership()), so it could not have overwritten anything - per
    // the D6 trace, "R2 never reaches phase 2, so T1 is never overwritten".
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

        // The actual rebuild has committed its clear and still holds its lock.
        // These maintenance adapters run on a distinct backend, as separate CLI
        // processes would. Command formatting is covered by their owning suites.
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
            // A deterministic failure after a real server commit. This proves
            // classification and recovery for the committed side of uncertainty,
            // not a network-level lost acknowledgement or an OS-process kill.
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

    // A separately authorized attempt decides from durable state, not from the
    // failed attempt's JSON or its unconfirmed progress counters.
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
