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
 * Produces a real 55P03 after persist(), on a decoy merchant row: locking the payment's merchant would block
 * the receipt's foreign-key check before the aggregate exists.
 * A session statement_timeout, well above lock_timeout, bounds the wait when SET LOCAL has no transaction to apply to.
 */
final class ContentionProbe extends Projector
{
    public int $weight = 2;

    /** Attempts that should still fail. Set to 1 to make attempt 2 commit. */
    public int $failuresRemaining = 0;

    public int $decoyMerchantId = 0;

    public string $lockTimeout = '250ms';

    /** Must stay well above $lockTimeout, so it never pre-empts the 55P03. */
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
            $this->sqlStates[] = RetryingAtomicTransaction::sqlStateOf($exception);

            throw $exception;
        } finally {
            $this->resetStatementTimeout();
        }

        $this->sqlStates[] = null;
    }

    private function resetStatementTimeout(): void
    {
        try {
            $this->connection->statement('set statement_timeout = default');
        } catch (Throwable) {
            // The transaction is usually already failed; never replace the caller's 55P03.
        }
    }
}
