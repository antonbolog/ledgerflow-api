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

beforeEach(function (): void {
    IntegrationDatabase::ensureSchema();
    IntegrationDatabase::register();
    IntegrationDatabase::refuseIfWrapped();
    IntegrationDatabase::truncate();
});

// Order matters: end an orphaned server transaction and release row locks before truncating.
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

    // TransactionCommitting fires after every statement and before COMMIT, so the observer must still see nothing.
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

    expect($observedDuring)->not->toBeNull()
        ->and($observedDuring)->toBe(['receipts' => 0, 'stored_events' => 0, 'outbox' => 0]);

    expect(IntegrationDatabase::observedCount('payment_idempotency_receipts'))->toBe(1)
        ->and(IntegrationDatabase::observedCount('stored_events'))->toBe(1)
        ->and(IntegrationDatabase::observedCount('payment_outbox'))->toBe(1);

    // One xmin: three sequential commits would still satisfy every count above.
    $receiptXmin = IntegrationDatabase::observedXmin('payment_idempotency_receipts');

    expect($receiptXmin)->not->toBe('')
        ->and(IntegrationDatabase::observedXmin('stored_events'))->toBe($receiptXmin)
        ->and(IntegrationDatabase::observedXmin('payment_outbox'))->toBe($receiptXmin);

    // A silent reconnect would move the work to another session without changing xmin.
    expect(IntegrationDatabase::backendPid())->toBe($backendBefore)
        ->and(DB::transactionLevel())->toBe(0);
});

it('rolls all three back when the failure lands after everything, before COMMIT', function (): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    Event::listen(TransactionCommitting::class, fn () => throw new RuntimeException('injected at the commit boundary'));

    $thrown = null;

    try {
        resolve(InitiatePayment::class)($context);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown?->getMessage())->toContain('injected at the commit boundary');

    expect(IntegrationDatabase::observedCount('payment_idempotency_receipts'))->toBe(0)
        ->and(IntegrationDatabase::observedCount('stored_events'))->toBe(0)
        ->and(IntegrationDatabase::observedCount('payment_outbox'))->toBe(0)
        ->and(IntegrationDatabase::observedCount('merchants'))->toBe(1)
        ->and(DB::transactionLevel())->toBe(0);

    // transactionLevel() is reset to 0 regardless; only the PDO proves the runner's own rollback.
    // Asked before afterEach, whose rollbackOpenTransactions() would otherwise close it.
    expect(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});

it('rolls all three back when a synchronous projector throws after the outbox row', function (): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

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

    expect(resolve(ThrowingProjector::class)->outboxRowsSeen)->toBe([1]);
});

/** @return array{IdentityRecorder, ContentionProbe} */
function installContentionRig(int $failures): array
{
    $decoy = Merchant::factory()->create();

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->select('select id from merchants where id = ? for update', [$decoy->getKey()]);

    // app(), not test()->app: $app is protected on TestCase.
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

    expect($probe->sqlStates)->toBe(['55P03']);

    // Fresh event objects with a stable eventId and occurredAt: the retry rebuilds the aggregate, never the context.
    expect($recorder->events)->toHaveCount(2)
        ->and($recorder->events[0])->not->toBe($recorder->events[1])
        ->and($recorder->distinctObjects())->toBe(2)
        ->and($recorder->eventIds)->toBe([$context->eventId, $context->eventId])
        ->and($recorder->occurredAt)->toBe([$context->occurredAt, $context->occurredAt]);

    expect($receipt->paymentId)->toBe($context->paymentId)
        ->and(IntegrationDatabase::observedCount('payment_idempotency_receipts'))->toBe(1)
        ->and(IntegrationDatabase::observedCount('stored_events'))->toBe(1)
        ->and(IntegrationDatabase::observedCount('payment_outbox'))->toBe(1);

    $receiptXmin = IntegrationDatabase::observedXmin('payment_idempotency_receipts');

    expect(IntegrationDatabase::observedXmin('stored_events'))->toBe($receiptXmin)
        ->and(IntegrationDatabase::observedXmin('payment_outbox'))->toBe($receiptXmin)
        ->and(DB::transactionLevel())->toBe(0);

    $stored = DB::table('stored_events')->where('event_class', PaymentInitiated::ALIAS)->sole();

    expect((int) $stored->aggregate_version)->toBe(1);
});

it('exhausts at three attempts and leaves no partial state', function (): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    [$recorder, $probe] = installContentionRig(failures: PHP_INT_MAX);

    $thrown = null;

    try {
        resolve(InitiatePayment::class)($context);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(IdempotencyContention::class)
        ->and($thrown?->getMessage())->toContain('55P03')
        ->and($thrown?->getMessage())->toContain('3 attempts');

    expect($probe->sqlStates)->toBe(['55P03', '55P03', '55P03'])
        ->and($recorder->events)->toHaveCount(3)
        ->and($recorder->distinctObjects())->toBe(3);

    expect(IntegrationDatabase::observedCount('payment_idempotency_receipts'))->toBe(0)
        ->and(IntegrationDatabase::observedCount('stored_events'))->toBe(0)
        ->and(IntegrationDatabase::observedCount('payment_outbox'))->toBe(0)
        ->and(IntegrationDatabase::observedCount('merchants'))->toBe(2)
        ->and(DB::transactionLevel())->toBe(0);
});

it('explains a truncation it cannot lock, and gives the bound back afterwards', function (): void {
    $merchant = Merchant::factory()->create();

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->select('select id from merchants where id = ? for update', [$merchant->getKey()]);

    $before = (string) DB::selectOne('show lock_timeout')->lock_timeout;

    expect(fn () => IntegrationDatabase::truncate('250ms'))
        ->toThrow(RuntimeException::class, 'Could not truncate the Integration tables within 250ms');

    expect((string) DB::selectOne('show lock_timeout')->lock_timeout)
        ->toBe($before)
        ->not->toBe('250ms');

    expect(IntegrationDatabase::observedCount('merchants'))->toBe(1);
});

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

    expect($thrown)->toBeInstanceOf(ProjectionRebuilding::class);

    expect(IntegrationDatabase::observedCount('payment_idempotency_receipts'))->toBe(0)
        ->and(IntegrationDatabase::observedCount('stored_events'))->toBe(0)
        ->and(IntegrationDatabase::observedCount('payment_outbox'))->toBe(0)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    initiationReopenGate();

    $receipt = resolve(InitiatePayment::class)($context);
    expect($receipt->paymentId)->toBe($context->paymentId);
});

it('holds the gate lock on its own connection while initiation is refused by the control row', function (): void {
    $merchant = Merchant::factory()->create();
    $context = Initiations::context($merchant->getKey());

    initiationCloseGate();

    $lockDuringTransaction = [];

    // DB::listen(), not TransactionCommitting: the refused transaction rolls back and never commits.
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

    expect($lockDuringTransaction)->toHaveCount(1)
        ->and($lockDuringTransaction[0]['mode'])->toBe('ShareLock')
        ->and($lockDuringTransaction[0]['granted'])->toBeTrue();

    expect(ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID))
        ->toBe([]);

    initiationReopenGate();
});
