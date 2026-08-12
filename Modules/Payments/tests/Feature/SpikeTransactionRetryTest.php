<?php

declare(strict_types=1);

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Payments\Tests\Spike\SpikeCommandContext;
use Modules\Payments\Tests\Spike\SpikeDatabase;
use Modules\Payments\Tests\Spike\SpikeFailureSwitch;
use Modules\Payments\Tests\Spike\SpikeInitiationHandler;
use Modules\Payments\Tests\Spike\SpikeOutboxWriter;
use Modules\Payments\Tests\Spike\SpikeProbeLog;
use Modules\Payments\Tests\Spike\SpikeTransactionRetry;
use Spatie\EventSourcing\Facades\Projectionist;

/*
|--------------------------------------------------------------------------
| T3 Q4 and Q5 — allowlisted retry, fresh objects, no retry otherwise
|--------------------------------------------------------------------------
|
| Every failure here is a REAL PostgreSQL error. A synthetic QueryException
| would prove the runner's branching and nothing about the database, which is
| the difference between this spike and a mocked happy-path test.
|
|   55P03  a real FOR UPDATE NOWAIT conflict against a lock held on a second
|          connection, released only through onRetry
|   23505  a real unique-index violation
|
| The lock guards below exist because an invisible or already-released lock
| would make the contention silently succeed, and the retry test would pass
| having never retried.
|
*/

const SPIKE_LOCK_PROBE_ID = 1;

beforeEach(function (): void {
    SpikeDatabase::configure();
    SpikeDatabase::useSpikeModels();
    SpikeDatabase::ensureSchema();

    app()->singleton(SpikeProbeLog::class);
    app()->singleton(SpikeFailureSwitch::class);

    Projectionist::addProjector(resolve(SpikeOutboxWriter::class));
});

afterEach(function (): void {
    SpikeDatabase::teardown();
});

/**
 * Commit the contended row, then take a real FOR UPDATE lock on it from the
 * lock connection, asserting at each step that the contention is genuine.
 *
 * C1: the row is committed BEFORE anything locks it. A row inserted inside an
 * uncommitted transaction is invisible to the other connection, so FOR UPDATE
 * NOWAIT would match zero rows, raise nothing, and the retry test would pass
 * having proven nothing at all.
 */
function spikeHoldRealLock(): void
{
    $spike = DB::connection(SpikeDatabase::CONNECTION);
    $lock = DB::connection(SpikeDatabase::LOCK_CONNECTION);

    $spike->table('spike_lock_probe')->insert(['id' => SPIKE_LOCK_PROBE_ID]);

    // GUARD: committed, and therefore visible to the connection about to lock it.
    expect($lock->table('spike_lock_probe')->where('id', SPIKE_LOCK_PROBE_ID)->count())->toBe(1);

    $lock->beginTransaction();
    $lock->select('select id from spike_lock_probe where id = ? for update', [SPIKE_LOCK_PROBE_ID]);

    // GUARD: the lock is really held, as PostgreSQL reports it, not merely
    // as our code believes it. A prematurely released lock is the other way
    // this test could go false-green.
    $lockPid = (int) $lock->selectOne('select pg_backend_pid() as pid')->pid;
    $granted = (int) $spike->selectOne(
        "select count(*) as c from pg_locks
         where pid = ? and relation = 'spike_lock_probe'::regclass
           and mode = 'RowShareLock' and granted",
        [$lockPid],
    )->c;

    expect($lock->transactionLevel())->toBe(1)
        ->and($granted)->toBe(1);
}

/** The identical contention statement, run on every attempt. */
function spikeContention(): Closure
{
    return function (): void {
        DB::connection(SpikeDatabase::CONNECTION)
            ->select('select id from spike_lock_probe where id = ? for update nowait', [SPIKE_LOCK_PROBE_ID]);
    };
}

