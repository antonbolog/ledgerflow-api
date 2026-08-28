<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use Illuminate\Queue\Jobs\InspectedJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Redis as PhpRedis;
use RuntimeException;
use Throwable;

/**
 * The suite's grip on the production Payments transport.
 *
 * IT OVERRIDES THE PRODUCTION CONNECTION RATHER THAN REGISTERING A TEST TWIN.
 * `payments` and `payments-projection` are the names the relay resolves, so a
 * parallel test-only name would prove a configuration nothing uses. Only the
 * logical database moves — to 15 — which is what keeps the suite off the
 * developer's keyspace while every other value stays exactly as shipped.
 *
 * ORDER IS LOAD-BEARING. RedisManager captures `database.redis` once, when the
 * singleton is built (Redis/RedisServiceProvider.php:18-22), and QueueManager
 * caches the resolved queue per name (Queue/QueueManager.php:158-198). Laravel
 * builds a fresh application per test, so calling configure() in beforeEach is
 * ahead of both — and the instances are forgotten anyway, so a resolution that
 * already happened cannot survive.
 *
 * BLAST RADIUS. Database 15 reduces it and is NOT claimed as owned: correctness
 * comes from the key list. Nothing here enumerates the keyspace, and there is no
 * flushdb or flushall. The server-reported database number is asserted before any
 * deletion, because deleting on the strength of a failed assumption is exactly the
 * class of mistake the PostgreSQL guard layers exist to prevent.
 *
 * The T4 spike owns `t4spike:relay*` on the same database. These key sets are
 * disjoint by name and both are enumerated explicitly, so neither can delete the
 * other's state.
 */
final class RelayTransport
{
    public const string REDIS_CONNECTION = 'payments';

    public const string QUEUE_CONNECTION = 'payments-projection';

    public const string QUEUE = 'payments-projection';

    public const int DATABASE = 15;

    /**
     * Every key Laravel's Redis queue can create for this queue, by name.
     *
     * A push writes `queues:<name>` and `queues:<name>:notify`; `:delayed` and
     * `:reserved` exist in the key space for the other job states. All four are
     * listed so cleanup is complete without ever asking Redis what exists.
     *
     * @var list<string>
     */
    public const array KEYS = [
        'queues:'.self::QUEUE,
        'queues:'.self::QUEUE.':notify',
        'queues:'.self::QUEUE.':delayed',
        'queues:'.self::QUEUE.':reserved',
    ];

    /** Move only the logical database. Everything else stays as production ships it. */
    public static function configure(): void
    {
        self::override(['database' => self::DATABASE]);
    }

    /**
     * Delete only the four keys this queue can create — in both spellings.
     *
     * BOTH SPELLINGS, because M-1 measured that this connection inherits
     * database.redis.options.prefix and therefore stores its queue PREFIXED. Deleting
     * only the bare names would leave every key behind and silently seed the next
     * test's count. The prefix is cleared on the raw client first so each name is
     * addressed exactly as written rather than being prefixed a second time.
     *
     * A wrong database number means the override did not take, and it returns
     * without touching anything rather than guessing. Failures are swallowed for the
     * reason T3's teardown swallows: a teardown error must never replace the test's
     * own failure as the reported cause.
     */
    public static function teardown(): void
    {
        try {
            if (self::databaseNumber() !== self::DATABASE) {
                return;
            }

            self::withoutClientPrefix(static function (PhpRedis $client, string $prefix): void {
                foreach (self::KEYS as $key) {
                    // del on a missing key is a no-op. No keys('*'), no flushdb.
                    $client->del($key);

                    if ($prefix !== '') {
                        $client->del($prefix.$key);
                    }
                }
            });
        } catch (Throwable) {
            // Deliberate. See the note above.
        } finally {
            try {
                self::forgetResolvedInstances();
            } catch (Throwable) {
                // Deliberate.
            }
        }
    }

    /** What the Redis server says this connection selected, not what config claims. */
    public static function databaseNumber(): int
    {
        return (int) self::client()->getDbNum();
    }

