<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Application\Initiation\IdempotencyContention;
use Modules\Payments\Application\Initiation\InitiatePayment;
use Modules\Payments\Application\Initiation\ProjectionRebuilding;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Modules\Payments\Tests\Integration\ContentionProbe;
use Modules\Payments\Tests\Integration\IdentityRecorder;
use Modules\Payments\Tests\Integration\Initiations;
use Modules\Payments\Tests\Integration\IntegrationDatabase;
use Modules\Payments\Tests\Integration\ProjectionGateLocks;
use Modules\Payments\Tests\Integration\ThrowingProjector;
use Spatie\EventSourcing\Projectionist;

/*
|--------------------------------------------------------------------------
| POST /api/payments — the commit boundary
|--------------------------------------------------------------------------
|
| WHAT THIS FILE PROVES, AND WHY IT CANNOT LIVE IN THE FEATURE SUITE:
| RefreshDatabase wraps every Feature test in a transaction, which turns the
| production DB::transaction() into a SAVEPOINT. At savepoint depth,
| TransactionCommitting never fires, no second connection can observe a commit,
| and "all three rows exist" would pass even against a handler that opened no
| transaction at all. Every claim here is about a REAL commit boundary, read from
| a SECOND BACKEND, and none of it is available under RefreshDatabase.
|
*/

beforeEach(function (): void {
    IntegrationDatabase::ensureSchema();
    IntegrationDatabase::register();

    // COMPLAINS, NEVER REPAIRS. An earlier version rolled back here instead, which
    // silently unwound RefreshDatabase's wrapper and made this suite unable to
    // notice it had been wrapped — the mutation that added the trait passed
    // untouched. Cleanup lives in afterEach; this only refuses.
    IntegrationDatabase::refuseIfWrapped();

    IntegrationDatabase::truncate();
});

/*
| ORDER IS LOAD-BEARING, AND ONE STEP WAS ADDED AFTER A REAL HANG.
|
| rollbackOpenTransactions() first: a commit-boundary failure leaves the SERVER
| transaction open while transactionLevel() reports 0 (see IntegrationDatabase),
| and truncating inside it takes ACCESS EXCLUSIVE that the next test's observer
| then blocks on forever.
|
| releaseLocks() next, and before truncation: TRUNCATE would otherwise queue
| behind the FOR UPDATE row lock it is trying to clear (E3).
*/
afterEach(function (): void {
    IntegrationDatabase::rollbackOpenTransactions();
    IntegrationDatabase::releaseLocks();
    IntegrationDatabase::truncate();
    IntegrationDatabase::disconnectAll();
});

it('commits receipt, stored event and outbox in one transaction on one backend', function (): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    $backendBefore = IntegrationDatabase::backendPid();

    // WHAT THE OBSERVER SEES WHILE THE TRANSACTION IS STILL OPEN. Captured at
    // TransactionCommitting, which fires at depth 1 AFTER every statement in the
    // closure and BEFORE the COMMIT — the one moment where "written but not yet
    // committed" is a distinguishable state. Under RefreshDatabase this event never
    // fires at all, which is precisely why this file exists.
    $observedDuring = null;

    Event::listen(TransactionCommitting::class, function () use (&$observedDuring): void {
        $observedDuring = [
            'receipts' => IntegrationDatabase::observedCount('payment_idempotency_receipts'),
            'stored_events' => IntegrationDatabase::observedCount('stored_events'),
            'outbox' => IntegrationDatabase::observedCount('payment_outbox'),
        ];
    });

    $receipt = resolve(InitiatePayment::class)($context);

    expect($receipt->paymentId)->toBe($context->paymentId);

    // NON-VACUOUS: if the listener never ran, the three zeroes below would be a
    // statement about nothing.
    expect($observedDuring)->not->toBeNull()
        ->and($observedDuring)->toBe(['receipts' => 0, 'stored_events' => 0, 'outbox' => 0]);

    // …and all three are there once it returns. Read from the OBSERVER: on the
    // connection under test they would be visible whether or not it committed.
    expect(IntegrationDatabase::observedCount('payment_idempotency_receipts'))->toBe(1)
        ->and(IntegrationDatabase::observedCount('stored_events'))->toBe(1)
        ->and(IntegrationDatabase::observedCount('payment_outbox'))->toBe(1);

    // ONE xmin ACROSS ALL THREE TUPLES. This is PostgreSQL's own record of which
    // transaction wrote each row, and it is the assertion that "one transaction"
    // rests on — not a restatement of how the connections were configured. Three
    // sequential commits would give three different values here and still satisfy
    // every count above.
    $receiptXmin = IntegrationDatabase::observedXmin('payment_idempotency_receipts');

    expect($receiptXmin)->not->toBe('')
        ->and(IntegrationDatabase::observedXmin('stored_events'))->toBe($receiptXmin)
        ->and(IntegrationDatabase::observedXmin('payment_outbox'))->toBe($receiptXmin);

    // ONE BACKEND. A silent reconnect mid-transaction would have moved the work to
    // a different session, and the xmin comparison alone could not see it.
    expect(IntegrationDatabase::backendPid())->toBe($backendBefore)
        ->and(DB::transactionLevel())->toBe(0);
});

