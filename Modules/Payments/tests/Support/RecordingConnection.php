<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use Closure;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Throwable;

/**
 * A ConnectionInterface whose only real methods are transaction() and the two
 * T11's projection gate touches.
 *
 * WHY A HAND-WRITTEN FAKE AND NOT A MOCK: the retry contract is about COUNTING and
 * about the second argument to transaction(). A fake records both as ordinary
 * assertable state, and the seventeen still-unused methods throw rather than
 * returning a plausible null — so a future change that makes the runner touch the
 * connection for anything else fails loudly here instead of silently passing.
 *
 * The outcome queue is what lets one test say "fail, fail, fail, then succeed":
 * each call consumes one entry, a Throwable is thrown and null runs the callback.
 * WHEN THE QUEUE IS EMPTY THE CALL SUCCEEDS. That is deliberate — it means a runner
 * that took a fourth attempt would RETURN a value instead of throwing, so
 * "at most three attempts" is proved by the exhaustion assertion itself rather than
 * only by a call count.
 *
 * transactionLevel() AND selectOne() ARE NOW REAL, NOT UNUSED. RetryingAtomicTransaction
 * wraps the caller's work in a ProjectionGate check on every attempt, and this
 * file's suite is about retry classification, not the gate — every test here needs
 * the gate to answer OPEN without touching a real PostgreSQL connection. Both
 * methods answer exactly what ProjectionGate::inspectPaymentDetails() needs to
 * read an open gate: level 1 (inside the caller's transaction), then the lock
 * acquired, then the control row saying `ready`. No test in this file configures
 * a closed gate; that behaviour belongs to the Integration suite, which runs
 * against real advisory locks.
 */
final class RecordingConnection implements ConnectionInterface
{
    /** How many selectOne() calls this connection has answered. */
    private int $selectOneCalls = 0;

    /** Every value passed as transaction()'s $attempts argument, in order. */
    public array $attemptArguments = [];

    public int $calls = 0;

    /** @var list<Throwable|null> */
    private array $outcomes;

    public function __construct(?Throwable ...$outcomes)
    {
        $this->outcomes = array_values($outcomes);
    }

    public function transaction(Closure $callback, $attempts = 1)
    {
        $this->calls++;
        $this->attemptArguments[] = $attempts;

        $outcome = array_shift($this->outcomes);

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $callback($this);
    }

    public function table($table, $as = null): never
    {
        throw $this->unused(__FUNCTION__);
    }

    public function raw($value): never
    {
        throw $this->unused(__FUNCTION__);
    }

    /**
     * The gate's two reads, in the fixed order it makes them: the advisory
     * try-lock first, the control row second. Odd calls answer the lock, even
     * calls answer the row — both saying the gate is open.
     *
     * @param  array<mixed>  $bindings
     */
    public function selectOne($query, $bindings = [], $useReadPdo = true): object
    {
        $this->selectOneCalls++;

        return $this->selectOneCalls % 2 === 1
            ? (object) ['acquired' => 1]
            : (object) ['state' => 'ready'];
    }

    /** @param  array<mixed>  $bindings */
    public function scalar($query, $bindings = [], $useReadPdo = true): never
    {
        throw $this->unused(__FUNCTION__);
    }

    /**
     * @param  array<mixed>  $bindings
     * @param  array<mixed>  $fetchUsing
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): never
    {
        throw $this->unused(__FUNCTION__);
    }

    /**
     * @param  array<mixed>  $bindings
     * @param  array<mixed>  $fetchUsing
     */
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): never
    {
        throw $this->unused(__FUNCTION__);
    }

    /** @param  array<mixed>  $bindings */
    public function insert($query, $bindings = []): never
    {
        throw $this->unused(__FUNCTION__);
    }

    /** @param  array<mixed>  $bindings */
    public function update($query, $bindings = []): never
    {
        throw $this->unused(__FUNCTION__);
    }

    /** @param  array<mixed>  $bindings */
    public function delete($query, $bindings = []): never
    {
        throw $this->unused(__FUNCTION__);
    }

    /** @param  array<mixed>  $bindings */
    public function statement($query, $bindings = []): never
    {
        throw $this->unused(__FUNCTION__);
    }

    /** @param  array<mixed>  $bindings */
    public function affectingStatement($query, $bindings = []): never
    {
        throw $this->unused(__FUNCTION__);
    }

    public function unprepared($query): never
    {
        throw $this->unused(__FUNCTION__);
    }

    /** @param  array<mixed>  $bindings */
    public function prepareBindings(array $bindings): never
    {
        throw $this->unused(__FUNCTION__);
    }

    public function beginTransaction(): never
    {
        throw $this->unused(__FUNCTION__);
    }

    public function commit(): never
    {
        throw $this->unused(__FUNCTION__);
    }

    public function rollBack(): never
    {
        throw $this->unused(__FUNCTION__);
    }

    /**
     * Always 1: ProjectionGate::inspect() requires a level of at least 1 before it
     * will check the gate at all, and this fake's transaction() never opens a real
     * one for the gate to count.
     */
    public function transactionLevel(): int
    {
        return 1;
    }

    public function pretend(Closure $callback): never
    {
        throw $this->unused(__FUNCTION__);
    }

    public function getDatabaseName(): never
    {
        throw $this->unused(__FUNCTION__);
    }

    private function unused(string $method): LogicException
    {
        return new LogicException("RetryingAtomicTransaction must not call ConnectionInterface::{$method}().");
    }
}
