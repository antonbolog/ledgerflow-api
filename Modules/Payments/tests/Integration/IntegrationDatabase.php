<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Integration;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use RuntimeException;
use Throwable;

/**
 * Test-only database harness for the T6 commit-boundary suite.
 *
 *   ledgerflow_test  (ONE physical database, three connections)
 *
 *     pgsql (default)          pgsql_t6_observer        pgsql_t6_lock
 *     UNIT UNDER TEST          OBSERVER                 CONTENTION SOURCE
 *     real BEGIN / COMMIT      reads committed state    holds FOR UPDATE
 *     NOT wrapped              never writes
 *
 * THE ROLES ARE INVERTED FROM THE T3/T4 SPIKES, AND THAT INVERSION IS THE POINT.
 * There, RefreshDatabase wrapped the default connection, so the spike had to move
 * the code under test onto a named connection to escape the wrapper. Here there is
 * no RefreshDatabase at all: the DEFAULT connection is the one the production
 * services resolve (ConnectionInterface is an alias of db.connection), so it is the
 * one that must really commit, and the observer is what proves that it did.
 *
 * NEVER PURGE THE DEFAULT CONNECTION. RetryingAtomicTransaction,
 * DatabaseIdempotencyReceipts and PaymentOutboxWriter each hold a resolved
 * Connection object handed to them by the container; purging would leave them
 * pointing at a dead PDO while config still looked correct.
 */
final class IntegrationDatabase
{
    public const string OBSERVER = 'pgsql_t6_observer';

    public const string LOCK = 'pgsql_t6_lock';

    /**
     * How long truncation waits for ACCESS EXCLUSIVE before giving up.
     *
     * Generous: nothing legitimate holds these tables at this point, because
     * afterEach releases the lock connection before it truncates. It exists only so
     * that an ILLEGITIMATE holder produces a diagnosable failure instead of a hang.
     */
    public const string TRUNCATE_LOCK_TIMEOUT = '5s';

    /** `lock_not_available` — what PostgreSQL raises when lock_timeout expires. */
    public const string LOCK_TIMEOUT_SQLSTATE = '55P03';

    /** Whether ensureSchema() has already applied pending migrations this process. */
    private static bool $schemaEnsured = false;

    /**
     * Truncated in dependency order, before AND after every test.
     *
     * `cascade` makes the order cosmetic for correctness, but it is kept honest so
     * that removing `cascade` later fails loudly rather than silently leaving rows.
     *
     * @var list<string>
     */
    public const array TABLES = [
        'payment_details',
        'payment_projector_receipts',
        'payment_outbox',
        'payment_idempotency_receipts',
        'stored_events',
        'snapshots',
        'users',
        'merchants',
    ];

    /**
     * E2. THIS SUITE MUST PROVISION ITS OWN SCHEMA.
     *
     * RefreshDatabase runs migrate:fresh exactly once per process, gated on the
     * static RefreshDatabaseState::$migrated, and only from a RefreshDatabase test.
     * A suite without the trait inherits a schema only by accident of ordering, so
     * on a fresh ledgerflow_test every test here would die on "relation
     * payment_idempotency_receipts does not exist" whenever it ran before the
     * Feature suite. This suite is DECLARED BEFORE Feature in phpunit.xml precisely
     * so that it always does run first — which makes this method load-bearing on
     * every run rather than dead code that only matters in a launch mode nobody
     * uses.
     *
     * The dangerous repair is worse than the failure: adding RefreshDatabase to make
     * the error go away silently converts every commit into a savepoint, and this
     * suite stops proving the one thing it exists to prove.
     *
     * Idempotent, and modelled on SpikeDatabase::ensureSchema(), which solved the
     * same problem for T3/T4.
     *
     * ONCE PER PROCESS, NOT GATED ON ONE TABLE NAME. An earlier version ran
     * migrations only when `payment_idempotency_receipts` was missing, which is
     * true exactly once in a test database's life. Every migration added after
     * that point was therefore never applied to an existing ledgerflow_test, and
     * this suite failed on the new relation while `make test` looked healthy on a
     * machine that happened to have dropped its database recently. FOUND THAT WAY
     * by T11's control-row migration.
     *
     * `migrate` is already idempotent — it applies what is pending and nothing
     * else — so the static flag is about not paying for that check on every one
     * of this suite's tests, not about correctness.
     */
    public static function ensureSchema(): void
    {
        // THE TABLE CHECK IS THE SELF-HEAL, and it must stay. Another suite in
        // this process can drop every table under us — RefreshDatabase's
        // migrate:fresh does exactly that — and without this the rest of the run
        // would fail on a missing relation rather than rebuild.
        //
        // THE STATIC FLAG IS THE PENDING-MIGRATION PATH, and it is the half that
        // was missing. `payment_idempotency_receipts` exists exactly once in a
        // database's life, so a version gated only on it never applied a
        // migration added later: T11's control row was invisible to an existing
        // ledgerflow_test while `make test` looked healthy on a machine that had
        // dropped its database recently.
        if (self::$schemaEnsured && Schema::hasTable('payment_idempotency_receipts')) {
            return;
        }

        Artisan::call('migrate', ['--force' => true]);

        self::$schemaEnsured = true;
    }