it('rolls all three back when the failure lands after everything, before COMMIT', function (): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    // THE LAST POSSIBLE MOMENT INSIDE THE TRANSACTION. Everything the use case does
    // has already happened — receipt reserved, event stored, envelope written — and
    // the COMMIT has not. Nothing else can inject a failure precisely here without a
    // production fault flag, and it fires only at depth 1, so RefreshDatabase could
    // not stage this test at all.
    Event::listen(TransactionCommitting::class, fn () => throw new RuntimeException('injected at the commit boundary'));

    $thrown = null;

    try {
        resolve(InitiatePayment::class)($context);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    // The original failure, not a contention: a RuntimeException carries no
    // SQLSTATE, so the runner classifies it non-retryable and rethrows it unchanged.
    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown?->getMessage())->toContain('injected at the commit boundary');

    // ALL THREE GONE, from the observer. Partial durability here would mean a
    // stored event with no envelope, or a receipt promising a payment that no event
    // records.
    expect(IntegrationDatabase::observedCount('payment_idempotency_receipts'))->toBe(0)
        ->and(IntegrationDatabase::observedCount('stored_events'))->toBe(0)
        ->and(IntegrationDatabase::observedCount('payment_outbox'))->toBe(0)
        // The merchant is still there, so the rollback undid the transaction rather
        // than the whole test.
        ->and(IntegrationDatabase::observedCount('merchants'))->toBe(1)
        ->and(DB::transactionLevel())->toBe(0);

    // THE CONNECTION IS ACTUALLY CLEAN, NOT MERELY REPORTED CLEAN — and this pair of
    // assertions is the whole reason the production runner has a cleanup step.
    //
    // transactionLevel() is 0 above because handleCommitTransactionException() SET it
    // to 0; it is not evidence of anything. Laravel fires `committing` BEFORE
    // getPdo()->commit(), so a throwing listener leaves that method rolling the PDO
    // back only for a concurrency error with attempts remaining — never at
    // $attempts = 1. Without RetryingAtomicTransaction's own rollback the REAL
    // transaction stays open here: the pooled connection goes back to the next
    // request `idle in transaction`, still holding every lock the abandoned work
    // took, and the next beginTransaction() raises "There is already an active
    // transaction" instead of the failure that actually happened.
    //
    // ASKED BEFORE TEARDOWN, DELIBERATELY. rollbackOpenTransactions() would close it
    // in afterEach and this test would pass against a runner that cleans up nothing —
    // that harness method is a net for a crashed test, not the mechanism that makes
    // production correct.
    expect(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});