    /** The live phpredis object, so options are read from the client rather than from config. */
    public static function client(): PhpRedis
    {
        $client = Redis::connection(self::REDIS_CONNECTION)->client();

        // The project pins phpredis. A predis client would answer none of the
        // option questions this class exists to ask, and would do so by returning
        // nulls rather than by failing — so the guard is loud instead.
        if (! $client instanceof PhpRedis) {
            throw new RuntimeException(
                'The payments Redis connection did not resolve a phpredis client, so the selected '
                .'database, prefix, timeouts and retry option cannot be read from the server. '
                .'REDIS_CLIENT must stay phpredis.'
            );
        }

        return $client;
    }

    /** The key prefix phpredis will apply to ordinary commands on this connection. */
    public static function clientPrefix(): string
    {
        return (string) self::client()->getOption(PhpRedis::OPT_PREFIX);
    }

    /**
     * Which of the four owned keys exist PHYSICALLY under the given prefix.
     *
     * The prefix is an explicit argument rather than an ambient client setting,
     * because the whole question M-1 asks is which physical key a push produced. A
     * helper that silently applied the client's own prefix would answer with the
     * same assumption the caller is trying to check — and an earlier version of this
     * method did exactly that, reporting an empty list for a queue that held a
     * message.
     *
     * @return list<string> the LOGICAL names found, so callers compare against KEYS
     */
    public static function existingKeys(string $prefix = ''): array
    {
        return self::withoutClientPrefix(static function (PhpRedis $client) use ($prefix): array {
            $present = [];

            foreach (self::KEYS as $key) {
                if ((int) $client->exists($prefix.$key) > 0) {
                    $present[] = $key;
                }
            }

            return $present;
        });
    }

    /**
     * Run a callback with the client's key prefix cleared, and ALWAYS put it back.
     *
     * try/finally, not two straight-line statements. A failed `exists` or `del` —
     * a dropped connection, a Redis restart mid-test — would otherwise leave the
     * shared client with an empty OPT_PREFIX for the rest of the process. Every
     * later ordinary command in that test run would then address unprefixed keys:
     * cache reads would miss, and a `del` would target the wrong name. The client is
     * a resolved singleton, so that leak outlives the test that caused it and would
     * surface as unrelated failures somewhere else.
     *
     * @template TReturn
     *
     * @param  callable(PhpRedis, string): TReturn  $callback  receives the client and its ORIGINAL prefix
     * @return TReturn
     */
    private static function withoutClientPrefix(callable $callback): mixed
    {
        $client = self::client();
        $prefix = (string) $client->getOption(PhpRedis::OPT_PREFIX);

        $client->setOption(PhpRedis::OPT_PREFIX, '');

        try {
            return $callback($client, $prefix);
        } finally {
            $client->setOption(PhpRedis::OPT_PREFIX, $prefix);
        }
    }

    /** Ready + delayed + reserved, counted through the queue's own Lua script. */
    public static function size(): int
    {
        return (int) Queue::connection(self::QUEUE_CONNECTION)->size(self::QUEUE);
    }

    /** Ready only, counted through an ordinary llen. */
    public static function pendingSize(): int
    {
        return (int) Queue::connection(self::QUEUE_CONNECTION)->pendingSize(self::QUEUE);
    }

    /**
     * Ready jobs with their decoded payloads, read through the queue's own key
     * derivation rather than a hand-rolled key string.
     *
     * @return Collection<int, InspectedJob>
     */
    public static function pendingJobs(): Collection
    {
        return Queue::connection(self::QUEUE_CONNECTION)->pendingJobs(self::QUEUE);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private static function override(array $overrides): void
    {
        $base = config('database.redis.'.self::REDIS_CONNECTION);

        config([
            'database.redis.'.self::REDIS_CONNECTION => array_merge(
                is_array($base) ? $base : [],
                $overrides,
            ),
        ]);

        self::forgetResolvedInstances();
    }

    private static function forgetResolvedInstances(): void
    {
        app()->forgetInstance('redis');
        app()->forgetInstance('redis.connection');
        app()->forgetInstance('queue');
        app()->forgetInstance('cache');
    }
}
