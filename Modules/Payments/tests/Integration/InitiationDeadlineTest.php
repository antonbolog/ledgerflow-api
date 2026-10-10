<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Application\Initiation\IdempotencyReceipts;
use Modules\Payments\Application\Initiation\InitiatePayment;
use Modules\Payments\Application\Initiation\PaymentInitiationContext;
use Modules\Payments\Application\Initiation\PaymentIntentDeadlineRefused;
use Modules\Payments\Application\Initiation\PaymentIntentSessionTerminated;
use Modules\Payments\Application\Initiation\ReservationRaced;
use Modules\Payments\Infrastructure\Persistence\DatabaseIdempotencyReceipts;
use Modules\Payments\Infrastructure\Persistence\DatabasePaymentIntentDeadline;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Modules\Payments\Tests\Integration\ContentionProbe;
use Modules\Payments\Tests\Integration\DeadlineMarginProbe;
use Modules\Payments\Tests\Integration\Initiations;
use Modules\Payments\Tests\Integration\IntegrationDatabase;
use Modules\Payments\Tests\Integration\RacingReceipts;
use Spatie\EventSourcing\Projectionist;

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

const BOUNDED_DEFAULT_SETTINGS = ['statement_timeout' => '0', 'lock_timeout' => '0', 'transaction_timeout' => '0'];

function boundedExpiryIn(int $milliseconds): string
{
    return (string) DB::connection(IntegrationDatabase::OBSERVER)->selectOne(
        "select to_char((clock_timestamp() + make_interval(secs => ?::float8 / 1000)) at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"') as e",
        [$milliseconds],
    )->e;
}

function boundedContext(int $merchantId, int $milliseconds, string $key = Initiations::KEY): PaymentInitiationContext
{
    return Initiations::context($merchantId, key: $key, intentId: (string) Str::uuid(), intentExpiresAt: boundedExpiryIn($milliseconds));
}

/** @return array{mixed, int} */
function boundedRun(PaymentInitiationContext $context): array
{
    $startedAt = hrtime(true);

    try {
        $outcome = resolve(InitiatePayment::class)($context);
    } catch (Throwable $exception) {
        $outcome = $exception;
    }

    return [$outcome, (int) ((hrtime(true) - $startedAt) / 1_000_000)];
}

/** @return array<string, string> */
function boundedCurrentSettings(): array
{
    return (array) DB::selectOne(
        "select current_setting('statement_timeout') as statement_timeout, "
        ."current_setting('lock_timeout') as lock_timeout, "
        ."current_setting('transaction_timeout') as transaction_timeout",
    );
}

function boundedGateLocks(int $pid): int
{
    return (int) DB::connection(IntegrationDatabase::OBSERVER)->selectOne(
        "select count(*) as held from pg_locks where locktype = 'advisory' and classid = ? and pid = ?",
        [ProjectionGate::CLASS_ID, $pid],
    )->held;
}

function boundedAlive(int $pid): bool
{
    return (int) DB::connection(IntegrationDatabase::OBSERVER)
        ->selectOne('select count(*) as alive from pg_stat_activity where pid = ?', [$pid])->alive === 1;
}

function boundedObserverBefore(string $expiresAt): bool
{
    return (int) DB::connection(IntegrationDatabase::OBSERVER)
        ->selectOne('select (clock_timestamp() < ?::timestamptz)::int as before', [$expiresAt])->before === 1;
}

/** @return array{int, int, int} */
function boundedRows(): array
{
    return [
        IntegrationDatabase::observedCount('payment_idempotency_receipts'),
        IntegrationDatabase::observedCount('stored_events'),
        IntegrationDatabase::observedCount('payment_outbox'),
    ];
}

function boundedAwait(Closure $condition, string $failure): void
{
    $giveUpAt = hrtime(true) + 5_000_000_000;

    while (! $condition()) {
        if (hrtime(true) > $giveUpAt) {
            throw new RuntimeException($failure);
        }

        Sleep::usleep(5_000);
    }
}

/** @return ArrayObject<int, string> begin markers, default-connection SQL and commit markers, in order */
function boundedLog(): ArrayObject
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

    Event::listen(TransactionCommitting::class, function () use ($log): void {
        $log[] = 'committing';
    });

    return $log;
}