    /**
     * Register the observer and the lock holder as plain clones of pgsql.
     *
     * Purges only those two. See the class docblock for why the default is
     * untouchable.
     */
    public static function register(): void
    {
        $base = config('database.connections.pgsql');

        config([
            'database.connections.'.self::OBSERVER => $base,
            'database.connections.'.self::LOCK => $base,
        ]);

        DB::purge(self::OBSERVER);
        DB::purge(self::LOCK);
    }

    /**
     * REFUSE to run wrapped, rather than quietly unwrapping.
     *
     * Found by mutation. An earlier version called rollbackOpenTransactions() in
     * beforeEach, which had the side effect of rolling back RefreshDatabase's
     * wrapper — so adding the trait to this suite changed nothing observable and
     * every commit-boundary claim went on passing for a reason that had stopped
     * being true. Silently repairing the one condition the suite exists to detect
     * is worse than failing.
     *
     * Cleanup belongs in afterEach, where the leak it fixes actually occurs. This
     * runs in beforeEach and only ever complains.
     */
    public static function refuseIfWrapped(): void
    {
        $connection = DB::connection();
        $level = $connection->transactionLevel();
        $inTransaction = $connection->getPdo()->inTransaction();

        if ($level > 0 || $inTransaction) {
            throw new RuntimeException(
                'The Integration suite arrived inside a transaction '
                ."(transactionLevel {$level}, pdo->inTransaction ".var_export($inTransaction, true).'). '
                .'Something has wrapped it — almost certainly RefreshDatabase. Every commit-boundary '
                .'claim in this suite becomes a savepoint under a wrapper: TransactionCommitting never '
                .'fires at depth 1 and no observer connection can witness a COMMIT.'
            );
        }
    }

    /**
     * Close any transaction the default connection left open — INCLUDING one that
     * Laravel no longer believes exists.
     *
     * MEASURED, NOT DEFENSIVE. When a `TransactionCommitting` listener throws,
     * Connection::transaction() routes into handleCommitTransactionException(),
     * which decrements `$this->transactions` to 0 and rethrows — and only rolls the
     * PDO back for a concurrency error with attempts remaining. For every other
     * failure the SERVER-SIDE transaction stays open while `transactionLevel()`
     * reports 0.
     *
     * The consequence is a hang, not a wrong assertion: the next `truncate()` runs
     * inside that orphaned transaction and takes ACCESS EXCLUSIVE on every table,
     * and the following test's observer SELECT blocks on it until the process is
     * killed. This suite hit exactly that before the PDO-level check below existed.
     *
     * So `transactionLevel()` is asked first, and then the PDO is asked directly,
     * because the two can disagree — the same class of trap T4 recorded when it
     * measured a stale `transactionLevel()` after a connection loss.
     */
    public static function rollbackOpenTransactions(): void
    {
        $connection = DB::connection();

        while ($connection->transactionLevel() > 0) {
            try {
                $connection->rollBack();
            } catch (Throwable) {
                break;
            }
        }

        try {
            $pdo = $connection->getPdo();

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable) {
            // Never let teardown mask the test's own failure.
        }
    }

