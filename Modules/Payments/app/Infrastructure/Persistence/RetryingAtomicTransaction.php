<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Persistence;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DeadlockException;
use Modules\Payments\Application\Initiation\AtomicTransaction;
use Modules\Payments\Application\Initiation\IdempotencyContention;
use Modules\Payments\Application\Initiation\ProjectionRebuilding;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Override;
use PDOException;
use Throwable;

// Owns the SQLSTATE allowlist: Laravel's ConcurrencyErrorDetector does not recognise 55P03 (ADR 0003).
final readonly class RetryingAtomicTransaction implements AtomicTransaction
{
    public const int MAX_ATTEMPTS = 3;

    public const array RETRYABLE = ['40P01', '40001', '55P03'];

    public function __construct(
        private ConnectionInterface $connection,
        private ProjectionGate $gate,
    ) {}

    #[Override]
    public function run(Closure $work): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                // One inner attempt, because this loop owns the budget; the gate is re-checked first on every attempt.
                return $this->connection->transaction(function () use ($work): mixed {
                    if (! $this->gate->inspectPaymentDetails()->isOpen()) {
                        throw ProjectionRebuilding::duringInitiation();
                    }

                    return $work();
                }, 1);
            } catch (Throwable $e) {
                $this->rollBackTransactionLaravelLeaked();

                $sqlState = self::sqlStateOf($e);

                if ($sqlState === null || ! self::isRetryable($sqlState)) {
                    throw $e;
                }

                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw IdempotencyContention::afterAttempts(self::MAX_ATTEMPTS, $sqlState, $e);
                }
            }
        }
    }

    // A failed commit can leave the server transaction open while transactionLevel() reports 0.
    // Roll back only on that disagreement: PDO state alone would also discard an outer transaction.
    private function rollBackTransactionLaravelLeaked(): void
    {
        if (! $this->connection instanceof Connection) {
            return;
        }

        if ($this->connection->transactionLevel() !== 0) {
            return;
        }

        try {
            $pdo = $this->connection->getPdo();

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable) {
            // Cleanup must not replace the original failure.
        }
    }

    public static function isRetryable(string $sqlState): bool
    {
        return in_array($sqlState, self::RETRYABLE, true);
    }

    // Unwrap DeadlockException first: Laravel wraps nested concurrency errors in it without errorInfo.
    public static function sqlStateOf(Throwable $e): ?string
    {
        if ($e instanceof DeadlockException && ($previous = $e->getPrevious()) instanceof Throwable) {
            return self::sqlStateOf($previous);
        }

        if (! $e instanceof PDOException) {
            return null;
        }

        $errorInfo = $e->errorInfo;

        if (is_array($errorInfo) && isset($errorInfo[0]) && is_string($errorInfo[0]) && $errorInfo[0] !== '') {
            return $errorInfo[0];
        }

        $code = $e->getCode();

        return is_string($code) && $code !== '' ? $code : null;
    }
}
