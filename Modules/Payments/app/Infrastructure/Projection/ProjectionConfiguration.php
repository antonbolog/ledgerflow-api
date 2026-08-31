<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

use InvalidArgumentException;

/**
 * `config('payments.projection')` plus the queue's reservation, validated once.
 *
 * IT EXISTS FOR THE ORDERING, not for the conversions. The three timeouts are one
 * correctness chain:
 *
 *     statement_timeout (2s) < worker timeout (15s) < retry_after (90s)
 *
 * and each inequality prevents a specific failure:
 *
 * - statement_timeout BELOW the worker timeout means a stalled projection is
 *   cancelled by PostgreSQL, on a connection the worker still owns, and arrives as
 *   a classifiable 57014. Above it, the worker's alarm fires first and kills the
 *   process mid-transaction — the outcome the failure classification calls
 *   "worker termination", which is deliberately non-countable, so a genuinely slow
 *   deterministic failure would retry forever without ever exhausting.
 *
 * - the worker timeout BELOW retry_after means the worker gives up long before
 *   Redis's reservation lapses. Above it, Redis hands the same message to a second
 *   worker while the first still holds the envelope's row lock: the second blocks
 *   on that lock until ITS timeout, and "one Horizon execution per published job"
 *   stops being true.
 *
 * VALIDATED AT CONTAINER RESOLUTION, which is before any job is consumed, for the
 * same reason RelayConfiguration is: a misordered chain is invisible at run time
 * until the exact conditions that need it occur.
 *
 * RETRY_AFTER IS READ FROM config/queue.php, where it must live, because that is
 * the array Laravel's RedisQueue actually reads. Copying it into
 * `payments.projection` would create a second value that could disagree with the
 * one the queue uses — which is the whole failure this class exists to prevent.
 */
final readonly class ProjectionConfiguration
{
    private function __construct(
        public string $queueConnection,
        public string $queue,
        public int $statementTimeoutMs,
        public int $workerTimeoutSeconds,
        public int $tries,
        public int $processes,
        public int $retryAfterSeconds,
    ) {}

    /**
     * @param  array<array-key, mixed>  $config  `payments.projection`
     * @param  array<array-key, mixed>  $queue  `queue.connections.payments-projection`
     */
    public static function fromArray(array $config, array $queue): self
    {
        $instance = new self(
            queueConnection: self::name($config, 'queue_connection'),
            queue: self::name($config, 'queue'),
            statementTimeoutMs: self::positiveInt($config, 'statement_timeout_ms'),
            workerTimeoutSeconds: self::positiveInt($config, 'worker_timeout_seconds'),
            tries: self::positiveInt($config, 'tries'),
            processes: self::positiveInt($config, 'processes'),
            retryAfterSeconds: self::positiveInt($queue, 'retry_after', 'queue.connections.payments-projection'),
        );

        $instance->assertOrdering();
        $instance->assertSingleAttempt();

        return $instance;
    }

    /** The projection statement timeout as PostgreSQL's own `SET LOCAL` argument. */
    public function statementTimeoutStatement(): string
    {
        return 'set local statement_timeout = '.$this->statementTimeoutMs;
    }

    /**
     * STRICT inequalities, in milliseconds so the two units cannot be compared by
     * accident.
     *
     * Equality is refused as firmly as inversion: two timers that fire at the same
     * instant race, and which one wins is decided by scheduling rather than by
     * this configuration.
     */
    private function assertOrdering(): void
    {
        $workerMs = $this->workerTimeoutSeconds * 1_000;
        $retryAfterMs = $this->retryAfterSeconds * 1_000;

        if ($this->statementTimeoutMs >= $workerMs) {
            throw new InvalidArgumentException(sprintf(
                'payments.projection.statement_timeout_ms (%d) must be strictly below the worker timeout '
                .'(%d ms). Otherwise the worker alarm fires before PostgreSQL cancels the statement, the '
                .'process is killed mid-transaction, and the failure is classified as non-countable worker '
                .'termination — so a slow deterministic failure would retry forever without exhausting.',
                $this->statementTimeoutMs,
                $workerMs,
            ));
        }

        if ($workerMs >= $retryAfterMs) {
            throw new InvalidArgumentException(sprintf(
                'The worker timeout (%d ms) must be strictly below queue.connections.%s.retry_after (%d ms). '
                .'Otherwise Redis releases the reservation while the first worker still holds the envelope '
                .'row lock, a second worker takes the same message, and one Horizon execution per published '
                .'job stops being true.',
                $workerMs,
                $this->queueConnection,
                $retryAfterMs,
            ));
        }
    }

    /**
     * Horizon owns no retry budget.
     *
     * Redelivery belongs to the outbox relay, durable in `available_at`, and the
     * poison budget belongs to the failure recorder. A `tries` above one would add
     * a third retry owner whose attempts appear in neither place, so the fifth
     * confirmed failure would no longer be the fifth DELIVERY.
     */
    private function assertSingleAttempt(): void
    {
        if ($this->tries !== 1) {
            throw new InvalidArgumentException(sprintf(
                'payments.projection.tries must be exactly 1, got %d. Horizon does not own a retry budget: '
                .'redelivery is the outbox relay\'s and the poison budget is the failure recorder\'s, and '
                .'neither can see an attempt Horizon made on its own.',
                $this->tries,
            ));
        }
    }

    /** @param array<array-key, mixed> $config */
    private static function name(array $config, string $key): string
    {
        $value = self::required($config, $key);

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf(
                'payments.projection.%s must be a non-empty string, got %s. The relay publishes to this '
                .'name and Horizon supervises it; an empty one consumes nothing and reports nothing.',
                $key,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /** @param array<array-key, mixed> $config */
    private static function positiveInt(array $config, string $key, string $namespace = 'payments.projection'): int
    {
        $value = self::required($config, $key, $namespace);

        // is_int, not is_numeric: every one of these is a literal in configuration,
        // so a string here means an env() crept in — which is exactly how a link of
        // the ordering chain becomes tunable without anyone deciding that it should
        // be. retry_after is the one exception and is cast in config/queue.php.
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException(sprintf(
                '%s.%s must be an integer greater than zero, got %s. A zero or missing timeout is not a '
                .'small bound, it is no bound at all.',
                $namespace,
                $key,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /** @param array<array-key, mixed> $config */
    private static function required(array $config, string $key, string $namespace = 'payments.projection'): mixed
    {
        // array_key_exists, not isset: a null must be reported as the wrong type for
        // its key rather than as a missing key, and neither may be defaulted.
        if (! array_key_exists($key, $config)) {
            throw new InvalidArgumentException(
                "{$namespace}.{$key} is missing. Every projection value is declared in configuration; a "
                .'default substituted here would let the config file and the running consumer disagree.'
            );
        }

        return $config[$key];
    }
}