it('rolls all three back when a synchronous projector throws after the outbox row', function (): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    // WEIGHT 1, so it runs AFTER the production writer at weight 0 and the outbox
    // row already exists when it throws. EventHandlerCollection::syncEventHandlers()
    // sorts by getWeight(), so this ordering is the package's own mechanism rather
    // than an accident of registration order.
    //
    // THIS IS THE ONLY TEST THAT PROVES THE EXCEPTION ESCAPES THE LIVE PROJECTIONIST
    // with the PRODUCTION writer registered — which is exactly what
    // catch_exceptions => false buys. Were it true, Spatie would swallow this and
    // the stored event would commit without its envelope.
    // SINGLETON FIRST, THEN REGISTER BY CLASS NAME. callEventHandler() re-resolves
    // through app(), and the container builds a concrete class fresh on every
    // resolve unless it is bound — so without this line the instance that recorded
    // is not the instance the assertion below reads.
    $this->app->singleton(ThrowingProjector::class);

    resolve(Projectionist::class)->addProjector(ThrowingProjector::class);

    $thrown = null;

    try {
        resolve(InitiatePayment::class)($context);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown?->getMessage())->toContain('projector failed after the envelope');

    expect(IntegrationDatabase::observedCount('payment_idempotency_receipts'))->toBe(0)
        ->and(IntegrationDatabase::observedCount('stored_events'))->toBe(0)
        ->and(IntegrationDatabase::observedCount('payment_outbox'))->toBe(0)
        ->and(DB::transactionLevel())->toBe(0);

    // NON-VACUOUS: the thrower must actually have seen the envelope, otherwise this
    // test proves rollback from some earlier point and the "after the outbox row"
    // in its name is a lie.
    expect(resolve(ThrowingProjector::class)->outboxRowsSeen)->toBe([1]);
});

/**
 * Installs the three-projector rig and holds a real row lock on a decoy merchant.
 *
 * Returns the recorder and the probe, both container singletons, because
 * Projectionist::callEventHandler() re-resolves every handler through app() and
 * discards the registered instance — a plain `new` would record into an object the
 * test never sees again.
 *
 * @return array{IdentityRecorder, ContentionProbe}
 */
function installContentionRig(int $failures): array
{
    // A merchant NOTHING references, so locking it cannot block the receipt
    // insert's foreign-key check. See ContentionProbe for why that distinction
    // decides whether this test proves anything.
    $decoy = Merchant::factory()->create();

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->select('select id from merchants where id = ? for update', [$decoy->getKey()]);

    // app(), not test()->app: $app is protected on the TestCase and reachable from
    // a bound test closure but not from a plain function.
    app()->singleton(IdentityRecorder::class);
    app()->singleton(ContentionProbe::class);

    $recorder = resolve(IdentityRecorder::class);
    $probe = resolve(ContentionProbe::class);

    $probe->decoyMerchantId = $decoy->getKey();
    $probe->failuresRemaining = $failures;

    resolve(Projectionist::class)->addProjector(IdentityRecorder::class);
    resolve(Projectionist::class)->addProjector(ContentionProbe::class);

    return [$recorder, $probe];
}

