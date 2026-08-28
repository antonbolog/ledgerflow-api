<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Relay;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Proof that the relay process is alive, independent of whether it had work.
 *
 * WHY THIS EXISTS AT ALL. An empty backlog and a dead scheduler look identical
 * from the outbox: no rows move in either case. The runtime contract requires a
 * stopped scheduler to be visible even while HTTP and workers are healthy, and
 * only a signal written on every handled pass can do that.
 *
 * APPLICATION UTC, NOT PostgreSQL now(). Every outbox timestamp comes from the
 * database because eligibility and grace are arithmetic on one transaction clock.
 * This is a different fact: it records that a PROCESS completed a pass, so it is
 * the process's own clock that matters. Reading it from PostgreSQL would also make
 * a database outage look like a scheduler outage, which is the one confusion this
 * signal exists to prevent.
 *
 * NOT THE DEFAULT CACHE STORE. `payments-relay` is pinned to the bounded `payments`
 * Redis connection, so a heartbeat write cannot retry or block for longer than the
 * pass it describes. The store carries its own literal prefix.
 *
 * IT IS LIVENESS, NOT TRUTH. PostgreSQL keeps delivery truth; a lost heartbeat
 * makes the scheduler look stopped, which is the correct alarm rather than a
 * correctness problem.
 */
final readonly class RelayHeartbeat
{
    /** Literal, and namespaced by the store's own prefix rather than by APP_NAME. */
    public const string KEY = 'scheduler-heartbeat';

    /** The canonical UTC-microsecond shape PaymentInitiated already uses. */
    public const string FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private CacheFactory $cache,
        private RelayConfiguration $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * Record one completed pass.
     *
     * A CACHE FAILURE WARNS AND RETURNS. By the time this runs the messages are in
     * Redis and the rows are committed; turning a cache outage into a failed pass
     * would report a delivery problem that did not happen and would leave the next
     * pass to republish for no reason. The missing heartbeat is itself the signal —
     * the status command will report the scheduler as unhealthy, which is true.
     */
    public function beat(): void
    {
        try {
            $this->cache->store($this->config->cacheStore)->put(
                self::KEY,
                CarbonImmutable::now()->utc()->format(self::FORMAT),
                $this->config->heartbeatTtlSeconds,
            );
        } catch (Throwable $failure) {
            $this->logger->warning('Payments outbox relay: could not record the scheduler heartbeat.', [
                'category' => 'relay_heartbeat_write_failed',
                'exception_class' => $failure::class,
            ]);
        }
    }

    /**
     * The heartbeat as the status surface reports it.
     *
     * FIVE STATES, AND THEY ARE NOT INTERCHANGEABLE:
     *
     *   fresh       within the freshness window — the scheduler is running
     *   stale       written, but too long ago — the scheduler has stopped
     *   missing     nothing stored — never run, or gone past its TTL
     *   unavailable the cache itself could not be read — an infrastructure fault,
     *               NOT evidence about the scheduler
     *   invalid     stored, but not a timestamp this code wrote, or in the future
     *
     * Collapsing `unavailable` into `missing` would report a stopped scheduler
     * during a Redis outage, sending an operator after the wrong process.
     *
     * A MALFORMED OR FUTURE VALUE IS NEVER ECHOED. Whatever is in that key came from
     * outside this method's control, and a status surface is exactly the wrong place
     * to render an unvalidated string.
     *
     * @return array{state: string, timestamp: string|null, age_seconds: int|null}
     */
    public function read(): array
    {
        try {
            $raw = $this->cache->store($this->config->cacheStore)->get(self::KEY);
        } catch (Throwable) {
            return self::withoutTimestamp('unavailable');
        }

        if ($raw === null) {
            return self::withoutTimestamp('missing');
        }

        if (! is_string($raw)) {
            return self::withoutTimestamp('invalid');
        }

        try {
            // createFromFormat with an exact format, not parse(): parse() would
            // happily accept `tomorrow`, a bare year, or most of a log line.
            $beatAt = CarbonImmutable::createFromFormat(self::FORMAT, $raw, 'UTC');
        } catch (Throwable) {
            return self::withoutTimestamp('invalid');
        }

        // createFromFormat() may signal a malformed value by returning null instead
        // of throwing, depending on the failure. Either shape is the same fact.
        if ($beatAt === null) {
            return self::withoutTimestamp('invalid');
        }

        $now = CarbonImmutable::now()->utc();

        // A heartbeat from the future is a clock disagreement between hosts, which
        // makes every age computed from it meaningless.
        if ($beatAt->greaterThan($now)) {
            return self::withoutTimestamp('invalid');
        }

        $age = max(0, $now->getTimestamp() - $beatAt->getTimestamp());

        return [
            'state' => $age <= $this->config->heartbeatFreshnessSeconds ? 'fresh' : 'stale',
            'timestamp' => $beatAt->format(self::FORMAT),
            'age_seconds' => $age,
        ];
    }

    /** @return array{state: string, timestamp: null, age_seconds: null} */
    private static function withoutTimestamp(string $state): array
    {
        return ['state' => $state, 'timestamp' => null, 'age_seconds' => null];
    }
}
