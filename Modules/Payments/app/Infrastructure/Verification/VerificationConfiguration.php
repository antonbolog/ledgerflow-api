<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Verification;

use InvalidArgumentException;

final readonly class VerificationConfiguration
{
    private function __construct(
        public int $batchSize,
        public int $exampleLimit,
        public int $graceSeconds,
        public int $statementTimeoutMs,
        public int $transactionTimeoutMs,
        public int $maxPayloadBytes,
    ) {}

    /** @param array<array-key, mixed> $config `payments.verification` */
    public static function fromArray(array $config): self
    {
        $instance = new self(
            batchSize: self::between($config, 'batch_size', 1, 1_000),
            // Zero is valid: counts without retained examples.
            exampleLimit: self::between($config, 'example_limit', 0, 200),
            graceSeconds: self::between($config, 'grace_seconds', 1, 86_400),
            statementTimeoutMs: self::between($config, 'statement_timeout_ms', 1, 600_000),
            transactionTimeoutMs: self::between($config, 'transaction_timeout_ms', 1, 600_000),
            maxPayloadBytes: self::between($config, 'max_payload_bytes', 1, 16_777_216),
        );

        if ($instance->statementTimeoutMs >= $instance->transactionTimeoutMs) {
            throw new InvalidArgumentException(sprintf(
                'payments.verification.statement_timeout_ms (%d) must be strictly below '
                .'transaction_timeout_ms (%d). Otherwise no single statement can ever be cancelled '
                .'before the whole transaction is, and one pathological query is indistinguishable '
                .'from an audit that ran out of time.',
                $instance->statementTimeoutMs,
                $instance->transactionTimeoutMs,
            ));
        }

        return $instance;
    }

    public function statementTimeoutStatement(): string
    {
        return 'set local statement_timeout = '.$this->statementTimeoutMs;
    }

    public function transactionTimeoutStatement(): string
    {
        return 'set local transaction_timeout = '.$this->transactionTimeoutMs;
    }

    // Derived from the transaction bound rather than configured separately: one audit, one deadline.
    public function workBudgetNanoseconds(): int
    {
        return $this->transactionTimeoutMs * 1_000_000;
    }

    /** @param array<array-key, mixed> $config */
    private static function between(array $config, string $key, int $minimum, int $maximum): int
    {
        if (! array_key_exists($key, $config)) {
            throw new InvalidArgumentException(
                "payments.verification.{$key} is missing. Every audit bound is declared in "
                .'configuration; a default substituted here would let the config file and the '
                .'running audit disagree silently.'
            );
        }

        $value = $config[$key];

        if (! is_int($value)) {
            throw new InvalidArgumentException(sprintf(
                'payments.verification.%s must be an integer, got %s. Check the (int) cast in '
                .'config/payments.php.',
                $key,
                get_debug_type($value),
            ));
        }

        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'payments.verification.%s must be between %d and %d, got %d.',
                $key,
                $minimum,
                $maximum,
                $value,
            ));
        }

        return $value;
    }
}
