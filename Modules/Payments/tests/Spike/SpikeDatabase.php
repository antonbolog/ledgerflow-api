<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Spike;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\EventSourcing\Snapshots\EloquentSnapshot;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Spatie\EventSourcing\StoredEvents\Repositories\StoredEventRepository;
use Throwable;

/**
 * Test-only database harness for the T3 compatibility spike.
 *
 *   ledgerflow_test  (ONE physical database, three connections)
 *
 *     pgsql (default)          pgsql_spike              pgsql_lock
 *     OBSERVER                 UNIT UNDER TEST          CONTENTION SOURCE
 *     wrapped by               unwrapped                unwrapped
 *     RefreshDatabase          real BEGIN / COMMIT      holds FOR UPDATE
 *     never commits
 *
 * RefreshDatabase wraps only config('database.default') (its connectionsToTransact()
 * returns exactly that), so both connections registered here reach PostgreSQL with
 * no open transaction and can really commit. That is the whole point: the spike has
 * to observe a genuine COMMIT boundary, and a savepoint inside the wrapper would
 * prove nothing.
 *
 * NEVER commit or roll back the default connection from a spike test.
 * RefreshDatabase's teardown sets RefreshDatabaseState::$migrated = false when the
 * default PDO is no longer in a transaction, which re-runs migrate:fresh on the next
 * test and drops every table here.
 */
final class SpikeDatabase
{
    public const string CONNECTION = 'pgsql_spike';

    public const string LOCK_CONNECTION = 'pgsql_lock';

    /** Tables the spike owns. Order matters only for readability; none are related. */
    private const array TABLES = [
        'stored_events',
        'snapshots',
        'spike_payment_receipts',
        'spike_payment_outbox',
        'spike_lock_probe',
    ];

    private static ?string $originalStoredEventModel = null;

    private static ?string $originalSnapshotModel = null;

    /**
     * Register both extra connections as plain clones of pgsql.
     */
    public static function configure(): void
    {
        $base = config('database.connections.pgsql');

        config([
            'database.connections.'.self::CONNECTION => $base,
            'database.connections.'.self::LOCK_CONNECTION => $base,
        ]);

        self::purgeConnections();
    }

    /**
     * Point Spatie's Eloquent models at the spike connection.
     *
     * This is the named-connection mechanism. EloquentStoredEvent pins $table but
     * no $connection, so it would otherwise follow the default connection and its
     * writes would land inside RefreshDatabase's wrapper transaction, where they
     * could never be observed committing.
     *
     * forgetInstance() is required rather than decorative: the package binds
     * StoredEventRepository as a singleton whose concrete class is read from config
     * at register time, so anything that resolved it earlier in this test would keep
     * a repository built against the default model.
     */
    public static function useSpikeModels(): void
    {
        self::$originalStoredEventModel = self::configuredClass('event-sourcing.stored_event_model', EloquentStoredEvent::class);
        self::$originalSnapshotModel = self::configuredClass('event-sourcing.snapshot_model', EloquentSnapshot::class);

        config([
            'event-sourcing.stored_event_model' => SpikeStoredEvent::class,
            'event-sourcing.snapshot_model' => SpikeSnapshot::class,
        ]);

        app()->forgetInstance(StoredEventRepository::class);
    }

    /**
     * Put the original models back. Laravel rebuilds the application per test, so
     * this cannot leak on its own; it exists so a failure inside one test cannot
     * make the next test's guard assert against state it did not set.
     */
    public static function restoreModels(): void
    {
        if (self::$originalStoredEventModel !== null) {
            config(['event-sourcing.stored_event_model' => self::$originalStoredEventModel]);
            self::$originalStoredEventModel = null;
        }

        if (self::$originalSnapshotModel !== null) {
            config(['event-sourcing.snapshot_model' => self::$originalSnapshotModel]);
            self::$originalSnapshotModel = null;
        }

        app()->forgetInstance(StoredEventRepository::class);
    }

    /**
     * Create the spike schema, idempotently, on the spike connection.
     *
     * stored_events and snapshots come from migrations copied byte-for-byte from
     * the vendor package, because Spatie publishes those migrations and never loads
     * them (its provider calls hasMigrations() but never runsMigrations(), which
     * defaults to false). They are run through the migrator with --database so the
     * stubs' bare Schema::create() follows the spike connection: Migrator::runMethod()
     * swaps the default connection around up(), and the Schema facade sets
     * $cached = false so it re-resolves per call and honours that swap.
     *
     * The three spike-owned tables are created directly through
     * Schema::connection() instead, so no ambient default-connection state is
     * involved at all.
     */
    public static function ensureSchema(): void
    {
        $schema = Schema::connection(self::CONNECTION);

        if (! $schema->hasTable('stored_events') || ! $schema->hasTable('snapshots')) {
            Artisan::call('migrate', [
                '--path' => __DIR__.'/migrations',
                '--realpath' => true,
                '--database' => self::CONNECTION,
                '--force' => true,
            ]);
        }

        if (! $schema->hasTable('spike_payment_receipts')) {
            $schema->create('spike_payment_receipts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('merchant_id');
                $table->string('idempotency_key_hash', 64);

                // The real index Q5's 23505 comes from.
                $table->unique(['merchant_id', 'idempotency_key_hash']);
            });
        }

