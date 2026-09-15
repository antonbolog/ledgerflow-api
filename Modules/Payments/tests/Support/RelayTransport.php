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
 * Moves only the logical database of the production `payments` connection.
 * Cleanup deletes named keys only and never enumerates or flushes the keyspace.
 */
final class RelayTransport
{
    public const string REDIS_CONNECTION = 'payments';

    public const string QUEUE_CONNECTION = 'payments-projection';

    public const string QUEUE = 'payments-projection';

    public const int DATABASE = 15;

    /** @var list<string> */
    public const array KEYS = [
        'queues:'.self::QUEUE,
        'queues:'.self::QUEUE.':notify',
        'queues:'.self::QUEUE.':delayed',
        'queues:'.self::QUEUE.':reserved',
    ];

    public static function configure(): void
    {
        self::override(['database' => self::DATABASE]);
    }

    /** Deletes both the prefixed and bare spellings, and touches nothing on an unexpected database. */
    public static function teardown(): void
    {
        try {
            if (self::databaseNumber() !== self::DATABASE) {
                return;
            }

            self::withoutClientPrefix(static function (PhpRedis $client, string $prefix): void {
                foreach (self::KEYS as $key) {
                    $client->del($key);

                    if ($prefix !== '') {
                        $client->del($prefix.$key);
                    }
                }
            });
        } catch (Throwable) {
            // A teardown error must not replace the test's own failure.
        } finally {
            try {
                self::forgetResolvedInstances();
            } catch (Throwable) {
                // A teardown error must not replace the test's own failure.
            }
        }
    }

    public static function databaseNumber(): int
    {
        return (int) self::client()->getDbNum();
    }

    public static function client(): PhpRedis
    {
        $client = Redis::connection(self::REDIS_CONNECTION)->client();

        // A predis client would answer these option reads with nulls instead of failing.
        if (! $client instanceof PhpRedis) {
            throw new RuntimeException(
                'The payments Redis connection did not resolve a phpredis client, so the selected '
                .'database, prefix, timeouts and retry option cannot be read from the server. '
                .'REDIS_CLIENT must stay phpredis.'
            );
        }

        return $client;
    }

    public static function clientPrefix(): string
    {
        return (string) self::client()->getOption(PhpRedis::OPT_PREFIX);
    }

    /** @return list<string> the logical names that exist under the given physical prefix */
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
     * Always restores the prefix: the client is a shared singleton, and a leaked empty prefix would break later commands.
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

    public static function size(): int
    {
        return (int) Queue::connection(self::QUEUE_CONNECTION)->size(self::QUEUE);
    }

    public static function pendingSize(): int
    {
        return (int) Queue::connection(self::QUEUE_CONNECTION)->pendingSize(self::QUEUE);
    }

    /** @return Collection<int, InspectedJob> */
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
