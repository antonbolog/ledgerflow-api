<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Spike;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The Payments projection gate primitive, and every way of touching or
 * observing it, for the T4 compatibility spike.
 *
 * THE KEY IS A SPIKE CONSTANT, NOT A DECISION. How payment_projection_controls
 * maps a projection name to an advisory key belongs to T11. Nothing here should
 * be read as proposing a production derivation.
 *
 * The two-integer form is deliberate: pg_locks then reports the halves in
 * classid/objid with objsubid = 2, so the witness assertions are exact instead
 * of approximate.
 *
 * Three invariants shape this class:
 *
 *   1. EVERY helper takes the connection name explicitly. There is no ambient
 *      default, because the whole question Q6 asks is which connection ran the
 *      statement — a helper that could silently fall back to the default
 *      connection would be the exact bug it exists to detect.
 *
 *   2. heldLocks() ALWAYS reads from the default observer connection, hard
 *      coded. An observation of "who holds this lock" made by the holder is not
 *      an observation. It also filters by the current database OID: advisory
 *      locks are per-database but pg_locks reports the whole cluster, and the
 *      development database ledgerflow shares this PostgreSQL instance, so an
 *      unfiltered read would count a developer's psql session holding the same
 *      key and silently change a contention result.
 *
 *   3. terminate() REFUSES a pid that is not the named connection's. A misfire
 *      at the observer would kill RefreshDatabase's wrapper transaction and
 *      make the rest of the run meaningless.
 *
 * Booleans and OIDs are cast to int in SQL rather than read raw: PDO pgsql can
 * hand back 't'/'f' strings for booleans, and 'f' is truthy in PHP.
 */
final class SpikeAdvisoryLock
{
    public const int GATE_CLASS = 4242;

    public const int GATE_OBJECT = 1;

    /**
     * Bounded wait settings for the blocking acquisition.
     *
     * lock_timeout is the measurement: it should raise 55P03, the SQLSTATE the
     * Payments retry allowlist names for "lock unavailable, including
     * configured lock timeout".
     *
     * statement_timeout is the HANG DEFENSE. If lock_timeout did not apply to
     * advisory-lock waits, the blocking call would sit there until the holder
     * released, which in these tests is never, and `make test` would stall with
     * no output. A 57014 at two seconds is a loud failure instead.
     */
    private const string LOCK_TIMEOUT = '250ms';

    private const string STATEMENT_TIMEOUT = '2s';

    public static function pid(string $connection): int
    {
        return (int) DB::connection($connection)
            ->selectOne('select pg_backend_pid() as pid')->pid;
    }

    /**
     * Every advisory lock on the gate key, in this database, as PostgreSQL
     * itself records it. Read from the observer, never from a holder.
     *
     * @return list<array{pid: int, mode: string, granted: bool, classid: int, objid: int, objsubid: int}>
     */
    public static function heldLocks(): array
    {
        $rows = DB::connection()->select(sprintf(
            'select pid::int as pid, '
            .'mode, '
            .'granted::int as granted, '
            .'classid::int as classid, '
            .'objid::int as objid, '
            .'objsubid::int as objsubid '
            .'from pg_locks '
            .'where locktype = %s '
            .'and database = (select oid from pg_database where datname = current_database()) '
            .'and classid = %d '
            .'and objid = %d '
            .'order by pid',
            "'advisory'",
            self::GATE_CLASS,
            self::GATE_OBJECT,
        ));

        return array_map(static fn (object $row): array => [
            'pid' => (int) $row->pid,
            'mode' => (string) $row->mode,
            'granted' => ((int) $row->granted) === 1,
            'classid' => (int) $row->classid,
            'objid' => (int) $row->objid,
            'objsubid' => (int) $row->objsubid,
        ], $rows);
    }

    /**
     * Does exactly one backend hold the gate, and is it the one that ran the
     * protected work?
     *
     * This is the affinity predicate itself, used by BOTH the Q6 test (must be
     * true) and its wrong-connection mutation (must be false). Sharing one
     * helper is what stops the Q6 equality from being a comparison of a value
     * with itself.
     */
    public static function hasAffinity(int $queryPid): bool
    {
        $locks = self::heldLocks();

        return count($locks) === 1 && $locks[0]['pid'] === $queryPid;
    }

