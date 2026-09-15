<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use Closure;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Throwable;

/**
 * Each transaction() consumes one queued outcome; an empty queue succeeds, so a fourth attempt would return instead of throwing.
 * selectOne() and transactionLevel() always answer an open projection gate.
 */
final class RecordingConnection implements ConnectionInterface
{
    private int $selectOneCalls = 0;

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
     * Odd calls answer the gate's advisory lock read, even calls its control row read.
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

    /** ProjectionGate checks the gate only inside a transaction. */
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
