<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DeadlockException;
use Modules\Payments\Application\Initiation\IdempotencyContention;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Modules\Payments\Tests\Support\RecordingConnection;
use Modules\Payments\Tests\Support\SqlStates;
use Modules\Payments\Tests\Support\StringCodeException;

function retryingAtomicTransaction(ConnectionInterface $connection): RetryingAtomicTransaction
{
    return new RetryingAtomicTransaction($connection, new ProjectionGate($connection));
}

it('reads the sqlstate from errorInfo first', function (): void {
    $exception = SqlStates::conflicting(errorInfoState: '55P03', codeState: '23505');

    expect(RetryingAtomicTransaction::sqlStateOf($exception))->toBe('55P03');
});

it('falls back to the exception code when errorInfo carries nothing', function (): void {
    expect(RetryingAtomicTransaction::sqlStateOf(SqlStates::fromCodeOnly('55P03')))->toBe('55P03')
        ->and(RetryingAtomicTransaction::sqlStateOf(SqlStates::withoutState()))->toBeNull();
});

it('unwraps a DeadlockException before reading', function (): void {
    // Laravel wraps concurrency errors at nesting depth > 1 in a DeadlockException that carries no errorInfo.
    $inner = SqlStates::fromErrorInfo('40P01');

    expect(RetryingAtomicTransaction::sqlStateOf(new DeadlockException('x', 0, $inner)))->toBe('40P01')
        ->and(RetryingAtomicTransaction::sqlStateOf(new DeadlockException('x')))->toBeNull();
});

it('never reads a sqlstate out of a non-database exception', function (): void {
    // Only a string code exercises the PDOException guard: the is_string() fallback already rejects int codes.
    expect(RetryingAtomicTransaction::sqlStateOf(new RuntimeException('55P03')))->toBeNull()
        ->and(RetryingAtomicTransaction::sqlStateOf(new StringCodeException('not a database failure', '55P03')))->toBeNull();
});

it('retries only the three allowlisted sqlstates', function (string $sqlState, bool $retryable): void {
    expect(RetryingAtomicTransaction::isRetryable($sqlState))->toBe($retryable);
})->with([
    'deadlock detected' => ['40P01', true],
    'serialization failure' => ['40001', true],
    'lock not available' => ['55P03', true],
    'unique violation' => ['23505', false],
    'foreign key violation' => ['23503', false],
    'in failed transaction' => ['25P02', false],
    'empty' => ['', false],
]);

it('holds exactly the three allowlisted sqlstates and nothing else', function (): void {
    expect(RetryingAtomicTransaction::RETRYABLE)->toBe(['40P01', '40001', '55P03'])
        ->and(RetryingAtomicTransaction::MAX_ATTEMPTS)->toBe(3);
});

it('attempts a non-retryable failure exactly once and rethrows it unchanged', function (): void {
    $failure = SqlStates::uniqueViolation();
    $connection = new RecordingConnection($failure);

    $thrown = null;

    try {
        retryingAtomicTransaction($connection)->run(fn (): string => 'never reached');
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBe($failure)
        ->and($thrown)->not->toBeInstanceOf(IdempotencyContention::class)
        ->and($connection->calls)->toBe(1);
});

it('does not retry a failure that carries no sqlstate at all', function (): void {
    $failure = new RuntimeException('could not persist aggregate');
    $connection = new RecordingConnection($failure);

    $thrown = null;

    try {
        retryingAtomicTransaction($connection)->run(fn (): string => 'never reached');
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBe($failure)
        ->and($connection->calls)->toBe(1);
});

it('stops a retryable failure at three attempts and reports contention', function (): void {
    $first = SqlStates::fromErrorInfo('55P03');
    $second = SqlStates::fromErrorInfo('55P03');
    $last = SqlStates::fromErrorInfo('55P03');

    // A fourth attempt would succeed, so exhaustion is proved by the exception rather than only by the count.
    $connection = new RecordingConnection($first, $second, $last);

    $thrown = null;

    try {
        retryingAtomicTransaction($connection)->run(fn (): string => 'succeeded on the fourth');
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(IdempotencyContention::class)
        ->and($thrown?->getMessage())->toContain('55P03')
        ->and($thrown?->getMessage())->toContain('3 attempts')
        ->and($thrown?->getPrevious())->toBe($last)
        ->and($connection->calls)->toBe(3);
});

it('retries a retryable failure and returns the value once it succeeds', function (): void {
    $connection = new RecordingConnection(SqlStates::fromErrorInfo('40001'));

    $result = retryingAtomicTransaction($connection)->run(fn (): string => 'committed');

    expect($result)->toBe('committed')
        ->and($connection->calls)->toBe(2);
});

it('gives laravel one inner attempt on every call so the budget cannot multiply', function (): void {
    $connection = new RecordingConnection(
        SqlStates::fromErrorInfo('55P03'),
        SqlStates::fromErrorInfo('55P03'),
        SqlStates::fromErrorInfo('55P03'),
    );

    try {
        retryingAtomicTransaction($connection)->run(fn (): string => 'unreachable');
    } catch (IdempotencyContention) {
    }

    expect($connection->attemptArguments)->toBe([1, 1, 1]);
});
