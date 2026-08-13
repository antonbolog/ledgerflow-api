<?php

declare(strict_types=1);

use Illuminate\Contracts\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Payments\Tests\Spike\SpikeAdvisoryLock;
use Modules\Payments\Tests\Spike\SpikeDatabase;
use Modules\Payments\Tests\Spike\SpikeInjectedFailure;
use Modules\Payments\Tests\Spike\SpikeTransactionRetry;

/*
|--------------------------------------------------------------------------
| T4 Q6-Q8 — the PostgreSQL advisory-lock gate primitive
|--------------------------------------------------------------------------
|
| Three questions, one harness:
|
|   Q6  Do a transaction-scoped SHARED advisory lock and the work it protects
|       run on the same connection and the same backend PID?
|   Q7  Is contention from a SEPARATE backend real, and observable without
|       sleeps, both by try-lock and by a bounded wait?
|   Q8  Can a lost or reconnected session silently preserve assumed lock
|       ownership?
|
| Evidence rules, carried from T3:
|
|   - pg_locks is read from the OBSERVER (default) connection, never from the
|     holder. An observation of "who holds this lock" made by the holder is not
|     an observation.
|   - Uncommitted rows are visible only to the session that wrote them, so
|     "spike sees it, observer does not" is PostgreSQL's own statement that the
|     transaction is still open.
|   - NO sleeps. Contention is observed by try-lock (immediate false) and by a
|     bounded lock_timeout (deterministic 55P03).
|
| DECLARATION ORDER IS NOT LOAD-BEARING. Every test creates the state it
| asserts on, including the teardown-recovery test, so the file passes under
| --order-by=random and each test passes when run alone.
|
*/

beforeEach(function (): void {
    SpikeDatabase::configure();
    SpikeDatabase::ensureSchema();

    // The six universal guards. Each fails loudly rather than degrading.
    //
    // Guard 1 is the one that keeps the shared-lock coexistence assertion
    // honest: PostgreSQL advisory locks never conflict with the session that
    // already holds them, so two connections collapsed onto one session would
    // make that test pass vacuously.
    expect(SpikeAdvisoryLock::pid(SpikeDatabase::CONNECTION))
        ->not->toBe(SpikeAdvisoryLock::pid(SpikeDatabase::LOCK_CONNECTION));

    expect(SpikeDatabase::databaseName(SpikeDatabase::CONNECTION))->toBe('ledgerflow_test');
    expect(SpikeDatabase::databaseName(SpikeDatabase::LOCK_CONNECTION))->toBe('ledgerflow_test');

    expect(DB::connection(SpikeDatabase::CONNECTION)->transactionLevel())->toBe(0);
    expect(DB::connection(SpikeDatabase::LOCK_CONNECTION)->transactionLevel())->toBe(0);
    expect(DB::connection()->transactionLevel())->toBe(1);

    expect(SpikeAdvisoryLock::heldLocks())->toBe([]);

    // Guard 5. The ONLY guard that catches a leaked committed row: a stale
    // transaction counter is erased by DB::purge() rebuilding the Connection
    // object, but committed rows survive that.
    //
    // toHaveKey() first, because `expect([])->each` passes vacuously. Without
    // it, a schema that failed to materialise would turn the leak detector into
    // a no-op that reports success.
    $rowCounts = SpikeDatabase::observedRowCounts();

    expect($rowCounts)->toHaveKey('spike_outbox_delivery');
    expect($rowCounts)->each->toBe(0);
});

afterEach(function (): void {
    SpikeDatabase::teardown();
});

/*
| Q6 — connection affinity
*/

