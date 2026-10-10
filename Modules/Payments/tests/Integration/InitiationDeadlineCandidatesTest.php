<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Application\Initiation\AtomicTransaction;
use Modules\Payments\Application\Initiation\IdempotencyContention;
use Modules\Payments\Application\Initiation\InitiatePayment;
use Modules\Payments\Application\Initiation\PaymentInitiationContext;
use Modules\Payments\Application\Initiation\PaymentIntentDeadline;
use Modules\Payments\Application\Initiation\PaymentIntentSessionTerminated;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Modules\Payments\Tests\Integration\DeadlineBoundTransaction;
use Modules\Payments\Tests\Integration\Initiations;
use Modules\Payments\Tests\Integration\IntegrationDatabase;

beforeEach(function (): void {
    IntegrationDatabase::ensureSchema();
    IntegrationDatabase::register();
    IntegrationDatabase::refuseIfWrapped();
    IntegrationDatabase::truncate();
});

afterEach(function (): void {
    IntegrationDatabase::rollbackOpenTransactions();
    IntegrationDatabase::releaseLocks();
    IntegrationDatabase::truncate();
    IntegrationDatabase::disconnectAll();
});

dataset('deadline candidates', ['statement_timeout', 'lock_timeout', 'transaction_timeout']);

function deadlineAt(int $milliseconds): string
{
    $row = DB::connection(IntegrationDatabase::OBSERVER)->selectOne(
        'select (clock_timestamp() + make_interval(secs => ?::float8 / 1000))::text as deadline',
        [$milliseconds],
    );

    return (string) $row->deadline;
}

function deadlineHarness(string $candidate, int $milliseconds, ?int $fixedBoundMs = null): DeadlineBoundTransaction
{
    $harness = new DeadlineBoundTransaction(DB::connection(), $candidate, deadlineAt($milliseconds), $fixedBoundMs);

    app()->instance(AtomicTransaction::class, $harness);

    // Each candidate is measured alone: the production arming disarms any running timer, so it would replace this one.
    app()->instance(PaymentIntentDeadline::class, new class implements PaymentIntentDeadline
    {
        public function arm(PaymentInitiationContext $context): void {}

        public function recheck(PaymentInitiationContext $context): void {}

        public function exceedsCap(string $intentExpiresAt): bool
        {
            return false;
        }
    });

    return $harness;
}

/** @return array{?Throwable, int} */
function deadlineInitiate(PaymentInitiationContext $context): array
{
    $thrown = null;
    $startedAt = hrtime(true);

    try {
        resolve(InitiatePayment::class)($context);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    return [$thrown, (int) ((hrtime(true) - $startedAt) / 1_000_000)];
}

/** @return array<string, string> */
function deadlineSettings(): array
{
    return (array) DB::selectOne(
        "select current_setting('statement_timeout') as statement_timeout, "
        ."current_setting('lock_timeout') as lock_timeout, "
        ."current_setting('transaction_timeout') as transaction_timeout",
    );
}

function deadlineGateLocksHeldBy(int $pid): int
{
    $row = DB::connection(IntegrationDatabase::OBSERVER)->selectOne(
        "select count(*) as held from pg_locks where locktype = 'advisory' and classid = ? and pid = ?",
        [ProjectionGate::CLASS_ID, $pid],
    );

    return (int) $row->held;
}

function deadlineBackendAlive(int $pid): bool
{
    $row = DB::connection(IntegrationDatabase::OBSERVER)
        ->selectOne('select count(*) as alive from pg_stat_activity where pid = ?', [$pid]);

    return (int) $row->alive === 1;
}

/** @return array{int, int, int} */
function deadlineObservedRows(): array
{
    return [
        IntegrationDatabase::observedCount('payment_idempotency_receipts'),
        IntegrationDatabase::observedCount('stored_events'),
        IntegrationDatabase::observedCount('payment_outbox'),
    ];
}

// Blocks the receipt's foreign-key check on the payment's own merchant row.
function deadlineLockMerchant(int $merchantId): void
{
    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->select('select id from merchants where id = ? for update', [$merchantId]);
}

/** Polls observable state with a hard bound; a fixed sleep could not prove the condition held. */
function deadlineAwait(Closure $condition, string $failure): void
{
    $giveUpAt = hrtime(true) + 5_000_000_000;

    while (! $condition()) {
        if (hrtime(true) > $giveUpAt) {
            throw new RuntimeException($failure);
        }

        Sleep::usleep(5_000);
    }
}

function deadlineAwaitClockPast(string $deadline): void
{
    deadlineAwait(
        fn (): bool => (int) DB::connection(IntegrationDatabase::OBSERVER)
            ->selectOne('select (clock_timestamp() > ?::timestamptz)::int as past', [$deadline])->past === 1,
        'the PostgreSQL clock never passed the deadline',
    );
}

/** @return ArrayObject<int, string> begin markers and default-connection SQL, in order */
function deadlineStatementLog(): ArrayObject
{
    $log = new ArrayObject;
    $default = DB::getDefaultConnection();

    Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use ($log, $default): void {
        if ($event->connectionName === $default) {
            $log[] = 'begin';
        }
    });

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($log, $default): void {
        if ($query->connectionName === $default) {
            $log[] = $query->sql;
        }
    });

    return $log;
}

