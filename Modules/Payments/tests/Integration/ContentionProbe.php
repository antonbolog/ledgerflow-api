<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Integration;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Throwable;

/**
 * Produces a REAL PostgreSQL 55P03 from inside the use case's own transaction.
 *
 * E5. THE CONTENTION IS INJECTED AFTER persist(), NOT AT THE RECEIPT INSERT, and
 * the difference decides whether the freshness contract is proved or quietly
 * abandoned. Holding a lock on the merchant row the RECEIPT's foreign key checks
 * also yields a real 55P03 — but it fires before the aggregate is ever built, so
 * attempt 1 never reaches the recorder, `objectIds` holds ONE entry, and the
 * natural repair is to weaken the assertion until it passes. So this probe runs at
 * $weight = 2, behind the writer (0) and the recorder (1), exactly where T3 placed
 * its own contention probe.
 *
 * IT LOCKS A DECOY ROW, NEVER THE PAYMENT'S MERCHANT. An INSERT's foreign-key check
 * takes FOR KEY SHARE on the referenced row, which conflicts with the FOR UPDATE
 * the lock connection holds — so locking the real merchant would block the receipt
 * insert instead of this statement, and would reintroduce the very defect above.
 *
 * NO SLEEPS. `SET LOCAL lock_timeout` makes PostgreSQL itself decide, and it is
 * LOCAL so it dies with the transaction rather than leaking into the next attempt.
 *
 * AND A SECOND, SESSION-LEVEL BOUND UNDERNEATH IT, BECAUSE `LOCAL` HAS A HOLE.
 * `SET LOCAL` is scoped to a transaction, so it does NOTHING when there is no
 * transaction to be local to — and "there is no transaction" is exactly the state
 * the most important mutation of this suite creates. Removing the transaction from
 * RetryingAtomicTransaction used to make the run HANG here, waiting on a row lock
 * with no bound at all, which is the worst way for a mutation to be caught: no
 * failure, no output, a wedged database, and a human left to guess. The
 * session-level `statement_timeout` is the floor that turns that hang into a
 * bounded, reported failure.
 *
 * It is deliberately much LONGER than the lock timeout, so it never pre-empts the
 * 55P03 this probe exists to produce. On the normal path lock_timeout fires first
 * and statement_timeout is never reached; it only ever speaks when the LOCAL scope
 * has silently evaporated.
 *
 * PostgreSQL rolls a plain `SET` back with its transaction, so on the normal path
 * the bound also disappears on its own when the attempt rolls back. The explicit
 * reset is for the path where there was no transaction to roll it back.
 */
final class ContentionProbe extends Projector
{
    public int $weight = 2;

    /** Attempts that should still fail. Set to 1 to make attempt 2 commit. */
    public int $failuresRemaining = 0;

    public int $decoyMerchantId = 0;

    public string $lockTimeout = '250ms';

    /** The safety floor. Must stay well above $lockTimeout — see the class docblock. */
    public string $statementTimeout = '5s';

    /** @var list<?string> */
    public array $sqlStates = [];

    public function __construct(private readonly ConnectionInterface $connection) {}

    public function onPaymentInitiated(PaymentInitiated $event): void
    {
        if ($this->failuresRemaining <= 0) {
            return;
        }

        $this->failuresRemaining--;

        $this->connection->statement("set statement_timeout = '{$this->statementTimeout}'");
        $this->connection->statement("set local lock_timeout = '{$this->lockTimeout}'");

        try {
            $this->connection->select(
                'select id from merchants where id = ? for update',
                [$this->decoyMerchantId],
            );
        } catch (QueryException $exception) {
            // Recorded from the SAME classifier production uses, so a change to
            // sqlStateOf() shows up here rather than being papered over by a
            // second, test-only reading of the exception.
            $this->sqlStates[] = RetryingAtomicTransaction::sqlStateOf($exception);

            throw $exception;
        } finally {
            $this->resetStatementTimeout();
        }

        // Reaching here means the lock was NOT held and no 55P03 was produced. The
        // test's sqlStates assertion is what catches it; recording null keeps the
        // count honest rather than silently short.
        $this->sqlStates[] = null;
    }

    /**
     * Give the bound back, without ever speaking over the failure that triggered it.
     *
     * On the normal path this runs while the transaction is already in a failed
     * state (`25P02`), so the reset itself fails — and that is fine twice over: the
     * rollback restores the bound anyway, and swallowing the error here is what
     * keeps the caller's 55P03 the exception the test actually sees.
     */
    private function resetStatementTimeout(): void
    {
        try {
            $this->connection->statement('set statement_timeout = default');
        } catch (Throwable) {
            // Deliberately silent. See above.
        }
    }
}
