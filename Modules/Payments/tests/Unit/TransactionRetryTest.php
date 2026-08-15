<?php

declare(strict_types=1);

use Illuminate\Database\DeadlockException;
use Modules\Payments\Application\Initiation\IdempotencyContention;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Tests\Support\RecordingConnection;
use Modules\Payments\Tests\Support\SqlStates;
use Modules\Payments\Tests\Support\StringCodeException;

/*
|--------------------------------------------------------------------------
| RetryingAtomicTransaction — the retry policy, proved as a unit
|--------------------------------------------------------------------------
|
| WHAT THIS FILE PROVES: how the runner CLASSIFIES a failure and how many times it
| acts on that classification. Every exception here is hand-built in the shape the
| PostgreSQL driver produces, and no real 55P03, 40P01 or 40001 is provoked.
|
| THE THREE ALLOWLISTED STATES DO NOT HAVE EQUAL STANDING BEHIND THEM, and this
| file must not be read as implying they do. Only 55P03 has been produced against a
| real database: T3 raised one from a real lock conflict and T4 raised another from
| a bounded lock_timeout, so its driver reporting is measured, and Slice 4 owns the
| runner against a real one. 40P01 and 40001 are allowlisted and classified HERE, by
| unit test, and have never been produced against PostgreSQL by any task — they
| remain an open §9 non-claim, unchanged since T3. Re-staging a live 55P03 in this
| file would re-prove the driver rather than this class; staging a live 40P01 or
| 40001 would prove something nobody has proved yet, and is not this file's job.
|
| WHAT IT DOES NOT PROVE, AND MUST NOT BE READ AS PROVING: anything about
| transactions. The connection is a fake, so nothing begins, commits or rolls back.
| No claim about rollback, fresh objects per attempt, the commit boundary, or
| atomicity is available from this file — that evidence is Slice 4's, from a suite
| without RefreshDatabase.
|
*/

it('reads the sqlstate from errorInfo first', function (): void {
    // The two channels DISAGREE, which is the only way precedence is observable:
    // with both saying 55P03 the test passes whichever one the runner reads.
    $exception = SqlStates::conflicting(errorInfoState: '55P03', codeState: '23505');

    expect(RetryingAtomicTransaction::sqlStateOf($exception))->toBe('55P03');
});

it('falls back to the exception code when errorInfo carries nothing', function (): void {
    // The non-vacuous control for the test above. Without it a runner that ignored
    // `code` entirely would still be green, and "errorInfo FIRST" would describe a
    // preference between one option and nothing.
    expect(RetryingAtomicTransaction::sqlStateOf(SqlStates::fromCodeOnly('55P03')))->toBe('55P03')
        ->and(RetryingAtomicTransaction::sqlStateOf(SqlStates::withoutState()))->toBeNull();
});

it('unwraps a DeadlockException before reading', function (): void {
    // NOT theoretical. Connection::handleTransactionException() wraps into
    // DeadlockException whenever a concurrency error happens at nesting depth > 1,
    // and inside RefreshDatabase our transaction IS depth 2 — so this is the normal
    // Feature-suite path for 40001 and 40P01 (V9). DeadlockException extends
    // PDOException but is constructed as (message, code, previous) and carries no
    // errorInfo of its own, so an unwrapped read returns null and misclassifies a
    // retryable failure as fatal.
    $inner = SqlStates::fromErrorInfo('40P01');

    expect(RetryingAtomicTransaction::sqlStateOf(new DeadlockException('x', 0, $inner)))->toBe('40P01')
        // A DeadlockException with nothing inside it is not classifiable, and
        // pretending otherwise would invent a retry from an empty wrapper.
        ->and(RetryingAtomicTransaction::sqlStateOf(new DeadlockException('x')))->toBeNull();
});