    /**
     * TRUNCATE, not DELETE — the opposite of SpikeDatabase's choice, for the
     * opposite reason.
     *
     * The spike could not use TRUNCATE because the observer sat inside
     * RefreshDatabase's still-open transaction holding ACCESS SHARE on every table
     * it had read, and TRUNCATE needs ACCESS EXCLUSIVE. Nothing here holds an open
     * read transaction, so TRUNCATE is available — and it is what `restart
     * identity` needs, which keeps stored_events.id comparable between tests.
     *
     * $lockTimeout exists so the failure branch below can be TESTED rather than
     * merely asserted about. The one test that stages a real conflicting lock passes
     * a few hundred milliseconds; every other caller takes the constant and never
     * mentions it.
     */
    public static function truncate(?string $lockTimeout = null): void
    {
        $lockTimeout ??= self::TRUNCATE_LOCK_TIMEOUT;

        // BOUNDED, BECAUSE AN UNBOUNDED WAIT HERE WEDGES EVERY FUTURE RUN. MEASURED.
        //
        // TRUNCATE takes ACCESS EXCLUSIVE, so it queues behind ANY session still
        // holding a lock on these tables — including one whose PHP process is long
        // gone. releaseLocks() can only clean up after a test that returned; a run
        // killed mid-test leaves its `FOR UPDATE` holder `idle in transaction` until
        // its backend dies, and every later `make test` then blocks here forever with
        // no output and no diagnosis. That is not hypothetical: it happened, and it
        // cost a run of `make check` that produced nothing to read.
        //
        // NO TRANSACTION HERE, AND THAT IS NOT A STYLE CHOICE — MEASURED TOO. The
        // obvious shape is `DB::transaction(fn () => SET LOCAL … ; TRUNCATE …)`, and
        // it DEADLOCKS: the commit-boundary test's TransactionCommitting listener is
        // still registered when afterEach runs, so committing that wrapper fires it,
        // the listener reads through the OBSERVER connection, and the observer blocks
        // on the ACCESS EXCLUSIVE the very same transaction is holding. Neither side
        // can move. So the bound is set at session level and RESET in `finally`,
        // which fires no event and cannot leak into the Feature suite.
        DB::statement("set lock_timeout = '".$lockTimeout."'");

        try {
            DB::statement('truncate '.implode(', ', self::TABLES).' restart identity cascade');
        } catch (Throwable $exception) {
            // ONLY a lock timeout gets the explanation. Found by mutation: an earlier
            // version caught everything and blamed a leaked backend for a truncate
            // that had failed because the tables did not exist — a confident, wrong
            // diagnosis, which is worse than none. Anything else is rethrown as it
            // came, and the SQLSTATE is read with the same classifier production uses.
            if (RetryingAtomicTransaction::sqlStateOf($exception) !== self::LOCK_TIMEOUT_SQLSTATE) {
                throw $exception;
            }

            throw new RuntimeException(
                'Could not truncate the Integration tables within '.$lockTimeout.'. '
                .'Something outside this process holds a lock on them — almost certainly a backend '
                .'leaked by a run that was killed mid-test, which stays `idle in transaction` until '
                .'its container dies. Clear it, then re-run: '
                .'docker rm -f $(docker ps -aq --filter name=ledgerflow-app-run)',
                previous: $exception,
            );
        } finally {
            // The default connection outlives this suite — the Feature suite runs on
            // it next. Restoring the server default is what keeps the bound local to
            // truncation instead of becoming a silent global timeout.
            DB::statement('set lock_timeout = default');
        }
    }

    /**
     * E3. The retry tests hold `SELECT … FOR UPDATE` on a merchants row from the
     * lock connection. If a test fails before releasing it, the NEXT test's receipt
     * insert blocks on that same row's foreign-key check until the PHP process dies
     * — the suite hangs, or times out reporting an unrelated test.
     *
     * Unconditional, in afterEach, and BEFORE truncation: TRUNCATE would otherwise
     * queue behind the very lock it is trying to clear.
     */
    public static function releaseLocks(): void
    {
        try {
            $lock = DB::connection(self::LOCK);

            while ($lock->transactionLevel() > 0) {
                $lock->rollBack();
            }
        } catch (Throwable) {
            // Already released, or the backend is gone. Never let teardown mask the
            // real failure.
        }

        DB::purge(self::LOCK);
    }

    /**
     * Close every PostgreSQL backend this test opened, INCLUDING the default one.
     *
     * MEASURED, AFTER THE SUITE HIT `sorry, too many clients already`. Laravel's
     * TestCase discards the whole application — and with it the DatabaseManager —
     * in tearDown, so register()'s purge in beforeEach runs against a BRAND-NEW
     * manager that holds no connections at all. It frees nothing, and every test
     * left one idle backend behind: 60 Integration tests plus the Feature suite
     * crossed PostgreSQL's max_connections of 100, and the run died in a beforeEach
     * with a connection error that named nothing relevant.
     *
     * Purging in afterEach works because the manager that OWNS the connections is
     * still the current one at that point.
     *
     * PURGING THE DEFAULT HERE DOES NOT CONTRADICT the class rule above. That rule
     * forbids purging it MID-TEST, while RetryingAtomicTransaction,
     * DatabaseIdempotencyReceipts, PaymentOutboxWriter and the consumer each hold a
     * resolved Connection that would be left pointing at a dead PDO. By the time
     * this runs the test is over, truncation is done, and the next test resolves
     * everything again from a fresh container.
     *
     * LAST IN afterEach, always: truncate() and releaseLocks() both need a live
     * connection, and rollbackOpenTransactions() must have closed anything still
     * open — a purge would otherwise discard a transaction rather than end it.
     */
    public static function disconnectAll(): void
    {
        DB::purge(self::OBSERVER);
        DB::purge(self::LOCK);
        DB::purge();
    }

    /** Row counts read from the OBSERVER, so only COMMITTED rows are counted. */
    public static function observedCount(string $table): int
    {
        return DB::connection(self::OBSERVER)->table($table)->count();
    }

    /**
     * The transaction id that inserted a row, as PostgreSQL itself recorded it.
     *
     * Comparing xmin across the receipt, the stored event and the outbox row is the
     * database's own statement about which transaction wrote each tuple, rather
     * than a restatement of how the connections were configured.
     */
    public static function observedXmin(string $table): string
    {
        $row = DB::connection(self::OBSERVER)->selectOne("select xmin::text as x from {$table}");

        return (string) ($row->x ?? '');
    }

    public static function backendPid(?string $connection = null): int
    {
        $row = DB::connection($connection)->selectOne('select pg_backend_pid() as pid');

        return (int) ($row->pid ?? 0);
    }
}