it('retries a real 55P03 and commits one logical result with fresh objects', function (): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    [$recorder, $probe] = installContentionRig(failures: 1);

    $receipt = resolve(InitiatePayment::class)($context);

    // A REAL 55P03, from PostgreSQL's own lock_timeout — not a hand-built exception
    // and not a sleep. Exactly one, because the probe disarmed after attempt 1.
    expect($probe->sqlStates)->toBe(['55P03']);

    // FRESH OBJECTS, STABLE IDENTITY. Two attempts reached the recorder; they built
    // DIFFERENT event objects carrying the SAME eventId and the SAME occurredAt.
    // Same ids would mean a rolled-back aggregate was carried across the retry;
    // different eventId or occurredAt would mean the context was rebuilt inside the
    // closure, and the response bytes would no longer be stable.
    //
    // OBJECT IDENTITY, NOT spl_object_id(). The recorder retains the events, so the
    // two are alive at once and cannot share a recycled id; comparing the objects
    // themselves removes the last way this assertion could be right by luck.
    expect($recorder->events)->toHaveCount(2)
        ->and($recorder->events[0])->not->toBe($recorder->events[1])
        ->and($recorder->distinctObjects())->toBe(2)
        ->and($recorder->eventIds)->toBe([$context->eventId, $context->eventId])
        ->and($recorder->occurredAt)->toBe([$context->occurredAt, $context->occurredAt]);

    // ONE LOGICAL RESULT. Two attempts, but exactly one of everything committed,
    // and all of it in one transaction.
    expect($receipt->paymentId)->toBe($context->paymentId)
        ->and(IntegrationDatabase::observedCount('payment_idempotency_receipts'))->toBe(1)
        ->and(IntegrationDatabase::observedCount('stored_events'))->toBe(1)
        ->and(IntegrationDatabase::observedCount('payment_outbox'))->toBe(1);

    $receiptXmin = IntegrationDatabase::observedXmin('payment_idempotency_receipts');

    expect(IntegrationDatabase::observedXmin('stored_events'))->toBe($receiptXmin)
        ->and(IntegrationDatabase::observedXmin('payment_outbox'))->toBe($receiptXmin)
        ->and(DB::transactionLevel())->toBe(0);

    // The aggregate version is 1, not 2: the rolled-back attempt left nothing
    // behind for the second to build on.
    $stored = DB::table('stored_events')->where('event_class', PaymentInitiated::ALIAS)->sole();

    expect((int) $stored->aggregate_version)->toBe(1);
});

it('exhausts at three attempts and leaves no partial state', function (): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    // Never disarmed, so every attempt hits the same held lock.
    [$recorder, $probe] = installContentionRig(failures: PHP_INT_MAX);

    $thrown = null;

    try {
        resolve(InitiatePayment::class)($context);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    // THE RUNNER'S BEHAVIOUR, not the mapper's. The 503 / Retry-After SHAPE is
    // asserted in the Feature suite against a bound double (E16); this test owns
    // the part that needs a real database and a real lock.
    expect($thrown)->toBeInstanceOf(IdempotencyContention::class)
        ->and($thrown?->getMessage())->toContain('55P03')
        ->and($thrown?->getMessage())->toContain('3 attempts');

    // THREE real 55P03s, and three distinct event objects — the retry budget was
    // spent on genuine contention, not on one failure counted three times.
    expect($probe->sqlStates)->toBe(['55P03', '55P03', '55P03'])
        ->and($recorder->events)->toHaveCount(3)
        // By identity. array_unique() over spl_object_id() would answer the same
        // question with an integer PHP is free to reuse.
        ->and($recorder->distinctObjects())->toBe(3);

    // NO PARTIAL STATE. Every attempt rolled back completely; only the two
    // merchants created before the transaction survive.
    expect(IntegrationDatabase::observedCount('payment_idempotency_receipts'))->toBe(0)
        ->and(IntegrationDatabase::observedCount('stored_events'))->toBe(0)
        ->and(IntegrationDatabase::observedCount('payment_outbox'))->toBe(0)
        ->and(IntegrationDatabase::observedCount('merchants'))->toBe(2)
        ->and(DB::transactionLevel())->toBe(0);
});

