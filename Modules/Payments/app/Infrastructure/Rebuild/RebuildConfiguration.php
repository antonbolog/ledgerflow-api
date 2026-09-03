<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Rebuild;

use InvalidArgumentException;

/**
 * `config('payments.rebuild')`, converted once and validated once.
 *
 * VALIDATED AT CONTAINER RESOLUTION, before the exclusive lock is even
 * requested - the same reason every other Payments configuration object is:
 * a zero batch size discovered on the first replay loop would already have
 * cleared `payment_details`.
 */
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

    /**
     * The session-level bound on phase 1's exclusive acquisition.
     *
     * SESSION-LEVEL, NOT `SET LOCAL`: phase 1 runs before any transaction is
     * open, so there is nothing for a local setting to be scoped to. The caller
     * resets it to `default` immediately after acquiring the lock, so it never
     * leaks into the short transactions that follow.
     */
    public function lockTimeoutStatement(): string
    {
        return "set lock_timeout = '".$this->lockTimeoutMs.'ms\'';
    }

    /** Every later transaction's own bound, as `SET LOCAL`. */
    public function statementTimeoutStatement(): string
    {
        return 'set local statement_timeout = '.$this->statementTimeoutMs;
    }

    /** @param array<array-key, mixed> $config */
    private static function between(array $config, string $key, int $minimum, int $maximum): int
    {
        // array_key_exists, not isset: a null must be reported as the wrong type
        // for its key rather than as a missing key, and neither may be defaulted.
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
