<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

use InvalidArgumentException;

// retry_after is read from the queue connection, where RedisQueue reads it; a copy could disagree.
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

    public function statementTimeoutStatement(): string
    {
        return 'set local statement_timeout = '.$this->statementTimeoutMs;
    }

    // Strict inequalities: two timers that fire together race.
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

        // is_int: these are configuration literals, so a string means an uncast env() crept in.
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
        if (! array_key_exists($key, $config)) {
            throw new InvalidArgumentException(
                "{$namespace}.{$key} is missing. Every projection value is declared in configuration; a "
                .'default substituted here would let the config file and the running consumer disagree.'
            );
        }

        return $config[$key];
    }
}