/** @param ArrayObject<int, string> $log */
function boundedExpectAttemptOrder(ArrayObject $log, int $attempts, bool $armed = true): void
{
    $entries = $log->getArrayCopy();
    $begins = array_keys($entries, 'begin', true);

    expect($begins)->toHaveCount($attempts);

    foreach ($begins as $begin) {
        expect($entries[$begin + 1] ?? '')->toContain('pg_try_advisory_xact_lock_shared')
            ->and($entries[$begin + 2] ?? '')->toContain('payment_projection_controls')
            ->and($entries[$begin + 3] ?? '')->toContain('from "payment_idempotency_receipts"');

        if ($armed) {
            expect($entries[$begin + 4] ?? '')->toContain("set_config('transaction_timeout'");
        }
    }
}

/** @param ArrayObject<int, string> $log */
function boundedWrites(ArrayObject $log): int
{
    return count(array_filter($log->getArrayCopy(), fn (string $entry): bool => str_starts_with($entry, 'insert into')));
}

function boundedContention(int $failures, string $lockTimeout): ContentionProbe
{
    $decoy = Merchant::factory()->create();

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->select('select id from merchants where id = ? for update', [$decoy->getKey()]);

    app()->singleton(ContentionProbe::class);

    $probe = resolve(ContentionProbe::class);
    $probe->decoyMerchantId = $decoy->getKey();
    $probe->failuresRemaining = $failures;
    $probe->lockTimeout = $lockTimeout;

    resolve(Projectionist::class)->addProjector(ContentionProbe::class);

    return $probe;
}

function boundedAwaitInsideMargin(string $expiresAt): void
{
    boundedAwait(
        fn (): bool => (int) DB::connection(IntegrationDatabase::OBSERVER)->selectOne(
            'select (floor(extract(epoch from (?::timestamptz - clock_timestamp())) * 1000) <= ?)::int as inside',
            [$expiresAt, DatabasePaymentIntentDeadline::MARGIN_MS],
        )->inside === 1,
        'the deadline never came inside the margin',
    );
}

it('classifies, then arms the deadline, re-checks it last, and commits before it', function (): void {
    $merchant = Merchant::factory()->create()->getKey();
    $context = boundedContext($merchant, 10_000);

    $before = boundedCurrentSettings();
    $pid = IntegrationDatabase::backendPid();
    $log = boundedLog();

    $armed = null;
    $gateLocksInside = null;
    $committedBeforeDeadline = null;

    Event::listen(TransactionCommitting::class, function () use (&$armed, &$gateLocksInside, $pid): void {
        $armed = (string) DB::selectOne("select current_setting('transaction_timeout') as value")->value;
        $gateLocksInside = boundedGateLocks($pid);
    });
    Event::listen(TransactionCommitted::class, function () use (&$committedBeforeDeadline, $context): void {
        $committedBeforeDeadline = boundedObserverBefore($context->intentExpiresAt);
    });

    [$outcome] = boundedRun($context);

    boundedExpectAttemptOrder($log, 1);

    $entries = $log->getArrayCopy();
    $committing = array_search('committing', $entries, true);

    expect($outcome)->not->toBeInstanceOf(Throwable::class)
        ->and($committing)->not->toBeFalse()
        ->and($entries[$committing - 1])->toContain('clock_timestamp')->not->toContain('set_config')
        ->and($armed)->not->toBe('0')
        ->and($gateLocksInside)->toBe(1)
        ->and($committedBeforeDeadline)->toBeTrue();

    expect(boundedCurrentSettings())->toBe($before)
        ->and(boundedGateLocks($pid))->toBe(0)
        ->and(IntegrationDatabase::backendPid())->toBe($pid)
        ->and(boundedRows())->toBe([1, 1, 1]);
});