it('never reads a sqlstate out of a non-database exception', function (): void {
    // The STRING-CODE row is the one that matters, and the plain RuntimeException
    // alone would not have caught this: its code is the int 0, which the
    // `is_string($code)` fallback rejects anyway — so a runner with no type guard
    // at all would still return null for it. Only an exception carrying a
    // SQLSTATE-shaped string code can tell the two apart, and without the guard
    // this one is read and retried despite never having come from a database.
    //
    // Found by mutation: deleting the `! $e instanceof PDOException` guard left the
    // first version of this test green.
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
    // By VALUE, so widening the allowlist is a decision someone has to make here
    // too. The row-by-row test above cannot catch a fourth entry nobody listed.
    expect(RetryingAtomicTransaction::RETRYABLE)->toBe(['40P01', '40001', '55P03'])
        ->and(RetryingAtomicTransaction::MAX_ATTEMPTS)->toBe(3);
});

it('attempts a non-retryable failure exactly once and rethrows it unchanged', function (): void {
    // Shaped as T3 measured a real 23505: a UniqueConstraintViolationException whose
    // errorInfo[0] is '23505'.
    $failure = SqlStates::uniqueViolation();
    $connection = new RecordingConnection($failure);

    $thrown = null;

    try {
        new RetryingAtomicTransaction($connection)->run(fn (): string => 'never reached');
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    // The SAME INSTANCE, not merely the same class: the caller's error handling and
    // the log both depend on the original, and re-wrapping would lose the SQL detail
    // that says which constraint fired.
    expect($thrown)->toBe($failure)
        ->and($thrown)->not->toBeInstanceOf(IdempotencyContention::class)
        ->and($connection->calls)->toBe(1);
});

it('does not retry a failure that carries no sqlstate at all', function (): void {
    // CouldNotPersistAggregate is the real case: a duplicate aggregate version is
    // caught inside Spatie's persist() and rethrown carrying no SQLSTATE. Retrying
    // it could never resolve it.
    $failure = new RuntimeException('could not persist aggregate');
    $connection = new RecordingConnection($failure);

    $thrown = null;

    try {
        new RetryingAtomicTransaction($connection)->run(fn (): string => 'never reached');
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

    // THREE failures queued, then the queue empties and a fourth call would SUCCEED.
    // So a runner that took a fourth attempt would return 'succeeded on the fourth'
    // instead of throwing — which is what makes "at most three" provable here rather
    // than only counted.
    $connection = new RecordingConnection($first, $second, $last);

    $thrown = null;

    try {
        new RetryingAtomicTransaction($connection)->run(fn (): string => 'succeeded on the fourth');
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(IdempotencyContention::class)
        // The LAST sqlstate, kept for the log, and the original exception kept as
        // previous — without it the driver detail is gone by the time anyone reads
        // the 503.
        ->and($thrown?->getMessage())->toContain('55P03')
        ->and($thrown?->getMessage())->toContain('3 attempts')
        ->and($thrown?->getPrevious())->toBe($last)
        ->and($connection->calls)->toBe(3);
});

it('retries a retryable failure and returns the value once it succeeds', function (): void {
    // The non-vacuous control for exhaustion: without it, a runner that threw
    // IdempotencyContention on the FIRST retryable failure would pass every
    // assertion above.
    $connection = new RecordingConnection(SqlStates::fromErrorInfo('40001'));

    $result = new RetryingAtomicTransaction($connection)->run(fn (): string => 'committed');

    expect($result)->toBe('committed')
        ->and($connection->calls)->toBe(2);
});

it('gives laravel one inner attempt on every call so the budget cannot multiply', function (): void {
    // transaction($work, 1). Laravel's own retry loop would MULTIPLY this class's
    // budget: passing 3 here would allow up to nine attempts against a database
    // that is already contended, and the extra six would be invisible to every
    // count assertion above because they happen inside one call.
    $connection = new RecordingConnection(
        SqlStates::fromErrorInfo('55P03'),
        SqlStates::fromErrorInfo('55P03'),
        SqlStates::fromErrorInfo('55P03'),
    );

    try {
        new RetryingAtomicTransaction($connection)->run(fn (): string => 'unreachable');
    } catch (IdempotencyContention) {
        // The outcome is asserted by the exhaustion test; this one is about the
        // argument, on EVERY attempt and not just the first.
    }

    expect($connection->attemptArguments)->toBe([1, 1, 1]);
});
