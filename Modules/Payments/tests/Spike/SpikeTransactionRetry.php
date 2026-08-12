<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Spike;

use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

/**
 * Test-only transaction retry runner. T6 owns the production version.
 *
 * It exists to prove the spec's retry contract is implementable on this exact
 * stack, not to be that implementation.
 *
 * IT DELIBERATELY DOES NOT USE Illuminate\Database\DetectsConcurrencyErrors.
 * Laravel's ConcurrencyErrorDetector matches 40001 by code and 40P01 only
 * through the English message 'deadlock detected', and does not recognise 55P03
 * at all. Measured against a real PostgreSQL 18.4 lock conflict it returns
 * false, so delegating to it would silently never retry the case the Payments
 * spec cares most about.
 *
 * Each attempt runs the closure inside DB::transaction(..., 1) — ONE inner
 * attempt, because the spec puts the retry budget in the outer loop and
 * multiplying the two would give up to nine attempts instead of three. Laravel
 * rolls back and rethrows at that depth, so every attempt starts from a clean
 * transaction.
 */
final class SpikeTransactionRetry
{
    public const int MAX_ATTEMPTS = 3;

    /**
     * The spec's allowlist, and nothing else.
     *
     * 40P01 deadlock detected, 40001 serialization failure,
     * 55P03 lock not available (including a configured lock timeout).
     */
    public const array RETRYABLE = ['40P01', '40001', '55P03'];

    private int $attempts = 0;

    /** @var list<?string> SQLSTATE captured from each failed attempt, in order. */
    private array $capturedSqlStates = [];

    public function __construct(private readonly ?Closure $onRetry = null) {}

    public function run(Closure $work): mixed
    {
        $connection = DB::connection(SpikeDatabase::CONNECTION);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $this->attempts = $attempt;

            try {
                return $connection->transaction($work, 1);
            } catch (Throwable $e) {
                $sqlState = self::sqlStateOf($e);
                $this->capturedSqlStates[] = $sqlState;

                $retryable = $sqlState !== null && in_array($sqlState, self::RETRYABLE, true);

                if (! $retryable || $attempt === self::MAX_ATTEMPTS) {
                    throw $e;
                }

                if ($this->onRetry instanceof Closure) {
                    ($this->onRetry)($attempt);
                }
            }
        }

        // Unreachable: the loop either returns or throws.
        throw new SpikeInjectedFailure('retry loop fell through');
    }

    /**
     * Pull the SQLSTATE out of the exception shapes Task 1 actually observed.
     *
     * Measured on PostgreSQL 18.4 / PDO pgsql:
     *
     *   55P03  Illuminate\Database\QueryException
     *   23505  Illuminate\Database\UniqueConstraintViolationException
     *
     * Both wrap a PDOException, and both carry the SQLSTATE in errorInfo[0] AND
     * in getCode() as a STRING. getCode() is the fallback rather than the
     * primary because errorInfo is the driver's own reporting channel; the
     * string-ness matters either way, since comparing against an integer would
     * silently never match.
     *
     * DeadlockException is checked FIRST and unwrapped, because it extends
     * PDOException but is constructed as (message, code, previous) and carries
     * no errorInfo of its own. Laravel raises it when a concurrency error
     * happens inside a NESTED transaction. Reading it without unwrapping would
     * yield null and misclassify a retryable failure as fatal.
     */
    public static function sqlStateOf(Throwable $e): ?string
    {
        if ($e instanceof DeadlockException) {
            $previous = $e->getPrevious();

            if ($previous !== null) {
                return self::sqlStateOf($previous);
            }
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

    public function attempts(): int
    {
        return $this->attempts;
    }

    /** @return list<?string> */
    public function capturedSqlStates(): array
    {
        return $this->capturedSqlStates;
    }
}