it('retries a real 55P03 and commits one logical event and outbox row', function (): void {
    $context = SpikeCommandContext::make();
    $spike = DB::connection(SpikeDatabase::CONNECTION);
    $lock = DB::connection(SpikeDatabase::LOCK_CONNECTION);

    spikeHoldRealLock();

    $handler = new SpikeInitiationHandler(resolve(SpikeProbeLog::class), spikeContention());

    $released = 0;
    $retry = new SpikeTransactionRetry(function () use ($lock, &$released): void {
        $released++;
        // The ONLY place the lock is released. Attempt 2 then runs the
        // byte-identical closure and succeeds because PostgreSQL genuinely let
        // go, not because the closure skipped the contested statement.
        $lock->rollBack();
    });

    $retry->run(fn () => $handler($context));

    // Attempt 1 really did hit 55P03, and the runner really did retry once.
    expect($retry->attempts())->toBe(2)
        ->and($retry->capturedSqlStates())->toBe(['55P03'])
        ->and($released)->toBe(1)
        ->and($lock->transactionLevel())->toBe(0);

    // Exactly one logical result committed.
    expect(DB::table('spike_payment_receipts')->count())->toBe(1)
        ->and(DB::table('stored_events')->count())->toBe(1)
        ->and(DB::table('spike_payment_outbox')->count())->toBe(1)
        ->and(DB::table('stored_events')->value('aggregate_uuid'))->toBe($context->paymentId)
        ->and(DB::table('spike_payment_outbox')->value('event_id'))->toBe($context->eventId);

    // All three written by one transaction, exactly as Q1 proved for the
    // single-attempt case.
    expect(array_unique([
        SpikeDatabase::xmin('spike_payment_receipts'),
        SpikeDatabase::xmin('stored_events'),
        SpikeDatabase::xmin('spike_payment_outbox'),
    ]))->toHaveCount(1);

    // Objects were built on BOTH attempts, before the contention each time.
    $log = resolve(SpikeProbeLog::class);
    expect($log->markers())->toBe([
        'receipt', 'objects-built',
        'receipt', 'objects-built', 'outbox-handler', 'after-persist',
    ]);

    $builds = $log->builds();
    expect($builds)->toHaveCount(2)
        // FRESH mutable objects per attempt.
        ->and($builds[0]['aggregate'])->not->toBe($builds[1]['aggregate'])
        ->and($builds[0]['event'])->not->toBe($builds[1]['event'])
        // ...from byte-identical stable context.
        ->and($builds[0]['payment_id'])->toBe($builds[1]['payment_id'])
        ->and($builds[0]['event_id'])->toBe($builds[1]['event_id'])
        ->and($builds[0]['occurred_at'])->toBe($builds[1]['occurred_at'])
        ->and($builds[0]['key_hash'])->toBe($builds[1]['key_hash']);
});

it('attempts an allowlisted failure at most three times', function (): void {
    $context = SpikeCommandContext::make();
    $spike = DB::connection(SpikeDatabase::CONNECTION);

    spikeHoldRealLock();

    $handler = new SpikeInitiationHandler(resolve(SpikeProbeLog::class), spikeContention());

    // onRetry never releases: the contention persists for every attempt.
    $retry = new SpikeTransactionRetry(fn () => null);

    expect(fn () => $retry->run(fn () => $handler($context)))->toThrow(QueryException::class);

    expect($retry->attempts())->toBe(SpikeTransactionRetry::MAX_ATTEMPTS)
        ->and($retry->attempts())->toBe(3)
        ->and($retry->capturedSqlStates())->toBe(['55P03', '55P03', '55P03']);

    // No partial state survives exhaustion.
    expect(DB::table('spike_payment_receipts')->count())->toBe(0)
        ->and(DB::table('stored_events')->count())->toBe(0)
        ->and(DB::table('spike_payment_outbox')->count())->toBe(0)
        ->and($spike->transactionLevel())->toBe(0);
});

it('does not retry a real unique violation', function (): void {
    $context = SpikeCommandContext::make();
    $spike = DB::connection(SpikeDatabase::CONNECTION);

    // Committed row whose primary key the contention statement will collide with.
    $spike->table('spike_lock_probe')->insert(['id' => SPIKE_LOCK_PROBE_ID]);

    $duplicate = function () use ($spike): void {
        $spike->table('spike_lock_probe')->insert(['id' => SPIKE_LOCK_PROBE_ID]);
    };

    $handler = new SpikeInitiationHandler(resolve(SpikeProbeLog::class), $duplicate);

    $retried = 0;
    $retry = new SpikeTransactionRetry(function () use (&$retried): void {
        $retried++;
    });

    expect(fn () => $retry->run(fn () => $handler($context)))
        ->toThrow(UniqueConstraintViolationException::class);

    expect($retry->attempts())->toBe(1)
        ->and($retry->capturedSqlStates())->toBe(['23505'])
        ->and($retried)->toBe(0);

    // No partial state: the receipt from the single attempt rolled back, and the
    // pre-existing probe row is untouched.
    expect(DB::table('spike_payment_receipts')->count())->toBe(0)
        ->and(DB::table('stored_events')->count())->toBe(0)
        ->and(DB::table('spike_payment_outbox')->count())->toBe(0)
        ->and(DB::table('spike_lock_probe')->count())->toBe(1)
        ->and($spike->transactionLevel())->toBe(0);
});

it('reads the SQLSTATE out of a DeadlockException by unwrapping it', function (): void {
    $spike = DB::connection(SpikeDatabase::CONNECTION);
    $spike->table('spike_lock_probe')->insert(['id' => SPIKE_LOCK_PROBE_ID]);

    // A REAL QueryException, not a hand-built one.
    $real = null;
    try {
        $spike->table('spike_lock_probe')->insert(['id' => SPIKE_LOCK_PROBE_ID]);
    } catch (QueryException $e) {
        $real = $e;
    }

    expect($real)->not->toBeNull()
        ->and(SpikeTransactionRetry::sqlStateOf($real))->toBe('23505');

    // Laravel rethrows a concurrency error inside a NESTED transaction as a
    // DeadlockException, which extends PDOException but carries no errorInfo of
    // its own. Without unwrapping getPrevious() the SQLSTATE would be lost and
    // the failure would be misclassified as non-retryable.
    $wrapped = new DeadlockException($real->getMessage(), 0, $real);

    expect($wrapped->errorInfo)->toBeNull()
        ->and(SpikeTransactionRetry::sqlStateOf($wrapped))->toBe('23505');
});