    public static function acquireSharedXact(string $connection): void
    {
        DB::connection($connection)->select(sprintf(
            'select pg_advisory_xact_lock_shared(%d, %d)',
            self::GATE_CLASS,
            self::GATE_OBJECT,
        ));
    }

    public static function tryExclusiveXact(string $connection): bool
    {
        return self::tryLock($connection, 'pg_try_advisory_xact_lock');
    }

    public static function trySharedXact(string $connection): bool
    {
        return self::tryLock($connection, 'pg_try_advisory_xact_lock_shared');
    }

    /**
     * Take the session-level gate lock, or fail loudly.
     *
     * NON-BLOCKING ON PURPOSE. The blocking pg_advisory_lock() this replaced was
     * the last unbounded wait in the spike: if anything already held the key —
     * a lock leaked by an earlier test, or a stray psql session in
     * ledgerflow_test — it would block forever and hang `make test` with no
     * output. A hung compatibility spike is worse than a failed one, which is
     * the same reasoning that put a bounded timeout on the blocking
     * transaction-scoped wait.
     *
     * "Acquire and assert we got it" is also strictly stronger evidence than
     * "wait until we get it": every caller now proves the key was free.
     */
    public static function acquireSessionExclusive(string $connection): void
    {
        if (! self::tryExclusiveSession($connection)) {
            throw new RuntimeException(sprintf(
                'Connection %s could not take the session-level gate lock (%d, %d): another backend '
                .'already holds it. This is a refusal, not a timeout — nothing waited.',
                $connection,
                self::GATE_CLASS,
                self::GATE_OBJECT,
            ));
        }
    }

    public static function tryExclusiveSession(string $connection): bool
    {
        return self::tryLock($connection, 'pg_try_advisory_lock');
    }

    /**
     * The blocking acquisition, bounded twice over. The caller must already be
     * inside a transaction, because SET LOCAL has no effect outside one.
     */
    public static function waitExclusiveXactBounded(string $connection): void
    {
        $db = DB::connection($connection);

        $db->statement(sprintf("set local lock_timeout = '%s'", self::LOCK_TIMEOUT));
        $db->statement(sprintf("set local statement_timeout = '%s'", self::STATEMENT_TIMEOUT));

        $db->select(sprintf(
            'select pg_advisory_xact_lock(%d, %d)',
            self::GATE_CLASS,
            self::GATE_OBJECT,
        ));
    }

    /**
     * Release every session-level advisory lock this connection holds.
     *
     * Transaction-scoped locks die with their transaction; this exists for the
     * session-level ones, and for the contender that takes the gate to prove
     * ownership was released.
     */
    public static function releaseAll(string $connection): void
    {
        DB::connection($connection)->select('select pg_advisory_unlock_all()');
    }

    /**
     * Kill a backend, from the lock connection, with three guards first.
     *
     * The guards are not defensive programming for its own sake. Terminating
     * the observer would take down RefreshDatabase's wrapper transaction and
     * every test after this one; terminating the lock connection would remove
     * the session doing the terminating.
     */
    public static function terminate(int $pid, string $expectedConnection): void
    {
        $expected = self::pid($expectedConnection);

        if ($pid !== $expected) {
            throw new RuntimeException(sprintf(
                'Refusing to terminate pid %d: connection %s reports pid %d.',
                $pid,
                $expectedConnection,
                $expected,
            ));
        }

        $observer = self::pid(DB::getDefaultConnection());

        if ($pid === $observer) {
            throw new RuntimeException(sprintf(
                'Refusing to terminate pid %d: that is the observer connection, and killing it '
                .'would take down RefreshDatabase for the rest of the suite.',
                $pid,
            ));
        }

        $terminator = self::pid(SpikeDatabase::LOCK_CONNECTION);

        if ($pid === $terminator) {
            throw new RuntimeException(sprintf(
                'Refusing to terminate pid %d: that is the connection issuing the termination.',
                $pid,
            ));
        }

        DB::connection(SpikeDatabase::LOCK_CONNECTION)
            ->select(sprintf('select pg_terminate_backend(%d)', $pid));
    }

    private static function tryLock(string $connection, string $function): bool
    {
        $acquired = DB::connection($connection)->selectOne(sprintf(
            'select %s(%d, %d)::int as acquired',
            $function,
            self::GATE_CLASS,
            self::GATE_OBJECT,
        ));

        return ((int) $acquired->acquired) === 1;
    }
}
