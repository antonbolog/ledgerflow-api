<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Rebuild;

use InvalidArgumentException;

final readonly class RebuildConfiguration
{
    private function __construct(
        public int $batchSize,
        public int $lockTimeoutMs,
        public int $statementTimeoutMs,
    ) {}

    /** @param array<array-key, mixed> $config `payments.rebuild` */
    public static function fromArray(array $config): self
    {
        return new self(
            batchSize: self::between($config, 'batch_size', 1, 1_000),
            lockTimeoutMs: self::between($config, 'lock_timeout_ms', 1, 600_000),
            statementTimeoutMs: self::between($config, 'statement_timeout_ms', 1, 600_000),
        );
    }

    // Session-level, not SET LOCAL: phase 1 runs before any transaction exists; the caller resets it.
    public function lockTimeoutStatement(): string
    {
        return "set lock_timeout = '".$this->lockTimeoutMs.'ms\'';
    }

    public function statementTimeoutStatement(): string
    {
        return 'set local statement_timeout = '.$this->statementTimeoutMs;
    }

    /** @param array<array-key, mixed> $config */
    private static function between(array $config, string $key, int $minimum, int $maximum): int
    {
        if (! array_key_exists($key, $config)) {
            throw new InvalidArgumentException(
                "payments.rebuild.{$key} is missing. Every rebuild bound is declared in "
                .'configuration; a default substituted here would let the config file and the '
                .'running rebuild disagree silently.'
            );
        }

        $value = $config[$key];

        if (! is_int($value)) {
            throw new InvalidArgumentException(sprintf(
                'payments.rebuild.%s must be an integer, got %s. Check the (int) cast in '
                .'config/payments.php.',
                $key,
                get_debug_type($value),
            ));
        }

        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'payments.rebuild.%s must be between %d and %d, got %d.',
                $key,
                $minimum,
                $maximum,
                $value,
            ));
        }

        return $value;
    }
}