it('refuses before creating any work when the deadline is :dataset', function (int $milliseconds, bool $settled): void {
    $merchant = Merchant::factory()->create()->getKey();
    $context = boundedContext($merchant, $milliseconds);

    $before = boundedCurrentSettings();
    $pid = IntegrationDatabase::backendPid();
    $log = boundedLog();

    [$outcome] = boundedRun($context);

    $pdoInTransaction = DB::connection()->getPdo()->inTransaction();

    expect($outcome)->toBeInstanceOf(PaymentIntentDeadlineRefused::class)
        ->and($outcome->millisecondsUntilSettled > 0)->toBe(! $settled)
        ->and(boundedWrites($log))->toBe(0)
        ->and($pdoInTransaction)->toBeFalse()
        ->and(DB::transactionLevel())->toBe(0);

    boundedExpectAttemptOrder($log, 1);

    expect(boundedCurrentSettings())->toBe($before)
        ->and(boundedGateLocks($pid))->toBe(0)
        ->and(IntegrationDatabase::backendPid())->toBe($pid)
        ->and(boundedRows())->toBe([0, 0, 0]);
})->with([
    'past the residual allowance' => [-5_000, true],
    'past, within the residual allowance' => [-200, false],
    'inside the margin' => [DatabasePaymentIntentDeadline::MARGIN_MS / 2, false],
]);

it('refuses at the pre-COMMIT re-check inside the margin and rolls back on the live session', function (): void {
    $merchant = Merchant::factory()->create()->getKey();
    $context = boundedContext($merchant, 1_000);

    app()->singleton(DeadlineMarginProbe::class);
    resolve(DeadlineMarginProbe::class)->intentExpiresAt = $context->intentExpiresAt;
    resolve(Projectionist::class)->addProjector(DeadlineMarginProbe::class);

    $before = boundedCurrentSettings();
    $pid = IntegrationDatabase::backendPid();
    $log = boundedLog();

    [$outcome] = boundedRun($context);

    $pdoInTransaction = DB::connection()->getPdo()->inTransaction();

    expect($outcome)->toBeInstanceOf(PaymentIntentDeadlineRefused::class)
        ->and($outcome->getMessage())->toContain('commit')
        ->and($outcome->millisecondsUntilSettled)->toBeGreaterThan(DatabasePaymentIntentDeadline::RESIDUAL_MS)
        ->and(boundedWrites($log))->toBeGreaterThan(0)
        ->and($log->getArrayCopy())->not->toContain('committing')
        ->and($pdoInTransaction)->toBeFalse()
        ->and(DB::transactionLevel())->toBe(0);

    expect(IntegrationDatabase::backendPid())->toBe($pid)
        ->and(boundedCurrentSettings())->toBe($before)
        ->and(boundedGateLocks($pid))->toBe(0)
        ->and(boundedRows())->toBe([0, 0, 0]);
});

it('ends the session when PHP delays COMMIT past the deadline after a passing re-check', function (): void {
    $merchant = Merchant::factory()->create()->getKey();
    $context = boundedContext($merchant, 600);
    $pid = IntegrationDatabase::backendPid();

    $goneBeforeDeadline = null;

    // The stall sits between the re-check and COMMIT, the one window the re-check cannot cover.
    Event::listen(TransactionCommitting::class, function () use ($pid, $context, &$goneBeforeDeadline): void {
        boundedAwait(fn (): bool => ! boundedAlive($pid), 'the deadline never ended the stalled backend');
        $goneBeforeDeadline = boundedObserverBefore($context->intentExpiresAt);
    });

    [$outcome] = boundedRun($context);

    expect($outcome)->toBeInstanceOf(PaymentIntentSessionTerminated::class)
        ->and($outcome->getPrevious())->toBeInstanceOf(PDOException::class)
        ->and($goneBeforeDeadline)->toBeFalse()
        ->and(DB::transactionLevel())->toBe(0)
        ->and(boundedRows())->toBe([0, 0, 0]);

    expect(IntegrationDatabase::backendPid())->not->toBe($pid)
        ->and(boundedGateLocks($pid))->toBe(0)
        ->and(boundedCurrentSettings())->toBe(BOUNDED_DEFAULT_SETTINGS);
});

it('re-arms the deadline when a non-zero session default transaction_timeout is already running', function (): void {
    $merchant = Merchant::factory()->create()->getKey();
    $pid = IntegrationDatabase::backendPid();

    // A non-zero default starts its own timer at BEGIN, and assigning a new value alone does not restart it.
    DB::statement("set transaction_timeout = '60s'");

    $context = boundedContext($merchant, 600);
    $goneBeforeDeadline = null;

    Event::listen(TransactionCommitting::class, function () use ($pid, $context, &$goneBeforeDeadline): void {
        boundedAwait(fn (): bool => ! boundedAlive($pid), 'the session default kept the stalled backend alive past the deadline');
        $goneBeforeDeadline = boundedObserverBefore($context->intentExpiresAt);
    });

    [$outcome] = boundedRun($context);

    expect($outcome)->toBeInstanceOf(PaymentIntentSessionTerminated::class)
        ->and($goneBeforeDeadline)->toBeFalse()
        ->and(boundedRows())->toBe([0, 0, 0]);
});

