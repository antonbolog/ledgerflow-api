<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Persistence;

use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Application\Initiation\PaymentInitiationContext;
use Modules\Payments\Application\Initiation\PaymentIntentDeadline;
use Modules\Payments\Application\Initiation\PaymentIntentDeadlineRefused;
use Override;

// Values and mechanism: ADR 0008. Every comparison reads clock_timestamp(), because now() is frozen at transaction start.
final readonly class DatabasePaymentIntentDeadline implements PaymentIntentDeadline
{
    /** Keeps a refusal and its ROLLBACK ahead of the server-side timer. */
    public const int MARGIN_MS = 250;

    /** Residual allowance for a COMMIT already executing when the timer fires. */
    public const int RESIDUAL_MS = 1_000;

    /** The BFF's 30 s lifetime plus the 2 s skew allowance. */
    public const int CAP_MS = 32_000;

    public function __construct(private ConnectionInterface $connection) {}

    // Reading and arming share one statement, so a PHP stall between them cannot move the server-side stop past the
    // expiry. Nothing is armed inside the margin: a near-zero timer could end the session before the refusal rolls back.
    // The inner set_config disarms first: a timer already running from a non-zero default ignores a new value.
    #[Override]
    public function arm(PaymentInitiationContext $context): void
    {
        /** @var object{ms: int|string} $row */
        $row = $this->connection->selectOne(
            <<<'SQL'
            select r.ms, case when r.ms > ?::int then set_config(
                'transaction_timeout', r.ms::text || left(set_config('transaction_timeout', '0', true), 0), true
            ) end as armed
            from (select floor(extract(epoch from (?::timestamptz - clock_timestamp())) * 1000)::bigint as ms) r
            SQL,
            [self::MARGIN_MS, $context->intentExpiresAt],
        );

        $remaining = (int) $row->ms;

        if ($remaining <= self::MARGIN_MS) {
            throw PaymentIntentDeadlineRefused::beforeWork($remaining + self::RESIDUAL_MS);
        }
    }

    #[Override]
    public function recheck(PaymentInitiationContext $context): void
    {
        $remaining = $this->remainingMs($context->intentExpiresAt);

        if ($remaining <= self::MARGIN_MS) {
            throw PaymentIntentDeadlineRefused::beforeCommit($remaining + self::RESIDUAL_MS);
        }
    }

    #[Override]
    public function exceedsCap(string $intentExpiresAt): bool
    {
        return $this->remainingMs($intentExpiresAt) > self::CAP_MS;
    }

    private function remainingMs(string $intentExpiresAt): int
    {
        /** @var object{ms: int|string} $row */
        $row = $this->connection->selectOne(
            'select floor(extract(epoch from (?::timestamptz - clock_timestamp())) * 1000)::bigint as ms',
            [$intentExpiresAt],
        );

        return (int) $row->ms;
    }
}
