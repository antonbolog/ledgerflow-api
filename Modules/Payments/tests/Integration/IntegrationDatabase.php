<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Integration;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use RuntimeException;
use Throwable;

/**
 * The default connection really commits and the observer connection proves it; the lock connection supplies contention.
 * Never purge the default mid-test: resolved services hold its Connection object.
 */
final class IntegrationDatabase
{
    public const string OBSERVER = 'pgsql_t6_observer';

    public const string LOCK = 'pgsql_t6_lock';

    // Bounded so a lock held outside this process fails with a diagnosis instead of hanging.
    public const string TRUNCATE_LOCK_TIMEOUT = '5s';

    public const string LOCK_TIMEOUT_SQLSTATE = '55P03';

    private static bool $schemaEnsured = false;

    /** @var list<string> */
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

    /** Never fix a missing relation by adding RefreshDatabase: it turns every commit into a savepoint. */
    public static function ensureSchema(): void
    {
        // The flag applies pending migrations once per process; the table check rebuilds after another suite's migrate:fresh.
        if (self::$schemaEnsured && Schema::hasTable('payment_idempotency_receipts')) {
            return;
        }

        Artisan::call('migrate', ['--force' => true]);

        self::$schemaEnsured = true;
    }

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

    /** Refuses rather than unwraps: rolling back a wrapper here would hide the condition this suite must detect. */
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

    /** Asks the PDO too: a throwing commit listener can leave the server transaction open while transactionLevel() is 0. */
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

    /** Restores the seeded control row, which is schema rather than fixture data. */
    public static function truncate(?string $lockTimeout = null): void
    {
        $lockTimeout ??= self::TRUNCATE_LOCK_TIMEOUT;

        // Session-level, not a transaction: committing one would fire a still-registered commit listener that deadlocks on the observer.
        DB::statement("set lock_timeout = '".$lockTimeout."'");

        try {
            DB::statement('truncate '.implode(', ', self::TABLES).' restart identity cascade');
            self::restoreProjectionControls();
        } catch (Throwable $exception) {
            if (RetryingAtomicTransaction::sqlStateOf($exception) !== self::LOCK_TIMEOUT_SQLSTATE) {
                throw $exception;
            }

            throw new RuntimeException(
                'Could not truncate the Integration tables within '.$lockTimeout.'. '
                .'Something outside this process holds a lock on them — almost certainly a backend '
                .'leaked by a run that was killed mid-test, which stays `idle in transaction` until '
                .'its container dies. Clear it, then re-run: '
                .'docker rm -f $(docker ps -aq --filter label=com.docker.compose.project=${COMPOSE_PROJECT_NAME:-ledgerflow} '
                .'--filter label=com.docker.compose.oneoff=True)',
                previous: $exception,
            );
        } finally {
            DB::statement('set lock_timeout = default');
        }
    }

    /** Run before truncation: TRUNCATE would otherwise queue behind the row lock it is clearing. */
    public static function releaseLocks(): void
    {
        try {
            $lock = DB::connection(self::LOCK);

            while ($lock->transactionLevel() > 0) {
                $lock->rollBack();
            }
        } catch (Throwable) {
            // Already released or disconnected; never mask the test's own failure.
        }

        DB::purge(self::LOCK);
    }

    /**
     * Run last in afterEach, while the manager that owns these connections still exists;
     * a later purge would find a fresh manager and leak one backend per test.
     */
    public static function disconnectAll(): void
    {
        DB::purge(self::OBSERVER);
        DB::purge(self::LOCK);
        DB::purge();
    }

    public static function observedCount(string $table): int
    {
        return DB::connection(self::OBSERVER)->table($table)->count();
    }

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

    private static function restoreProjectionControls(): void
    {
        DB::statement(
            <<<'SQL'
            insert into payment_projection_controls (projection, state, created_at, updated_at)
            values (?, ?, now(), now())
            on conflict (projection) do update set
                state = excluded.state,
                owner_token = null,
                owner_pid = null,
                started_at = null,
                cleared_at = null,
                updated_at = excluded.updated_at
            SQL,
            [ProjectionGate::PAYMENT_DETAILS, ProjectionGate::READY],
        );
    }
}