it('bounds a wait behind an in-flight insert of the same intent by the deadline', function (): void {
    $merchant = Merchant::factory()->create()->getKey();
    $intentId = (string) Str::uuid();
    $competitor = Initiations::context($merchant, key: 'holder', intentId: $intentId);

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->table('payment_idempotency_receipts')->insert([
        'id' => (string) Str::uuid7(),
        'merchant_id' => $merchant,
        'idempotency_key_hash' => $competitor->idempotencyKeyHash,
        'request_fingerprint' => $competitor->requestFingerprint,
        'payment_id' => $competitor->paymentId,
        'response_status' => 202,
        'response_body' => $competitor->responseBody,
        'response_location' => $competitor->responseLocation,
        'intent_id' => $competitor->intentId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Stamped after the setup, so only the wait itself spends the deadline.
    $context = Initiations::context($merchant, key: 'waiter', intentId: $intentId, intentExpiresAt: boundedExpiryIn(800));

    [$outcome, $elapsedMs] = boundedRun($context);

    $endedBeforeDeadline = boundedObserverBefore($context->intentExpiresAt);

    $lock->rollBack();

    expect($outcome)->toBeInstanceOf(PaymentIntentSessionTerminated::class)
        ->and($endedBeforeDeadline)->toBeFalse()
        ->and($elapsedMs)->toBeLessThan(3_000)
        ->and(boundedRows())->toBe([0, 0, 0]);
});

it('spends one deadline across retried attempts instead of multiplying it', function (): void {
    $merchant = Merchant::factory()->create()->getKey();

    $probe = boundedContention(failures: PHP_INT_MAX, lockTimeout: '400ms');

    $before = boundedCurrentSettings();
    $pid = IntegrationDatabase::backendPid();
    $log = boundedLog();

    // Stamped after the setup, so only the attempts spend the deadline.
    $context = boundedContext($merchant, 1_000);

    [$outcome, $elapsedMs] = boundedRun($context);

    // Three attempts at a fixed 400 ms bound would have taken about 1200 ms and ended as IdempotencyContention.
    expect($outcome)->toBeInstanceOf(PaymentIntentDeadlineRefused::class)
        ->and($probe->sqlStates)->toBe(['55P03', '55P03'])
        ->and($elapsedMs)->toBeLessThan(1_000 + 500);

    boundedExpectAttemptOrder($log, 3);

    expect(boundedCurrentSettings())->toBe($before)
        ->and(IntegrationDatabase::backendPid())->toBe($pid)
        ->and(boundedGateLocks($pid))->toBe(0)
        ->and(boundedRows())->toBe([0, 0, 0]);
});

it('refuses the reservation rerun before work once the shared deadline is spent', function (): void {
    $merchant = Merchant::factory()->create()->getKey();
    $context = boundedContext($merchant, 1_000);

    $racing = new RacingReceipts(new DatabaseIdempotencyReceipts(DB::connection()));
    $racing->beforeFirstReservation = function () use ($context): void {
        boundedAwaitInsideMargin($context->intentExpiresAt);

        throw ReservationRaced::onAnotherUniqueKey();
    };
    app()->instance(IdempotencyReceipts::class, $racing);

    $pid = IntegrationDatabase::backendPid();
    $log = boundedLog();

    [$outcome] = boundedRun($context);

    expect($outcome)->toBeInstanceOf(PaymentIntentDeadlineRefused::class)
        ->and($outcome->getMessage())->toContain('start')
        ->and($racing->reservations)->toBe(1)
        ->and(boundedWrites($log))->toBe(0)
        ->and(IntegrationDatabase::backendPid())->toBe($pid)
        ->and(boundedRows())->toBe([0, 0, 0]);

    boundedExpectAttemptOrder($log, 2);
});
