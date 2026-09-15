<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Relay;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

// Application clock, not PostgreSQL now(): a database outage must not look like a stopped scheduler.
final readonly class RelayHeartbeat
{
    public const string KEY = 'scheduler-heartbeat';

    public const string FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private CacheFactory $cache,
        private RelayConfiguration $config,
        private LoggerInterface $logger,
    ) {}

    // A cache failure only warns: messages are published and rows committed, and the missing heartbeat is the signal.
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
            $beatAt = CarbonImmutable::createFromFormat(self::FORMAT, $raw, 'UTC');
        } catch (Throwable) {
            return self::withoutTimestamp('invalid');
        }

        if ($beatAt === null) {
            return self::withoutTimestamp('invalid');
        }

        $now = CarbonImmutable::now()->utc();

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