/** @param ArrayObject<int, string> $log */
function deadlineExpectGateFirstOnEveryAttempt(ArrayObject $log, int $attempts): void
{
    $entries = $log->getArrayCopy();
    $begins = array_keys($entries, 'begin', true);

    expect($begins)->toHaveCount($attempts);

    foreach ($begins as $begin) {
        expect($entries[$begin + 1] ?? '')->toContain('pg_try_advisory_xact_lock_shared')
            ->and($entries[$begin + 2] ?? '')->toContain('payment_projection_controls')
            ->and($entries[$begin + 3] ?? '')->toContain('current_setting')
            ->and($entries[$begin + 4] ?? '')->toContain('clock_timestamp');
    }
}

function deadlineEvidence(string $label, array $data): void
{
    if (getenv('LEDGERFLOW_DEADLINE_EVIDENCE') === '1') {
        fwrite(STDERR, $label.' '.json_encode($data, JSON_UNESCAPED_SLASHES).PHP_EOL);
    }
}

// Cuts Laravel's suffix, which carries the SQL and its bindings.
function deadlineServerMessage(?Throwable $exception): ?string
{
    return $exception === null ? null : strstr($exception->getMessage().' (Connection:', ' (Connection:', true);
}

/** @param list<float> $values */
function deadlineSummary(array $values): array
{
    sort($values);
    $count = count($values);

    return [
        'n' => $count,
        'min' => round($values[0], 3),
        'p50' => round($values[intdiv($count, 2)], 3),
        'p95' => round($values[(int) floor(($count - 1) * 0.95)], 3),
        'max' => round($values[$count - 1], 3),
    ];
}

it('applies :dataset after the gate and leaves no setting behind after commit', function (string $candidate): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    $before = deadlineSettings();
    $pid = IntegrationDatabase::backendPid();
    $log = deadlineStatementLog();
    $harness = deadlineHarness($candidate, 10_000);

    $inside = null;
    $gateLocksInside = null;

    Event::listen(TransactionCommitting::class, function () use (&$inside, &$gateLocksInside, $candidate, $pid): void {
        $inside = (string) DB::selectOne('select current_setting(?) as value', [$candidate])->value;
        $gateLocksInside = deadlineGateLocksHeldBy($pid);
    });

    resolve(InitiatePayment::class)($context);

    deadlineExpectGateFirstOnEveryAttempt($log, 1);

    expect($harness->attempts)->toBe(1)
        ->and($harness->remainingMs[0])->toBeGreaterThan(0)->toBeLessThanOrEqual(10_000)
        ->and($harness->settingAtAttemptStart)->toBe([$before[$candidate]])
        ->and($inside)->not->toBeNull()->not->toBe($before[$candidate])
        ->and($gateLocksInside)->toBe(1);

    expect(deadlineSettings())->toBe($before)
        ->and(deadlineGateLocksHeldBy($pid))->toBe(0)
        ->and(IntegrationDatabase::backendPid())->toBe($pid)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(deadlineObservedRows())->toBe([1, 1, 1]);
})->with('deadline candidates');

