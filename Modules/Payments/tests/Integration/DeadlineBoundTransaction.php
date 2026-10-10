<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Integration;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Application\Initiation\AtomicTransaction;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Override;
use RuntimeException;
use Throwable;

/**
 * Applies the candidate only after the runner's gate check, so the gate stays each attempt's first statement.
 * Remaining time comes from clock_timestamp(), because now() is frozen at transaction start.
 */
final class DeadlineBoundTransaction implements AtomicTransaction
{
    public const string ELAPSED_BEFORE_WORK = 'deadline elapsed before work';

    public int $attempts = 0;

    /** @var list<int> */
    public array $remainingMs = [];

    /** @var list<string> the candidate's value at each attempt start, before it is applied */
    public array $settingAtAttemptStart = [];

    /** @var list<?string> */
    public array $sqlStates = [];

    /** @var list<class-string<Throwable>> */
    public array $exceptionClasses = [];

    /** Runs after the work closure returns, still inside the attempt's transaction. */
    public ?Closure $afterWork = null;

    public function __construct(
        private readonly ConnectionInterface $connection,
        public readonly string $candidate,
        public readonly string $deadline,
        private readonly ?int $fixedBoundMs = null,
    ) {}

    #[Override]
    public function run(Closure $work): mixed
    {
        $runner = new RetryingAtomicTransaction($this->connection, new ProjectionGate($this->connection));

        return $runner->run(function () use ($work): mixed {
            $this->attempts++;
            $this->settingAtAttemptStart[] = $this->currentSetting();

            $remaining = $this->remainingMsNow();
            $this->remainingMs[] = $remaining;

            // A fixed bound models a per-attempt timeout that ignores the shared deadline.
            if ($this->fixedBoundMs === null && $remaining <= 0) {
                throw new RuntimeException(self::ELAPSED_BEFORE_WORK);
            }

            $this->connection->select('select set_config(?, ?, true)', [
                $this->candidate,
                (string) ($this->fixedBoundMs ?? $remaining),
            ]);

            try {
                $result = $work();
            } catch (Throwable $exception) {
                $this->sqlStates[] = RetryingAtomicTransaction::sqlStateOf($exception);
                $this->exceptionClasses[] = $exception::class;

                throw $exception;
            }

            if ($this->afterWork !== null) {
                ($this->afterWork)();
            }

            return $result;
        });
    }

    public function remainingMsNow(): int
    {
        /** @var object{ms: int|string} $row */
        $row = $this->connection->selectOne(
            'select floor(extract(epoch from (?::timestamptz - clock_timestamp())) * 1000)::bigint as ms',
            [$this->deadline],
        );

        return (int) $row->ms;
    }

    private function currentSetting(): string
    {
        /** @var object{value: string} $row */
        $row = $this->connection->selectOne('select current_setting(?) as value', [$this->candidate]);

        return $row->value;
    }
}