it('holds the shared gate lock and its protected work on one backend', function (): void {
    $eventId = Str::uuid()->toString();
    $queryPid = null;
    $locksWhileHeld = [];
    $observerRowsWhileOpen = null;
    $affinity = null;

    DB::connection(SpikeDatabase::CONNECTION)->transaction(function () use (
        $eventId,
        &$queryPid,
        &$locksWhileHeld,
        &$observerRowsWhileOpen,
        &$affinity,
    ): void {
        SpikeAdvisoryLock::acquireSharedXact(SpikeDatabase::CONNECTION);

        DB::connection(SpikeDatabase::CONNECTION)->table('spike_outbox_delivery')->insert([
            'event_id' => $eventId,
        ]);

        $queryPid = SpikeAdvisoryLock::pid(SpikeDatabase::CONNECTION);
        $locksWhileHeld = SpikeAdvisoryLock::heldLocks();

        // The SAME predicate the wrong-connection mutation below expects to
        // return false. Asserting it here is what makes that mutation
        // load-bearing: without this line a hasAffinity() that returned a
        // constant false would leave both tests green.
        $affinity = SpikeAdvisoryLock::hasAffinity($queryPid);

        // Uncommitted work is invisible to everyone else, so the lock is being
        // held ACROSS the write rather than taken after it.
        $observerRowsWhileOpen = DB::connection()->table('spike_outbox_delivery')->count();
    }, 1);

    expect($affinity)->toBeTrue();

    // Primary evidence: PostgreSQL's own record of who holds the lock, read by
    // a third party, matches the backend that did the protected work.
    expect($locksWhileHeld)->toHaveCount(1);
    expect($locksWhileHeld[0]['pid'])->toBe($queryPid);
    expect($locksWhileHeld[0]['mode'])->toBe('ShareLock');
    expect($locksWhileHeld[0]['granted'])->toBeTrue();
    expect($locksWhileHeld[0]['classid'])->toBe(SpikeAdvisoryLock::GATE_CLASS);
    expect($locksWhileHeld[0]['objid'])->toBe(SpikeAdvisoryLock::GATE_OBJECT);
    expect($locksWhileHeld[0]['objsubid'])->toBe(2);

    expect($observerRowsWhileOpen)->toBe(0);

    // After commit: the row is visible and the transaction-scoped lock is gone
    // with no explicit release.
    expect(DB::connection()->table('spike_outbox_delivery')->count())->toBe(1);
    expect(SpikeAdvisoryLock::heldLocks())->toBe([]);
});