it('cancels a blocked write with a non-retryable 57014 under statement_timeout and keeps the session', function (): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    // Test-owned floor: if the candidate stopped applying, the wait would end as 55P03 after 5s rather than hang.
    DB::statement("set lock_timeout = '5s'");

    $before = deadlineSettings();
    $pid = IntegrationDatabase::backendPid();
    deadlineLockMerchant($merchant->getKey());
    $harness = deadlineHarness('statement_timeout', 400);

    [$thrown, $elapsedMs] = deadlineInitiate($context);

    $pdoInTransaction = DB::connection()->getPdo()->inTransaction();
    $sqlState = RetryingAtomicTransaction::sqlStateOf($thrown ?? new RuntimeException);

    deadlineEvidence('statement_timeout.blocked', [
        'exception' => $thrown === null ? null : $thrown::class,
        'message' => deadlineServerMessage($thrown),
        'sqlstate' => $sqlState,
        'attempts' => $harness->attempts,
        'remaining_ms' => $harness->remainingMs,
        'elapsed_ms' => $elapsedMs,
    ]);

    expect($thrown)->toBeInstanceOf(QueryException::class)
        ->and($sqlState)->toBe('57014')
        ->and(RetryingAtomicTransaction::isRetryable('57014'))->toBeFalse()
        ->and($harness->attempts)->toBe(1)
        ->and($harness->sqlStates)->toBe(['57014'])
        ->and($elapsedMs)->toBeGreaterThanOrEqual($harness->remainingMs[0])->toBeLessThan(2_000)
        ->and($pdoInTransaction)->toBeFalse()
        ->and(DB::transactionLevel())->toBe(0);

    expect(IntegrationDatabase::backendPid())->toBe($pid)
        ->and(deadlineSettings())->toBe($before)
        ->and(deadlineGateLocksHeldBy($pid))->toBe(0)
        ->and(deadlineObservedRows())->toBe([0, 0, 0]);
});

it('retries a deadline-sized 55P03 under lock_timeout until the shared deadline refuses the next attempt', function (): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    // Test-owned floor: if the candidate stopped applying, the wait would end as 57014 after 5s rather than hang.
    DB::statement("set statement_timeout = '5s'");

    $before = deadlineSettings();
    $pid = IntegrationDatabase::backendPid();
    $log = deadlineStatementLog();
    deadlineLockMerchant($merchant->getKey());
    $harness = deadlineHarness('lock_timeout', 400);

    [$thrown, $elapsedMs] = deadlineInitiate($context);

    $pdoInTransaction = DB::connection()->getPdo()->inTransaction();

    deadlineEvidence('lock_timeout.blocked.recomputed', [
        'exception' => $thrown === null ? null : $thrown::class,
        'attempt_sqlstates' => $harness->sqlStates,
        'attempts' => $harness->attempts,
        'remaining_ms' => $harness->remainingMs,
        'elapsed_ms' => $elapsedMs,
    ]);

    // The runner cannot tell this 55P03 from ordinary contention; only the shared clock stops the retry.
    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown?->getMessage())->toBe(DeadlineBoundTransaction::ELAPSED_BEFORE_WORK)
        ->and($harness->sqlStates)->toBe(['55P03'])
        ->and($harness->attempts)->toBe(2)
        ->and($harness->remainingMs[0])->toBeGreaterThan(0)
        ->and($harness->remainingMs[1])->toBeLessThanOrEqual(0)
        ->and($harness->settingAtAttemptStart)->toBe([$before['lock_timeout'], $before['lock_timeout']])
        ->and($elapsedMs)->toBeGreaterThanOrEqual($harness->remainingMs[0])->toBeLessThan(2_000)
        ->and($pdoInTransaction)->toBeFalse()
        ->and(DB::transactionLevel())->toBe(0);

    deadlineExpectGateFirstOnEveryAttempt($log, 2);

    expect(IntegrationDatabase::backendPid())->toBe($pid)
        ->and(deadlineSettings())->toBe($before)
        ->and(deadlineGateLocksHeldBy($pid))->toBe(0)
        ->and(deadlineObservedRows())->toBe([0, 0, 0]);
});