it('explains a truncation it cannot lock, and gives the bound back afterwards', function (): void {
    // THE HARNESS'S OWN FAILURE PATH, WHICH NOTHING ELSE REACHES. Every other test
    // here truncates successfully, so the diagnostic branch and the reset in
    // `finally` are executed by this test or by nothing.
    //
    // A REAL CONFLICTING LOCK AND NO SLEEP. The lock connection holds FOR UPDATE on
    // a merchants row; TRUNCATE needs ACCESS EXCLUSIVE on that table and cannot have
    // it. PostgreSQL's own lock_timeout ends the wait, so the test's duration is the
    // bound it passes in — a few hundred milliseconds — rather than a guess about
    // scheduling.
    $merchant = Merchant::factory()->create();

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->select('select id from merchants where id = ? for update', [$merchant->getKey()]);

    // Read from the connection itself, not hardcoded: the server default is the
    // server's business, and the claim is that truncate() RESTORES it.
    $before = (string) DB::selectOne('show lock_timeout')->lock_timeout;

    expect(fn () => IntegrationDatabase::truncate('250ms'))
        ->toThrow(RuntimeException::class, 'Could not truncate the Integration tables within 250ms');

    // THE BOUND IS GIVEN BACK. A leaked lock_timeout would turn a later statement on
    // this connection into an intermittent 55P03 with no visible cause.
    expect((string) DB::selectOne('show lock_timeout')->lock_timeout)
        ->toBe($before)
        ->not->toBe('250ms');

    // NON-VACUOUS: the row really is still locked, so the failure above was the
    // conflict this test staged and not some unrelated error.
    expect(IntegrationDatabase::observedCount('merchants'))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| T11's shared projection gate
|--------------------------------------------------------------------------
|
| Only the CLOSED-gate behaviour belongs here. Every test above ran with a
| `ready` control row, and none of it changed - the regression boundary Slice 2
| promises for T6.
*/

/** Close the gate as a rebuild-shaped holder of the durable row would. */
function initiationCloseGate(): void
{
    DB::table('payment_projection_controls')->where('projection', 'payment_details')->update([
        'state' => 'rebuilding',
        'owner_token' => (string) Str::uuid7(),
        'owner_pid' => 999_999,
        'started_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Reopen it, exactly as a completed rebuild would. */
function initiationReopenGate(): void
{
    DB::table('payment_projection_controls')->where('projection', 'payment_details')->update([
        'state' => 'ready',
        'owner_token' => null,
        'owner_pid' => null,
        'started_at' => null,
        'cleared_at' => null,
        'updated_at' => now(),
    ]);
}

it('refuses initiation with ProjectionRebuilding on the first attempt and writes nothing', function (): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    initiationCloseGate();

    $thrown = null;

    try {
        resolve(InitiatePayment::class)($context);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    // NEVER RETRIED: ProjectionRebuilding carries no SQLSTATE, so
    // RetryingAtomicTransaction's classifier treats it as non-retryable and it
    // escapes on the first attempt - the gate does not reopen mid-loop.
    expect($thrown)->toBeInstanceOf(ProjectionRebuilding::class);

    expect(IntegrationDatabase::observedCount('payment_idempotency_receipts'))->toBe(0)
        ->and(IntegrationDatabase::observedCount('stored_events'))->toBe(0)
        ->and(IntegrationDatabase::observedCount('payment_outbox'))->toBe(0)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    initiationReopenGate();

    // The mutation: the gate reopened is what makes the refusal above non-vacuous.
    $receipt = resolve(InitiatePayment::class)($context);
    expect($receipt->paymentId)->toBe($context->paymentId);
});

it('holds the gate lock on its own connection while initiation is refused by the control row', function (): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    initiationCloseGate();

    $lockDuringTransaction = [];

    // DB::listen() FIRES RIGHT AFTER EACH STATEMENT, WHILE THE TRANSACTION IS
    // STILL OPEN — a TransactionCommitting listener never fires on this path: the
    // gate throws, so RetryingAtomicTransaction's transaction() rolls back rather
    // than commits.
    DB::listen(function ($query) use (&$lockDuringTransaction): void {
        if (str_contains($query->sql, 'payment_projection_controls')) {
            $lockDuringTransaction = ProjectionGateLocks::onKey(
                ProjectionGate::CLASS_ID,
                ProjectionGate::PAYMENT_DETAILS_OBJECT_ID,
            );
        }
    });

    $thrown = null;

    try {
        resolve(InitiatePayment::class)($context);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(ProjectionRebuilding::class);

    // GRANTED, ON ONE BACKEND. The control row is what refused initiation, not the
    // lock - both checks are load-bearing, for different failures (D3).
    expect($lockDuringTransaction)->toHaveCount(1)
        ->and($lockDuringTransaction[0]['mode'])->toBe('ShareLock')
        ->and($lockDuringTransaction[0]['granted'])->toBeTrue();

    // Gone once the transaction rolled back.
    expect(ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID))
        ->toBe([]);

    initiationReopenGate();
});
