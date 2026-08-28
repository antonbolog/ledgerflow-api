<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Relay;

use InvalidArgumentException;

/**
 * `config('payments.relay')`, converted once and validated once.
 *
 * WHY A TYPE AND NOT config() AT THE CALL SITE. Every value here is either a
 * duration that ends up inside a PostgreSQL interval or a limit that bounds a
 * loop. `config()` returns mixed, and PostgreSQL accepts a negative interval
 * without complaint — `available_at = now() + interval '-1 second'` makes a row
 * eligible in the past, forever, and nothing reports it. Converting at the
 * boundary means a mistyped `.env` fails at container resolution, before any row
 * is touched, instead of becoming a silently wrong delivery schedule.
 *
 * THE THREE NAMES ARE LITERALS IN config/payments.php, not env(). The runtime
 * contract and T8's Horizon supervision both name `payments-projection`; an
 * env-tunable name is how those two drift apart with nothing failing. They are
 * still validated here, because a config edit is as capable of emptying them as a
 * `.env` is.
 */
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
     * @param  array<string, mixed>  $config  `payments.relay`
     * @param  array<string, mixed>  $transport  `database.redis.payments`
     *
     * The transport bounds are validated HERE, beside the relay's own limits, even
     * though they live in config/database.php — which is where they must live,
     * because that is the array Laravel's PhpRedisConnector actually reads. They
     * belong to the same decision as everything else on this object: publication
     * happens inside an open PostgreSQL transaction holding a row lock, so the
     * connect and read timeouts bound database occupancy exactly as
     * statement_timeout_ms does. Validating them at any later point would mean the
     * first relay operation is what discovers them.
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
            // AT LEAST ONE SECOND, not zero. Zero is arithmetically valid and
            // operationally catastrophic: available_at = now() puts an accepted row
            // straight back into the active index, so with no consumer to complete
            // it the relay would republish it on every pass forever.
            reconciliationGraceSeconds: self::atLeast($config, 'reconciliation_grace_seconds', 1),
            backoffBaseSeconds: self::atLeast($config, 'backoff_base_seconds', 1),
            backoffMaxSeconds: self::atLeast($config, 'backoff_max_seconds', 1),
            heartbeatFreshnessSeconds: self::atLeast($config, 'heartbeat_freshness_seconds', 1),
            heartbeatTtlSeconds: self::atLeast($config, 'heartbeat_ttl_seconds', 1),
            redisTimeout: self::positiveSeconds($transport, 'timeout'),
            redisReadTimeout: self::positiveSeconds($transport, 'read_timeout'),
        );

        // The status contract distinguishes `stale` from `missing`. A TTL at or
        // below the freshness window makes `stale` unreachable — the value expires
        // before it can be reported as old — so a stopped scheduler would look
        // identical to one that has never run.
        if ($instance->heartbeatTtlSeconds <= $instance->heartbeatFreshnessSeconds) {
            throw new InvalidArgumentException(sprintf(
                'payments.relay.heartbeat_ttl_seconds (%d) must exceed heartbeat_freshness_seconds (%d), '
                .'or a stale heartbeat expires before it can be reported as stale.',
                $instance->heartbeatTtlSeconds,
                $instance->heartbeatFreshnessSeconds,
            ));
        }

        // Constructed once here so an unusable pair is refused at the same boundary
        // as everything else, rather than on the first transport rejection.
        $instance->backoff();

        return $instance;
    }

    /**
     * The backoff calculator, rather than the two integers it is built from.
     *
     * One owner for the rule: a caller handed `backoffBaseSeconds` and
     * `backoffMaxSeconds` could recombine them differently from every other caller.
     */
    public function backoff(): TransportBackoff
    {
        return new TransportBackoff(
            baseSeconds: $this->backoffBaseSeconds,
            maxSeconds: $this->backoffMaxSeconds,
        );
    }

    /**
     * @param  array<string, mixed>  $config
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
     * @param  array<string, mixed>  $config
     */
    private static function atLeast(array $config, string $key, int $minimum): int
    {
        $value = self::required($config, $key);

        // STRICTLY int. '500' is what an uncast env() produces, and accepting it
        // would hide the missing cast in config/payments.php until some other value
        // arrived as a non-numeric string and silently became zero.
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
     * A transport duration in seconds: a real, finite number strictly above zero.
     *
     * EVERY REJECTED SHAPE IS REACHABLE FROM .env, WHICH IS WHY EACH IS NAMED.
     * `PAYMENTS_RELAY_REDIS_TIMEOUT=` (empty) and `=fast` both cast to 0.0, and
     * phpredis reads 0.0 as NO TIMEOUT — an unreachable Redis would then block the
     * relay's open transaction, holding a row lock, until the PHP process died.
     * INF and NAN survive a (float) cast of '1e999' and 'nan' and behave the same
     * way or worse. A negative is nonsense phpredis does not define.
     *
     * The message names the environment variable rather than only the config key,
     * because .env is where the operator will fix it.
     *
     * @param  array<string, mixed>  $transport
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
     * @param  array<string, mixed>  $config
     */
    private static function required(array $config, string $key, string $namespace = 'payments.relay'): mixed
    {
        // array_key_exists, not isset: a null must be reported as the wrong type
        // for its key rather than as a missing key, and neither may be defaulted.
        if (! array_key_exists($key, $config)) {
            throw new InvalidArgumentException(
                "{$namespace}.{$key} is missing. Every relay value is declared in configuration; "
                .'a default substituted here would let the config file and the running relay disagree silently.'
            );
        }

        return $config[$key];
    }
}