it('multiplies a fixed lock_timeout across the three-attempt budget', function (): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    DB::statement("set statement_timeout = '5s'");

    $before = deadlineSettings();
    deadlineLockMerchant($merchant->getKey());
    $harness = deadlineHarness('lock_timeout', 400, fixedBoundMs: 400);

    [$thrown, $elapsedMs] = deadlineInitiate($context);

    deadlineEvidence('lock_timeout.blocked.fixed', [
        'attempt_sqlstates' => $harness->sqlStates,
        'remaining_ms' => $harness->remainingMs,
        'elapsed_ms' => $elapsedMs,
    ]);

    expect($thrown)->toBeInstanceOf(IdempotencyContention::class)
        ->and($harness->sqlStates)->toBe(['55P03', '55P03', '55P03'])
        ->and($harness->remainingMs[2])->toBeLessThan(0)
        ->and($elapsedMs)->toBeGreaterThanOrEqual(3 * 400)
        ->and(deadlineSettings())->toBe($before)
        ->and(deadlineObservedRows())->toBe([0, 0, 0]);
});

it('terminates the session on a blocked write under transaction_timeout and reconnects on next use', function (): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    DB::statement("set lock_timeout = '5s'");

    $pid = IntegrationDatabase::backendPid();
    deadlineLockMerchant($merchant->getKey());
    $harness = deadlineHarness('transaction_timeout', 400);

    [$thrown, $elapsedMs] = deadlineInitiate($context);

    $levelAfter = DB::transactionLevel();
    // The production runner wraps a lost connection; the raw driver failure stays the evidence.
    $raw = $thrown instanceof PaymentIntentSessionTerminated ? $thrown->getPrevious() : $thrown;
    $sqlState = RetryingAtomicTransaction::sqlStateOf($raw ?? new RuntimeException);
    $aliveAfter = deadlineBackendAlive($pid);
    $gateLocksAfter = deadlineGateLocksHeldBy($pid);

    $reuse = null;

    try {
        DB::select('select 1');
    } catch (Throwable $exception) {
        $reuse = $exception;
    }

    $pidAfter = IntegrationDatabase::backendPid();

    deadlineEvidence('transaction_timeout.blocked', [
        'exception' => $thrown === null ? null : $thrown::class,
        'message' => deadlineServerMessage($raw),
        'sqlstate' => $sqlState,
        'attempt_exceptions' => $harness->exceptionClasses,
        'attempt_sqlstates' => $harness->sqlStates,
        'attempts' => $harness->attempts,
        'remaining_ms' => $harness->remainingMs,
        'elapsed_ms' => $elapsedMs,
        'level_after' => $levelAfter,
        'backend_alive_after' => $aliveAfter,
        'next_query_failed' => $reuse === null ? null : $reuse::class,
        'pid_changed' => $pidAfter !== $pid,
    ]);

    // Laravel's rollback on the dead socket replaces the statement's own failure, and PDO never surfaces 25P04.
    expect($thrown)->toBeInstanceOf(PaymentIntentSessionTerminated::class)
        ->and($raw)->toBeInstanceOf(PDOException::class)->not->toBeInstanceOf(QueryException::class)
        ->and(deadlineServerMessage($raw))->toContain('no connection to the server')
        ->and($sqlState)->toBe('HY000')
        ->and(RetryingAtomicTransaction::isRetryable('HY000'))->toBeFalse()
        ->and($harness->exceptionClasses)->toBe([QueryException::class])
        ->and($harness->sqlStates)->toBe(['HY000'])
        ->and($harness->attempts)->toBe(1)
        ->and($levelAfter)->toBe(0)
        ->and($reuse)->toBeNull()
        ->and($elapsedMs)->toBeGreaterThanOrEqual($harness->remainingMs[0])->toBeLessThan(2_000)
        ->and($aliveAfter)->toBeFalse()
        ->and($gateLocksAfter)->toBe(0)
        ->and($pidAfter)->not->toBe($pid)
        ->and(deadlineObservedRows())->toBe([0, 0, 0]);

    // A new session starts from server defaults, so the test-owned floor is gone with the old one.
    expect(deadlineSettings())->toBe(['statement_timeout' => '0', 'lock_timeout' => '0', 'transaction_timeout' => '0']);
});