it('releases the transaction-scoped lock on rollback as well as on commit', function (): void {
    $thrown = null;

    try {
        DB::connection(SpikeDatabase::CONNECTION)->transaction(function (): void {
            SpikeAdvisoryLock::acquireSharedXact(SpikeDatabase::CONNECTION);

            DB::connection(SpikeDatabase::CONNECTION)->table('spike_outbox_delivery')->insert([
                'event_id' => Str::uuid()->toString(),
            ]);

            throw new SpikeInjectedFailure('after the gate lock and the protected write');
        }, 1);
    } catch (SpikeInjectedFailure $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(SpikeInjectedFailure::class);
    expect(DB::connection()->table('spike_outbox_delivery')->count())->toBe(0);
    expect(SpikeAdvisoryLock::heldLocks())->toBe([]);
    expect(DB::connection(SpikeDatabase::CONNECTION)->transactionLevel())->toBe(0);
});

it('reports an affinity mismatch when the lock is taken on another connection', function (): void {
    // The mutation. Without it, the equality asserted above could be comparing
    // a value with itself and nobody would know.
    DB::connection(SpikeDatabase::LOCK_CONNECTION)->beginTransaction();
    SpikeAdvisoryLock::acquireSharedXact(SpikeDatabase::LOCK_CONNECTION);

    $holderPid = SpikeAdvisoryLock::pid(SpikeDatabase::LOCK_CONNECTION);
    $affinity = null;
    $queryPid = null;

    DB::connection(SpikeDatabase::CONNECTION)->transaction(function () use (&$affinity, &$queryPid): void {
        DB::connection(SpikeDatabase::CONNECTION)->table('spike_outbox_delivery')->insert([
            'event_id' => Str::uuid()->toString(),
        ]);

        $queryPid = SpikeAdvisoryLock::pid(SpikeDatabase::CONNECTION);
        $affinity = SpikeAdvisoryLock::hasAffinity($queryPid);
    }, 1);

    expect($affinity)->toBeFalse();
    expect($holderPid)->not->toBe($queryPid);

    $locks = SpikeAdvisoryLock::heldLocks();
    expect($locks)->toHaveCount(1);
    expect($locks[0]['pid'])->toBe($holderPid);

    DB::connection(SpikeDatabase::LOCK_CONNECTION)->rollBack();
});

/*
| Q7 — real contention from a separate backend, without sleeps
*/

it('refuses an exclusive contender while the gate is held and admits it after release', function (): void {
    DB::connection(SpikeDatabase::CONNECTION)->beginTransaction();
    SpikeAdvisoryLock::acquireSharedXact(SpikeDatabase::CONNECTION);

    // Immediate answer, no waiting. The contender runs outside a transaction,
    // so a successful try-lock is released at statement end and cannot leak.
    expect(SpikeAdvisoryLock::tryExclusiveXact(SpikeDatabase::LOCK_CONNECTION))->toBeFalse();

    DB::connection(SpikeDatabase::CONNECTION)->rollBack();

    // The mutation: the false above is only meaningful if this true exists.
    expect(SpikeAdvisoryLock::tryExclusiveXact(SpikeDatabase::LOCK_CONNECTION))->toBeTrue();
});

it('admits a second shared holder while the gate is held', function (): void {
    DB::connection(SpikeDatabase::CONNECTION)->beginTransaction();
    SpikeAdvisoryLock::acquireSharedXact(SpikeDatabase::CONNECTION);

    DB::connection(SpikeDatabase::LOCK_CONNECTION)->beginTransaction();

    expect(SpikeAdvisoryLock::trySharedXact(SpikeDatabase::LOCK_CONNECTION))->toBeTrue();

    // The gate is SHARED: POST, transactional GET, relay and consumer must all
    // pass simultaneously.
    $locks = SpikeAdvisoryLock::heldLocks();

    expect($locks)->toHaveCount(2);
    expect($locks[0]['mode'])->toBe('ShareLock');
    expect($locks[1]['mode'])->toBe('ShareLock');
    expect($locks[0]['granted'])->toBeTrue();
    expect($locks[1]['granted'])->toBeTrue();
    expect($locks[0]['pid'])->not->toBe($locks[1]['pid']);

    DB::connection(SpikeDatabase::LOCK_CONNECTION)->rollBack();
    DB::connection(SpikeDatabase::CONNECTION)->rollBack();
});

it('raises a real 55P03 when a bounded exclusive wait times out', function (): void {
    DB::connection(SpikeDatabase::CONNECTION)->beginTransaction();
    SpikeAdvisoryLock::acquireSharedXact(SpikeDatabase::CONNECTION);

    DB::connection(SpikeDatabase::LOCK_CONNECTION)->beginTransaction();

    $thrown = null;

    try {
        // lock_timeout 250ms proves the point; statement_timeout 2s is the hang
        // defense. A 57014 here would mean lock_timeout does not apply to
        // advisory waits, which is a loud finding rather than a stalled run.
        SpikeAdvisoryLock::waitExclusiveXactBounded(SpikeDatabase::LOCK_CONNECTION);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(QueryException::class);

    // T3's extractor, reused deliberately: if the two spikes ever observe
    // different exception shapes, this assertion is what says so.
    expect(SpikeTransactionRetry::sqlStateOf($thrown))->toBe('55P03');
    expect(SpikeTransactionRetry::RETRYABLE)->toContain('55P03');

    DB::connection(SpikeDatabase::LOCK_CONNECTION)->rollBack();
    DB::connection(SpikeDatabase::CONNECTION)->rollBack();
});

it('refuses a session-level acquisition when another backend already holds the gate', function (): void {
    // The harness helper the Q8 tests below depend on must never wait. A
    // blocking acquisition here would hang the whole suite with no output if
    // anything already held the key, which is worse than failing.
    expect(SpikeAdvisoryLock::tryExclusiveSession(SpikeDatabase::LOCK_CONNECTION))->toBeTrue();

    // No sleep, no timeout: the try-lock answers on the spot, so contention
    // surfaces as a refusal.
    expect(fn () => SpikeAdvisoryLock::acquireSessionExclusive(SpikeDatabase::CONNECTION))
        ->toThrow(RuntimeException::class);

    // The contender never became a holder: still exactly one lock, still the
    // other backend's.
    $locks = SpikeAdvisoryLock::heldLocks();

    expect($locks)->toHaveCount(1);
    expect($locks[0]['pid'])->toBe(SpikeAdvisoryLock::pid(SpikeDatabase::LOCK_CONNECTION));
    expect($locks[0]['mode'])->toBe('ExclusiveLock');

    // Deliberately NOT released here. SpikeDatabase::teardown() runs
    // pg_advisory_unlock_all() on both spike connections, and the next test's
    // beforeEach guard asserts the gate key is free — so leaving it held makes
    // that teardown path load-bearing evidence rather than an untested claim.
});

/*
| Q8 — connection loss. Three falsifiable cases, never "one of several
| outcomes". Each asserts a definite branch; a different result on this stack
| is a RED test and a finding.
*/

it('reconnects silently onto a new backend when the lost connection has no transaction', function (): void {
    SpikeAdvisoryLock::acquireSessionExclusive(SpikeDatabase::CONNECTION);

    $originalPid = SpikeAdvisoryLock::pid(SpikeDatabase::CONNECTION);

    $locks = SpikeAdvisoryLock::heldLocks();
    expect($locks)->toHaveCount(1);
    expect($locks[0]['pid'])->toBe($originalPid);
    expect($locks[0]['mode'])->toBe('ExclusiveLock');
    expect($locks[0]['granted'])->toBeTrue();

    SpikeAdvisoryLock::terminate($originalPid, SpikeDatabase::CONNECTION);

    // V19, transactions === 0 branch: Laravel reconnects and re-runs, so the
    // caller sees a successful query on a backend it never asked for.
    $newPid = SpikeAdvisoryLock::pid(SpikeDatabase::CONNECTION);

    expect($newPid)->not->toBe($originalPid);
    expect(SpikeAdvisoryLock::heldLocks())->toBe([]);

    // Ownership is provably gone: a third party can now take it.
    expect(SpikeAdvisoryLock::tryExclusiveSession(SpikeDatabase::LOCK_CONNECTION))->toBeTrue();

    SpikeAdvisoryLock::releaseAll(SpikeDatabase::LOCK_CONNECTION);
});

it('surfaces the lost connection and leaves a stale transaction level inside a transaction', function (): void {
    SpikeAdvisoryLock::acquireSessionExclusive(SpikeDatabase::CONNECTION);

    DB::connection(SpikeDatabase::CONNECTION)->beginTransaction();

    $originalPid = SpikeAdvisoryLock::pid(SpikeDatabase::CONNECTION);

    SpikeAdvisoryLock::terminate($originalPid, SpikeDatabase::CONNECTION);

    // V19, transactions >= 1 branch: handleQueryException rethrows instead of
    // reconnecting, so the loss is visible here.
    $thrown = null;

    try {
        SpikeAdvisoryLock::pid(SpikeDatabase::CONNECTION);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(QueryException::class);

    // The container's real detector, never a re-implementation of its
    // substring list.
    //
    // MEASURED SHAPE (PostgreSQL 18.4, PDO pgsql, backend terminated while
    // idle inside a transaction): QueryException wrapping PDOException, and the
    // SQLSTATE is HY000 — NOT 57P01. The message carries both
    // "FATAL: terminating connection due to administrator command" and
    // "server closed the connection unexpectedly", and it is the second of
    // those that LostConnectionDetector matches. Classification here is message
    // based, so anything keying off SQLSTATE would misread this as a generic
    // error rather than a lost connection.
    $previous = $thrown->getPrevious();
    expect($previous)->not->toBeNull();
    expect(resolve(LostConnectionDetector::class)->causedByLostConnection($previous))->toBeTrue();

    // V20/V21: reconnect()/disconnect() swap the PDO and never touch the
    // counter, so transactionLevel() describes a transaction on a backend that
    // no longer exists. T11 must not read this as proof its session survived.
    expect(DB::connection(SpikeDatabase::CONNECTION)->transactionLevel())->toBe(1);

    // ...and the lock is gone regardless of what the counter says.
    expect(SpikeAdvisoryLock::tryExclusiveSession(SpikeDatabase::LOCK_CONNECTION))->toBeTrue();

    SpikeAdvisoryLock::releaseAll(SpikeDatabase::LOCK_CONNECTION);
});

it('recovers from a terminated backend during teardown without leaking rows', function (): void {
    // Self-contained: this test creates its own damage instead of inheriting
    // it from the test declared above.
    $eventId = Str::uuid()->toString();

    DB::connection(SpikeDatabase::CONNECTION)->table('spike_outbox_delivery')->insert([
        'event_id' => $eventId,
    ]);

    expect(DB::connection()->table('spike_outbox_delivery')->count())->toBe(1);

    DB::connection(SpikeDatabase::CONNECTION)->beginTransaction();
    SpikeAdvisoryLock::acquireSessionExclusive(SpikeDatabase::CONNECTION);

    $originalPid = SpikeAdvisoryLock::pid(SpikeDatabase::CONNECTION);

    SpikeAdvisoryLock::terminate($originalPid, SpikeDatabase::CONNECTION);

    $teardownFailure = null;

    try {
        SpikeDatabase::teardown();
    } catch (Throwable $e) {
        $teardownFailure = $e;
    }

    expect($teardownFailure)->toBeNull();

    $recoveredPid = SpikeAdvisoryLock::pid(SpikeDatabase::CONNECTION);

    expect($recoveredPid)->not->toBe($originalPid);
    expect(DB::connection(SpikeDatabase::CONNECTION)->transactionLevel())->toBe(0);
    expect(SpikeAdvisoryLock::heldLocks())->toBe([]);

    // The E3 leak path, made executable. If rollBack()'s failure ever stops
    // matching causedByLostConnection, the stale counter keeps cleanup() on the
    // rethrow branch, teardown swallows it, and this row survives.
    expect(DB::connection()->table('spike_outbox_delivery')->count())->toBe(0);
});
