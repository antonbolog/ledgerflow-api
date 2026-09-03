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

/**
 * The specification's retry contract, owned rather than delegated.
 *
 * IT DELIBERATELY DOES NOT USE Illuminate\Database\DetectsConcurrencyErrors.
 * Laravel's ConcurrencyErrorDetector matches 40001 by code and 40P01 only through
 * the English message 'deadlock detected', and does not recognise 55P03 at all —
 * T3 measured it returning false for a real PostgreSQL 18.4 lock conflict, which is
 * the case this specification cares most about.
 *
 * Each attempt runs inside transaction($work, 1) — ONE inner attempt — because the
 * budget lives in this loop and multiplying the two would give up to nine attempts
 * instead of three.
 *
 * NOT EVERY FAILURE IS CLASSIFIABLE BY SQLSTATE. A duplicate aggregate version is
 * caught inside AggregateRoot::persistWithoutApplyingToEventHandlers() and rethrown
 * as CouldNotPersistAggregate, which carries none. It falls through to the
 * non-retryable path, which is correct: retrying a version conflict cannot resolve
 * it. So does a 23505 from the global payment_id unique.
 *
 * SLICE 2 SHIPS THIS RUNNER BUT PROVES ONLY THAT IT COMMITS. Its unit tests are
 * Slice 3's and its behaviour against a real 55P03, a real rollback and a real
 * exhaustion is Slice 4's, from a suite that is not wrapped by RefreshDatabase.
 */
final readonly class RetryingAtomicTransaction implements AtomicTransaction
{
    public const int MAX_ATTEMPTS = 3;

    /**
     * The specification's allowlist, and nothing else.
     *
     * 40P01 deadlock detected, 40001 serialization failure, 55P03 lock not available
     * (including a configured lock timeout).
     *
     * @var list<string>
     */
    public const array RETRYABLE = ['40P01', '40001', '55P03'];

    public function __construct(
        private ConnectionInterface $connection,
        private ProjectionGate $gate,
    ) {}

    /**
     * The loop has no exit condition ON PURPOSE: it can only end by returning a
     * value or throwing, and `$attempt >= MAX_ATTEMPTS` is where it throws. A
     * `$attempt <= MAX_ATTEMPTS` condition would need an unreachable statement after
     * the loop to satisfy the return type — a line no test can ever cover and no
     * reader can ever check.
     */
    #[Override]
    public function run(Closure $work): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                // ONE inner attempt. The budget lives in this loop, and Laravel's own
                // retry count would multiply with it — three times three is nine.
                //
                // T11'S GATE, INSIDE $work RATHER THAN INSIDE InitiatePayment. The
                // gate is an Infrastructure concern and InitiatePayment must not name
                // it — the module boundary rule permits Application to reference only
                // its own module's Domain/Application and other modules' Application
                // contracts. Wrapping the caller's closure here is what makes the
                // check the first statement inside the transaction on every attempt,
                // re-acquired per attempt exactly as the specification requires. A
                // closed gate throws ProjectionRebuilding, which carries no SQLSTATE
                // and is therefore never retried below — see ProjectionRebuilding.
                return $this->connection->transaction(function () use ($work): mixed {
                    if (! $this->gate->inspectPaymentDetails()->isOpen()) {
                        throw ProjectionRebuilding::duringInitiation();
                    }

                    return $work();
                }, 1);
            } catch (Throwable $e) {
                // BEFORE CLASSIFYING, BEFORE RETHROWING, BEFORE RETRYING.
                $this->rollBackTransactionLaravelLeaked();

                $sqlState = self::sqlStateOf($e);

                if ($sqlState === null || ! self::isRetryable($sqlState)) {
                    throw $e;
                }

                if ($attempt >= self::MAX_ATTEMPTS) {
                    // Exhaustion is a documented outcome, not the raw database
                    // exception: the caller owes the client a 503 with Retry-After
                    // and no partial state, and the SQL vocabulary stays in the log.
                    throw IdempotencyContention::afterAttempts(self::MAX_ATTEMPTS, $sqlState, $e);
                }
            }
        }
    }

    /**
     * Close a server-side transaction Laravel abandoned but still believes is gone.
     *
     * MEASURED IN THE VENDOR SOURCE, NOT GUESSED. Connection::transaction() fires
     * `committing` BEFORE getPdo()->commit(), so a throwing TransactionCommitting
     * listener lands in handleCommitTransactionException(). That method sets
     * `$this->transactions = 0` and rethrows, and it rolls the PDO back ONLY for a
     * concurrency error with attempts remaining — which, at $attempts = 1, never
     * happens. Every other failure at that boundary leaves the REAL transaction open
     * while transactionLevel() reports 0.
     *
     * The consequences are both production ones, which is why this cleanup lives
     * here and not in a test harness: the connection is pooled and handed to the
     * next request still `idle in transaction`, holding every lock the abandoned
     * work took; and the next attempt's beginTransaction() would raise "There is
     * already an active transaction" from PDO, replacing the real failure with a
     * confusing one.
     *
     * THE SIGNATURE IS THE DISAGREEMENT, NOT THE PDO STATE ALONE, and getting that
     * wrong is destructive rather than merely useless. `inTransaction()` is equally
     * true when some OUTER transaction is legitimately open — which, in the Feature
     * suite, is RefreshDatabase's per-test wrapper. Rolling back on that condition
     * alone discards the wrapper and every row the test had already committed
     * through it: measured, as two Feature tests failing the moment this method
     * asked only the PDO.
     *
     * On every ordinary failure Laravel's own handleTransactionException() has
     * already called rollBack(), so the two witnesses agree and this does nothing.
     * They part company only in the case above: level 0, PDO still in a transaction.
     */
    private function rollBackTransactionLaravelLeaked(): void
    {
        // Framework-specific inspection, and Infrastructure is where it belongs.
        // getPdo() is on the concrete Connection, not on ConnectionInterface.
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
            // NEVER let cleanup replace the failure that caused it. A caller that
            // sees a rollback error instead of the commit-boundary exception has
            // lost the only information that explains what went wrong.
        }
    }

    public static function isRetryable(string $sqlState): bool
    {
        return in_array($sqlState, self::RETRYABLE, true);
    }

    /**
     * Pull the SQLSTATE out of the exception shapes this stack actually produces.
     *
     * DeadlockException is checked FIRST and unwrapped. It extends PDOException but
     * is constructed as (message, code, previous) and carries no errorInfo of its
     * own, so an unwrapped read returns null and misclassifies a retryable failure
     * as fatal. This is not theoretical: Connection::handleTransactionException()
     * wraps into it whenever a concurrency error happens at nesting depth > 1, which
     * is exactly the Feature suite inside RefreshDatabase.
     *
     * errorInfo is the primary source because it is the driver's own reporting
     * channel; getCode() is the fallback. Both carry the SQLSTATE as a STRING, so
     * comparing against an integer would silently never match.
     */
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