it('lets an idle transaction commit after the deadline under :dataset', function (string $candidate): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    $harness = deadlineHarness($candidate, 300);

    // Idle in transaction, as slow PHP after the last statement would be: neither setting bounds idle time.
    Event::listen(TransactionCommitting::class, fn () => deadlineAwaitClockPast($harness->deadline));

    [$thrown] = deadlineInitiate($context);

    expect($thrown)->toBeNull()
        ->and(deadlineObservedRows())->toBe([1, 1, 1]);
})->with(['statement_timeout', 'lock_timeout']);

it('terminates an idle transaction at the deadline under transaction_timeout so COMMIT cannot land', function (): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    $pid = IntegrationDatabase::backendPid();
    $harness = deadlineHarness('transaction_timeout', 300);

    Event::listen(TransactionCommitting::class, fn () => deadlineAwait(
        fn (): bool => ! deadlineBackendAlive($pid),
        'transaction_timeout never terminated the idle backend',
    ));

    [$thrown, $elapsedMs] = deadlineInitiate($context);

    // The production runner wraps a lost connection; the raw driver failure stays the evidence.
    $raw = $thrown instanceof PaymentIntentSessionTerminated ? $thrown->getPrevious() : $thrown;
    $sqlState = RetryingAtomicTransaction::sqlStateOf($raw ?? new RuntimeException);

    deadlineEvidence('transaction_timeout.idle', [
        'exception' => $thrown === null ? null : $thrown::class,
        'message' => deadlineServerMessage($raw),
        'sqlstate' => $sqlState,
        'attempts' => $harness->attempts,
        'remaining_ms' => $harness->remainingMs,
        'elapsed_ms' => $elapsedMs,
    ]);

    // The failure surfaces from COMMIT itself, after the backend is already gone.
    expect($thrown)->toBeInstanceOf(PaymentIntentSessionTerminated::class)
        ->and($raw)->toBeInstanceOf(PDOException::class)->not->toBeInstanceOf(QueryException::class)
        ->and(deadlineServerMessage($raw))->toContain('terminating connection due to transaction timeout')
        ->and($sqlState)->toBe('HY000')
        ->and($harness->attempts)->toBe(1)
        ->and(DB::transactionLevel())->toBe(0)
        ->and($elapsedMs)->toBeGreaterThanOrEqual($harness->remainingMs[0])
        ->and(IntegrationDatabase::backendPid())->not->toBe($pid)
        ->and(deadlineObservedRows())->toBe([0, 0, 0]);
});

it('starts a mid-transaction transaction_timeout from the SET rather than from BEGIN', function (): void {
    DB::beginTransaction();

    $failure = null;
    $setAt = 0;

    try {
        // Ages the transaction first, so the two possible timer origins land a second apart.
        DB::select('select pg_sleep(1.0)');
        $setAt = hrtime(true);
        DB::select("select set_config('transaction_timeout', '1500', true)");
        DB::select('select pg_sleep(3.0)');
    } catch (QueryException $caught) {
        $failure = $caught;
    } finally {
        IntegrationDatabase::rollbackOpenTransactions();
    }

    $sinceSetMs = (int) ((hrtime(true) - $setAt) / 1_000_000);

    deadlineEvidence('transaction_timeout.origin', ['since_set_ms' => $sinceSetMs]);

    // From BEGIN it would fire about 500 ms after the SET; from the SET, about 1500 ms.
    expect($failure)->not->toBeNull('transaction_timeout did not fire')
        ->and($sinceSetMs)->toBeGreaterThanOrEqual(1_400)->toBeLessThan(2_800);
});

