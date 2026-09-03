<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Verification;

use InvalidArgumentException;

/**
 * `config('payments.verification')`, converted once and validated once.
 *
 * VALIDATED AT CONTAINER RESOLUTION, WHICH IS BEFORE THE SNAPSHOT OPENS. That is
 * the whole point: an audit that discovered a zero batch size on its first loop
 * would already be holding a REPEATABLE READ transaction open, and a bound that
 * is checked after the work has started is not a bound.
 *
 * THE RANGES ARE THE SPECIFICATION'S, not defensive guesses. A batch size above
 * a thousand stops being a batch, and an example cap above two hundred stops
 * being a cap; both are the point at which "bounded" quietly becomes "bounded by
 * however much history there is".
 *
 * THE TWO TIMEOUTS ARE ORDERED, and the ordering is refused rather than
 * corrected. `statement_timeout` must be strictly below `transaction_timeout`, or
 * the per-statement bound can never fire first and every stall is reported as the
 * whole audit dying — which loses the distinction between one pathological query
 * and an audit that genuinely ran out of time.
 *
 * transaction_timeout TERMINATES THE SESSION when it fires, so it is a bound on
 * database transaction lifetime and NOT a promise about the CLI process. The
 * application work budget below is the separate, in-process bound; both exist
 * because neither one alone covers the other's failure.
 */
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
            // ZERO IS A LEGITIMATE CAP, unlike every other value here: an
            // operator who wants counts without any retained diagnostics is
            // asking for something coherent. It is the batch size that may not
            // be zero, because that is no progress rather than no detail.
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

    /** The audit's per-statement bound, as PostgreSQL's own `SET LOCAL` argument. */
    public function statementTimeoutStatement(): string
    {
        return 'set local statement_timeout = '.$this->statementTimeoutMs;
    }

    /**
     * The audit's transaction bound, as `SET LOCAL`.
     *
     * PostgreSQL 17 introduced `transaction_timeout` and it TERMINATES the
     * session rather than cancelling a statement, so the caller must abort
     * instead of reconnecting into a snapshot that no longer exists.
     */
    public function transactionTimeoutStatement(): string
    {
        return 'set local transaction_timeout = '.$this->transactionTimeoutMs;
    }

    /**
     * The in-process work budget, in nanoseconds, for comparison against hrtime().
     *
     * DERIVED FROM THE TRANSACTION BOUND rather than configured separately: two
     * independently tunable deadlines for one audit is two ways to be wrong. It
     * is checked BEFORE each next batch, so a batch already begun always finishes
     * rather than being abandoned half-evaluated.
     */
    public function workBudgetNanoseconds(): int
    {
        return $this->transactionTimeoutMs * 1_000_000;
    }

    /** @param array<array-key, mixed> $config */
    private static function between(array $config, string $key, int $minimum, int $maximum): int
    {
        // array_key_exists, not isset: a null must be reported as the wrong type
        // for its key rather than as a missing key, and neither may be defaulted.
        if (! array_key_exists($key, $config)) {
            throw new InvalidArgumentException(
                "payments.verification.{$key} is missing. Every audit bound is declared in "
                .'configuration; a default substituted here would let the config file and the '
                .'running audit disagree silently.'
            );
        }

        $value = $config[$key];

        // STRICTLY int. '100' is what an uncast env() produces, and a non-numeric
        // environment value casts to 0 — which for batch_size is an audit that
        // reads nothing and reports a clean snapshot of it.
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
