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

    /**
     * Tables the spike owns. Order matters only for readability; none are related.
     *
     * spike_outbox_delivery (T4) MUST stay in this list. It has no unique key,
     * so nothing else would catch a leaked committed row: T3's tables are
     * protected by the receipt-reservation guard failing on a duplicate key,
     * and this one has no equivalent.
     */
    private const array TABLES = [
        'stored_events',
        'snapshots',
        'spike_payment_receipts',
        'spike_payment_outbox',
        'spike_lock_probe',
        'spike_outbox_delivery',
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
            // T3 Task 4's contention target. Its own table so receipt counts stay
            // unambiguous when a retry rolls an attempt back.
            $schema->create('spike_lock_probe', function (Blueprint $table): void {
                $table->unsignedInteger('id')->primary();
            });
        }

        if (! $schema->hasTable('spike_outbox_delivery')) {
            // T4's durable outbox surrogate. THREE COLUMNS, and it stays three
            // columns: no available_at, no backoff, no exhausted_at, no partial
            // index. The eligibility predicate is literally
            // "last_published_at is null", the smallest thing that can tell a
            // pre-attempt row from a published one. Anything more would make
            // this a partial relay implementation, which is T7's, and TODOS.md
            // already forbids anticipating that design.
            $schema->create('spike_outbox_delivery', function (Blueprint $table): void {
                $table->uuid('event_id')->primary();
                $table->timestampTz('last_published_at')->nullable();
                $table->integer('publish_count')->default(0);
            });
        }
    }

    /**
     * What the server says this connection is attached to, not what config
     * claims. Same reasoning as the infrastructure contract test: config can
     * report one thing while the connection resolves somewhere else entirely.
     */
    public static function databaseName(string $connection): string
    {
        return (string) DB::connection($connection)
            ->selectOne('select current_database() as name')->name;
    }

    /**
     * Row counts for every spike table that exists, read from the OBSERVER.
     *
     * This is the only leak detector that works. A stale transaction counter is
     * erased when DB::purge() unsets the connection and the next
     * DB::connection() builds a fresh Connection object reporting
     * transactionLevel() === 0 — but committed rows survive that, invisibly, and
     * would be counted by whichever test ran next.
     *
     * @return array<string, int>
     */
    public static function observedRowCounts(): array
    {
        $connection = DB::connection();
        $schema = Schema::connection(DB::getDefaultConnection());

        $counts = [];

        foreach (self::TABLES as $table) {
            if ($schema->hasTable($table)) {
                $counts[$table] = $connection->table($table)->count();
            }
        }

        return $counts;
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
     * a later purge performs, silently leaking rows into the next test. It also
     * keeps a DELETE from blocking on a FOR UPDATE row lock a failed attempt left
     * behind.
     *
     * releaseAdvisoryLocks() sits between them (T4). Transaction-scoped locks die
     * with their transaction, so this exists for the session-level ones; running
     * it unconditionally means no test has to remember.
     *
     * Failures here are swallowed on purpose. A teardown error must never replace
     * the test's own failure as the reported cause, and leaked state is detected
     * loudly by the next test's beforeEach guards (distinct backends, transaction
     * level 0 on both connections, no advisory locks on the gate key, and zero
     * rows in every spike table) rather than quietly here.
     */
    public static function teardown(): void
    {
        try {
            try {
                self::rollbackOpenTransactions();
            } finally {
                try {
                    self::releaseAdvisoryLocks();
                } finally {
                    self::cleanup();
                }
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
     * Drop every session-level advisory lock both spike connections hold.
     *
     * Guarded per connection rather than as one loop body: a terminated backend
     * makes its own call throw, and skipping the other connection because of it
     * would leak a lock into the next test, where a stale holder makes every
     * contention result meaningless in the direction of a false pass.
     *
     * A throw here is expected and harmless in one case: a backend that has been
     * terminated already released everything it held, so there is nothing left
     * to unlock.
     */
    public static function releaseAdvisoryLocks(): void
    {
        foreach ([self::CONNECTION, self::LOCK_CONNECTION] as $name) {
            try {
                DB::connection($name)->select('select pg_advisory_unlock_all()');
            } catch (Throwable) {
                // See the note above.
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