it('bounds each statement rather than the attempt under statement_timeout', function (): void {
    DB::beginTransaction();

    $startedAt = hrtime(true);

    try {
        DB::select("select set_config('statement_timeout', '1000', true)");
        DB::select('select pg_sleep(0.6)');
        DB::select('select pg_sleep(0.6)');
    } finally {
        IntegrationDatabase::rollbackOpenTransactions();
    }

    $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

    expect($elapsedMs)->toBeGreaterThan(1_000);
});

it('does not bound non-lock work under lock_timeout', function (): void {
    DB::beginTransaction();

    $startedAt = hrtime(true);

    try {
        DB::select("select set_config('lock_timeout', '200', true)");
        DB::select('select pg_sleep(0.5)');
    } finally {
        IntegrationDatabase::rollbackOpenTransactions();
    }

    expect((int) ((hrtime(true) - $startedAt) / 1_000_000))->toBeGreaterThanOrEqual(500);
});

it('runs nothing between the end of the work closure and COMMIT except commit listeners', function (): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    $log = deadlineStatementLog();
    $harness = deadlineHarness('statement_timeout', 10_000);
    $harness->afterWork = function () use ($log): void {
        $log[] = 'work-returned';
    };

    Event::listen(TransactionCommitting::class, function () use ($log): void {
        $log[] = 'committing';
    });
    Event::listen(TransactionCommitted::class, function () use ($log): void {
        $log[] = 'committed';
    });

    resolve(InitiatePayment::class)($context);

    $entries = $log->getArrayCopy();
    $workReturned = array_search('work-returned', $entries, true);
    $outboxInsert = array_key_first(array_filter(
        $entries,
        fn (string $entry): bool => str_starts_with($entry, 'insert into "payment_outbox"'),
    ));

    expect($outboxInsert)->not->toBeNull()
        ->and($outboxInsert)->toBeLessThan($workReturned)
        ->and(array_slice($entries, (int) $workReturned))->toBe(['work-returned', 'committing', 'committed']);
});

it('refuses a deadline that passes before COMMIT when re-checked at the :dataset', function (string $location): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    $before = deadlineSettings();
    $pid = IntegrationDatabase::backendPid();
    $harness = deadlineHarness('statement_timeout', 300);

    $recheck = function () use ($harness): void {
        deadlineAwaitClockPast($harness->deadline);

        if ($harness->remainingMsNow() <= 0) {
            throw new RuntimeException('deadline elapsed before commit');
        }
    };

    if ($location === 'end of the work closure') {
        $harness->afterWork = $recheck;
    } else {
        Event::listen(TransactionCommitting::class, $recheck);
    }

    [$thrown] = deadlineInitiate($context);

    $pdoInTransaction = DB::connection()->getPdo()->inTransaction();

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown?->getMessage())->toBe('deadline elapsed before commit')
        ->and($harness->attempts)->toBe(1)
        ->and($pdoInTransaction)->toBeFalse()
        ->and(DB::transactionLevel())->toBe(0)
        ->and(IntegrationDatabase::backendPid())->toBe($pid)
        ->and(deadlineSettings())->toBe($before)
        ->and(deadlineGateLocksHeldBy($pid))->toBe(0)
        ->and(deadlineObservedRows())->toBe([0, 0, 0]);
})->with(['end of the work closure', 'TransactionCommitting listener']);