        if (! $schema->hasTable('spike_payment_outbox')) {
            $schema->create('spike_payment_outbox', function (Blueprint $table): void {
                $table->uuid('event_id')->primary();
            });
        }

        if (! $schema->hasTable('spike_lock_probe')) {
            // Task 4's contention target. Its own table so receipt counts stay
            // unambiguous when a retry rolls an attempt back.
            $schema->create('spike_lock_probe', function (Blueprint $table): void {
                $table->unsignedInteger('id')->primary();
            });
        }
    }

    /**
     * The whole teardown, ordered and failure-safe.
     *
     * Every step runs even when an earlier one throws, which is why the nesting
     * is explicit rather than four sequential calls: a test that fails midway
     * through an open transaction must not also leave the models swapped or two
     * PostgreSQL sessions open.
     *
     * Order is load-bearing. Rolling back before cleanup matters because a DELETE
     * issued inside a still-open transaction would be undone by the rollback that
     * a later purge performs, silently leaking rows into the next test.
     *
     * Failures here are swallowed on purpose. A teardown error must never replace
     * the test's own failure as the reported cause, and leaked state is detected
     * loudly by the next test's beforeEach guards (transaction level 0 on both
     * connections, schema present, config swapped) rather than quietly here.
     */
    public static function teardown(): void
    {
        try {
            try {
                self::rollbackOpenTransactions();
            } finally {
                self::cleanup();
            }
        } catch (Throwable) {
            // Deliberate. See the note above.
        } finally {
            try {
                try {
                    self::restoreModels();
                } finally {
                    self::purgeConnections();
                }
            } catch (Throwable) {
                // Deliberate. See the note above.
            }
        }
    }

    /**
     * Roll back BOTH spike connections, not just the lock holder.
     *
     * A test that fails between beginTransaction() and commit() leaves the spike
     * connection mid-transaction too. Cleanup would then run inside that doomed
     * transaction and be discarded.
     */
    public static function rollbackOpenTransactions(): void
    {
        foreach ([self::CONNECTION, self::LOCK_CONNECTION] as $name) {
            $connection = DB::connection($name);

            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
    }

    /**
     * DELETE, never TRUNCATE.
     *
     * Pest runs afterEach BEFORE parent::tearDown(), so the observer connection is
     * still inside RefreshDatabase's transaction and still holds ACCESS SHARE on
     * every table it read. TRUNCATE needs ACCESS EXCLUSIVE and would block on that
     * for as long as the suite runs. DELETE takes ROW EXCLUSIVE, which does not
     * conflict.
     *
     * Tolerates a partially created schema: ensureSchema() can abort halfway, and
     * a teardown that then threw "relation does not exist" would skip the tables
     * that DO exist and leave their rows behind.
     */
    public static function cleanup(): void
    {
        $connection = DB::connection(self::CONNECTION);
        $schema = Schema::connection(self::CONNECTION);

        foreach (self::TABLES as $table) {
            if ($schema->hasTable($table)) {
                $connection->table($table)->delete();
            }
        }
    }

    /**
     * Close both real PostgreSQL sessions deterministically, rather than leaving
     * one idle backend per test to be collected whenever.
     */
    public static function purgeConnections(): void
    {
        DB::purge(self::CONNECTION);
        DB::purge(self::LOCK_CONNECTION);
    }

    /**
     * @return array{backend_pid: int, transaction_level: int}
     */
    public static function probe(): array
    {
        $connection = DB::connection(self::CONNECTION);

        return [
            'backend_pid' => (int) $connection->selectOne('select pg_backend_pid() as pid')->pid,
            'transaction_level' => $connection->transactionLevel(),
        ];
    }

    /**
     * The transaction id that inserted a row, as PostgreSQL itself recorded it.
     *
     * This is the Q1 evidence. Comparing xmin across the receipt, the stored event
     * and the outbox row is the database's own statement about which transaction
     * wrote each tuple, rather than a restatement of how the connections were
     * configured.
     */
    public static function xmin(string $table): string
    {
        return (string) DB::connection(self::CONNECTION)
            ->selectOne("select xmin::text as x from {$table}")->x;
    }

    private static function configuredClass(string $key, string $fallback): string
    {
        $value = config($key, $fallback);

        return is_string($value) ? $value : $fallback;
    }
}
