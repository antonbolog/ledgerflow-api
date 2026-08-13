<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Spike;

use Illuminate\Queue\Jobs\InspectedJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Test-only Redis transport for the T4 spike.
 *
 *   redis (ONE server, logical database 15)
 *
 *     redis_spike                        redis_spike_deferred
 *     after_commit => false              after_commit => true
 *     queue t4spike:relay                queue t4spike:relay-deferred
 *     the real config/queue.php value    the false-green control
 *
 * BLAST RADIUS. Database 15 is chosen for isolation, not claimed as owned.
 * Nothing here flushes anything: cleanup deletes the eight keys the spike
 * itself creates, by name. An unrelated key on database 15 is neither counted
 * by the guards nor destroyed by teardown. The getDbNum() assertion is a
 * CONFIGURATION guard proving the per-connection override took effect, never a
 * licence to delete.
 *
 * The empty prefix is deliberate. phpredis applies OPT_PREFIX to ordinary
 * commands but the queue writes its keys as arguments to a Lua eval, so a
 * non-empty prefix could make the writer and the reader disagree about which
 * key they are talking about. Setting it to '' removes the question, and the
 * Q9 test still asserts size() (eval side) against pendingSize()/pendingJobs()
 * (ordinary-command side) so a divergence would fail loudly rather than
 * silently report zero.
 */
final class SpikeRelayQueue
{
    public const string REDIS_CONNECTION = 'spike';

    public const string CONNECTION = 'redis_spike';

    public const string DEFERRED_CONNECTION = 'redis_spike_deferred';

    public const string QUEUE = 't4spike:relay';

    public const string DEFERRED_QUEUE = 't4spike:relay-deferred';

    public const int DATABASE = 15;

    /**
     * Every key the two spike queues can create, by name.
     *
     * Laravel's Redis queue writes queues:<name> and queues:<name>:notify on a
     * push, and uses :delayed and :reserved for the other job states. All eight
     * are listed so cleanup is complete without ever enumerating the keyspace.
     *
     * @var list<string>
     */
    public const array KEYS = [
        'queues:'.self::QUEUE,
        'queues:'.self::QUEUE.':notify',
        'queues:'.self::QUEUE.':delayed',
        'queues:'.self::QUEUE.':reserved',
        'queues:'.self::DEFERRED_QUEUE,
        'queues:'.self::DEFERRED_QUEUE.':notify',
        'queues:'.self::DEFERRED_QUEUE.':delayed',
        'queues:'.self::DEFERRED_QUEUE.':reserved',
    ];

    /**
     * Register the spike Redis connection and both queue connections.
     *
     * ORDER IS LOAD-BEARING. RedisServiceProvider reads database.redis once,
     * when it builds the manager singleton, so a connection added to config
     * later is invisible until the instance is forgotten. The queue connector
     * resolves app['redis'] inside a closure that QueueManager invokes on every
     * resolve(), so forgetting the instance BEFORE the first
     * Queue::connection() call is both sufficient and necessary.
     */
    public static function configure(): void
    {
        $base = config('database.redis.default');

        config([
            'database.redis.'.self::REDIS_CONNECTION => array_merge(
                is_array($base) ? $base : [],
                [
                    'database' => self::DATABASE,
                    'options' => ['prefix' => ''],
                ],
            ),
            'queue.connections.'.self::CONNECTION => [
                'driver' => 'redis',
                'connection' => self::REDIS_CONNECTION,
                'queue' => self::QUEUE,
                'retry_after' => 90,
                'block_for' => null,
                'after_commit' => false,
            ],
            'queue.connections.'.self::DEFERRED_CONNECTION => [
                'driver' => 'redis',
                'connection' => self::REDIS_CONNECTION,
                'queue' => self::DEFERRED_QUEUE,
                'retry_after' => 90,
                'block_for' => null,
                'after_commit' => true,
            ],
        ]);

        self::forgetRedisManager();
    }

    /**
     * Targeted cleanup, ordered and failure-safe.
     *
     * A wrong database number means the configuration did not take, and
     * deleting on the strength of a failed assumption is precisely the class of
     * mistake the PostgreSQL guard layers exist to prevent. So it returns
     * without touching anything rather than guessing.
     *
     * Failures are swallowed for the same reason T3's database teardown
     * swallows: a teardown error must never replace the test's own failure as
     * the reported cause. A leak is surfaced loudly by the next test's
     * beforeEach guard, which asserts all eight keys are absent.
     */
    public static function teardown(): void
    {
        try {
            if (self::dbNum() !== self::DATABASE) {
                return;
            }

            // del on a missing key is a no-op. No keys('*'), no flushdb, no
            // flushall: the spike deletes only what it created.
            Redis::connection(self::REDIS_CONNECTION)->del(self::KEYS);
        } catch (Throwable) {
            // Deliberate. See the note above.
        } finally {
            try {
                self::forgetRedisManager();
            } catch (Throwable) {
                // Deliberate.
            }
        }
    }

    /**
     * What the Redis server says this connection selected, not what config
     * claims.
     */
    public static function dbNum(): int
    {
        return (int) Redis::connection(self::REDIS_CONNECTION)->client()->getDbNum();
    }

    /**
     * Which of the eight spike-owned keys currently exist, in declaration
     * order. Used both as a guard (must be empty) and as the measurement of
     * which keys a real push actually populates.
     *
     * @return list<string>
     */
    public static function existingKeys(): array
    {
        $connection = Redis::connection(self::REDIS_CONNECTION);

        $present = [];

        foreach (self::KEYS as $key) {
            if ((int) $connection->exists($key) > 0) {
                $present[] = $key;
            }
        }

        return $present;
    }

    /** Ready + delayed + reserved, counted through the queue's own Lua script. */
    public static function size(string $queue): int
    {
        return (int) Queue::connection(self::CONNECTION)->size($queue);
    }

    /** Ready only, counted through an ordinary llen. */
    public static function pendingSize(string $queue): int
    {
        return (int) Queue::connection(self::CONNECTION)->pendingSize($queue);
    }

    /**
     * Ready jobs with their decoded payloads, read through the queue's own key
     * derivation rather than a hand-rolled key string.
     *
     * @return Collection<int, InspectedJob>
     */
    public static function pendingJobs(string $queue): Collection
    {
        return Queue::connection(self::CONNECTION)->pendingJobs($queue);
    }

    private static function forgetRedisManager(): void
    {
        app()->forgetInstance('redis');
        app()->forgetInstance('redis.connection');
    }
}