it('measures residual COMMIT latency after a passing pre-COMMIT re-check', function (): void {
    $merchant = Merchant::factory()->create();

    $commitMs = [];
    $recheckToVisibleMs = [];

    for ($sample = 0; $sample < 20; $sample++) {
        $harness = deadlineHarness('statement_timeout', 10_000);
        $recheckedAt = null;
        $committingAt = 0;

        $harness->afterWork = function () use (&$recheckedAt): void {
            $recheckedAt = (float) DB::selectOne('select extract(epoch from clock_timestamp()) as at')->at;
        };

        Event::listen(TransactionCommitting::class, function () use (&$committingAt): void {
            $committingAt = hrtime(true);
        });

        resolve(InitiatePayment::class)(Initiations::context($merchant->getKey(), key: 'residual-'.$sample));

        $commitMs[] = (hrtime(true) - $committingAt) / 1_000_000;
        $visibleAt = (float) DB::connection(IntegrationDatabase::OBSERVER)
            ->selectOne('select extract(epoch from clock_timestamp()) as at')->at;
        $recheckToVisibleMs[] = ($visibleAt - (float) $recheckedAt) * 1_000;

        Event::forget(TransactionCommitting::class);
    }

    deadlineEvidence('commit.residual', [
        'commit_round_trip_ms' => deadlineSummary($commitMs),
        'recheck_to_observer_visible_ms' => deadlineSummary($recheckToVisibleMs),
    ]);

    expect(IntegrationDatabase::observedCount('payment_idempotency_receipts'))->toBe(20)
        ->and(min($recheckToVisibleMs))->toBeGreaterThan(0.0)
        ->and(max($commitMs))->toBeLessThan(1_000.0);
});

it('commits when COMMIT is issued just before the deadline under :dataset', function (string $candidate): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    $harness = deadlineHarness($candidate, 300);
    $remainingAtCommit = null;
    $committingAt = 0;

    Event::listen(TransactionCommitting::class, function () use ($harness, &$remainingAtCommit, &$committingAt): void {
        deadlineAwait(fn (): bool => $harness->remainingMsNow() < 100, 'the deadline never came within 100 ms');
        $remainingAtCommit = $harness->remainingMsNow();
        $committingAt = hrtime(true);
    });

    [$thrown] = deadlineInitiate($context);

    $commitMs = (hrtime(true) - $committingAt) / 1_000_000;

    deadlineEvidence("commit.near_deadline.{$candidate}", [
        'remaining_ms_at_commit' => $remainingAtCommit,
        'commit_round_trip_ms' => round($commitMs, 3),
    ]);

    // A per-statement bound restarts for COMMIT itself, so a near-deadline COMMIT is not cut short.
    expect($thrown)->toBeNull()
        ->and(deadlineObservedRows())->toBe([1, 1, 1]);
})->with(['statement_timeout', 'lock_timeout']);

it('freezes now() at transaction start while clock_timestamp() advances', function (): void {
    DB::beginTransaction();

    try {
        $first = DB::selectOne('select now()::text as frozen, clock_timestamp()::text as wall');
        $second = DB::selectOne(
            'select now()::text as frozen, clock_timestamp()::text as wall, (clock_timestamp() > ?::timestamptz)::int as advanced',
            [$first->wall],
        );
    } finally {
        IntegrationDatabase::rollbackOpenTransactions();
    }

    expect($second->frozen)->toBe($first->frozen)
        ->and((int) $second->advanced)->toBe(1);
});

it('measures the PostgreSQL clock against the PHP clock', function (): void {
    $offsetMs = [];
    $halfRoundTripMs = [];

    for ($sample = 0; $sample < 20; $sample++) {
        $before = microtime(true);
        $postgres = (float) DB::selectOne('select extract(epoch from clock_timestamp()) as at')->at;
        $after = microtime(true);

        $offsetMs[] = ($postgres - ($before + $after) / 2) * 1_000;
        $halfRoundTripMs[] = ($after - $before) / 2 * 1_000;
    }

    deadlineEvidence('clock.postgres_minus_php', [
        'offset_ms' => deadlineSummary($offsetMs),
        'half_round_trip_ms' => deadlineSummary($halfRoundTripMs),
    ]);

    // Loose on purpose: both containers read one kernel clock here, which a deployment does not guarantee.
    $bestBound = min(array_map(
        fn (float $offset, float $half): float => abs($offset) - $half,
        $offsetMs,
        $halfRoundTripMs,
    ));

    expect($bestBound)->toBeLessThan(1_000.0);
});
