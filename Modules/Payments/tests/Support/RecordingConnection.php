<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use Closure;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Throwable;

/**
 * A ConnectionInterface whose only real method is transaction().
 *
 * WHY A HAND-WRITTEN FAKE AND NOT A MOCK: the retry contract is about COUNTING and
 * about the second argument to transaction(). A fake records both as ordinary
 * assertable state, and the nineteen unused methods throw rather than returning a
 * plausible null — so a future change that makes the runner touch the connection
 * for anything else fails loudly here instead of silently passing.
 *
 * The outcome queue is what lets one test say "fail, fail, fail, then succeed":
 * each call consumes one entry, a Throwable is thrown and null runs the callback.
 * WHEN THE QUEUE IS EMPTY THE CALL SUCCEEDS. That is deliberate — it means a runner
 * that took a fourth attempt would RETURN a value instead of throwing, so
 * "at most three attempts" is proved by the exhaustion assertion itself rather than
 * only by a call count.
 */
final class RecordingConnection implements ConnectionInterface
{
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

    /** @param  array<mixed>  $bindings */
    public function selectOne($query, $bindings = [], $useReadPdo = true): never
    {
        throw $this->unused(__FUNCTION__);
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

    public function transactionLevel(): never
    {
        throw $this->unused(__FUNCTION__);
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
