<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Relay;

use InvalidArgumentException;

final readonly class RelayConfiguration
{
    private function __construct(
        public string $queueConnection,
        public string $queue,
        public string $cacheStore,
        public int $batchSize,
        public int $startBudgetMs,
        public int $statementTimeoutMs,
        public int $reconciliationGraceSeconds,
        public int $backoffBaseSeconds,
        public int $backoffMaxSeconds,
        public int $heartbeatFreshnessSeconds,
        public int $heartbeatTtlSeconds,
        public float $redisTimeout,
        public float $redisReadTimeout,
    ) {}

    /**
     * @param  array<array-key, mixed>  $config  `payments.relay`
     * @param  array<array-key, mixed>  $transport  `database.redis.payments`
     */
    public static function fromArray(array $config, array $transport): self
    {
        $instance = new self(
            queueConnection: self::name($config, 'queue_connection'),
            queue: self::name($config, 'queue'),
            cacheStore: self::name($config, 'cache_store'),
            batchSize: self::atLeast($config, 'batch_size', 1),
            startBudgetMs: self::atLeast($config, 'start_budget_ms', 1),
            statementTimeoutMs: self::atLeast($config, 'statement_timeout_ms', 1),
            // Never zero: an accepted row would be republished on every pass.
            reconciliationGraceSeconds: self::atLeast($config, 'reconciliation_grace_seconds', 1),
            backoffBaseSeconds: self::atLeast($config, 'backoff_base_seconds', 1),
            backoffMaxSeconds: self::atLeast($config, 'backoff_max_seconds', 1),
            heartbeatFreshnessSeconds: self::atLeast($config, 'heartbeat_freshness_seconds', 1),
            heartbeatTtlSeconds: self::atLeast($config, 'heartbeat_ttl_seconds', 1),
            redisTimeout: self::positiveSeconds($transport, 'timeout'),
            redisReadTimeout: self::positiveSeconds($transport, 'read_timeout'),
        );

        if ($instance->heartbeatTtlSeconds <= $instance->heartbeatFreshnessSeconds) {
            throw new InvalidArgumentException(sprintf(
                'payments.relay.heartbeat_ttl_seconds (%d) must exceed heartbeat_freshness_seconds (%d), '
                .'or a stale heartbeat expires before it can be reported as stale.',
                $instance->heartbeatTtlSeconds,
                $instance->heartbeatFreshnessSeconds,
            ));
        }

        $instance->backoff();

        return $instance;
    }

    public function backoff(): TransportBackoff
    {
        return new TransportBackoff(
            baseSeconds: $this->backoffBaseSeconds,
            maxSeconds: $this->backoffMaxSeconds,
        );
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function name(array $config, string $key): string
    {
        $value = self::required($config, $key);

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf(
                'payments.relay.%s must be a non-empty string, got %s.',
                $key,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function atLeast(array $config, string $key, int $minimum): int
    {
        $value = self::required($config, $key);

        if (! is_int($value)) {
            throw new InvalidArgumentException(sprintf(
                'payments.relay.%s must be an integer, got %s. Check the (int) cast in config/payments.php.',
                $key,
                get_debug_type($value),
            ));
        }

        if ($value < $minimum) {
            throw new InvalidArgumentException(sprintf(
                'payments.relay.%s must be at least %d, got %d.',
                $key,
                $minimum,
                $value,
            ));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $transport
     */
    private static function positiveSeconds(array $transport, string $key): float
    {
        $value = self::required($transport, $key, 'database.redis.payments');

        if (! is_float($value) && ! is_int($value)) {
            throw new InvalidArgumentException(sprintf(
                'database.redis.payments.%s must be a number, got %s. An empty or non-numeric '
                .'environment value casts to 0.0, which phpredis reads as NO timeout; check the '
                .'(float) cast in config/database.php.',
                $key,
                get_debug_type($value),
            ));
        }

        $seconds = (float) $value;

        if (! is_finite($seconds) || $seconds <= 0.0) {
            throw new InvalidArgumentException(sprintf(
                'database.redis.payments.%s must be a finite number of seconds greater than zero, got %s. '
                .'Zero means NO timeout to phpredis, so an unreachable Redis would hold the relay\'s open '
                .'transaction — and its row lock — until the process died. Set PAYMENTS_RELAY_REDIS%s in .env.',
                $key,
                var_export($seconds, true),
                $key === 'timeout' ? '_TIMEOUT' : '_READ_TIMEOUT',
            ));
        }

        return $seconds;
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function required(array $config, string $key, string $namespace = 'payments.relay'): mixed
    {
        if (! array_key_exists($key, $config)) {
            throw new InvalidArgumentException(
                "{$namespace}.{$key} is missing. Every relay value is declared in configuration; "
                .'a default substituted here would let the config file and the running relay disagree silently.'
            );
        }

        return $config[$key];
    }
}
