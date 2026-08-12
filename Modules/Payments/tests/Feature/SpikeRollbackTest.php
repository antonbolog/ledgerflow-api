<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Payments\Tests\Spike\SpikeCommandContext;
use Modules\Payments\Tests\Spike\SpikeDatabase;
use Modules\Payments\Tests\Spike\SpikeFailureSwitch;
use Modules\Payments\Tests\Spike\SpikeInitiationHandler;
use Modules\Payments\Tests\Spike\SpikeInjectedFailure;
use Modules\Payments\Tests\Spike\SpikeOutboxWriter;
use Modules\Payments\Tests\Spike\SpikeProbeLog;
use Spatie\EventSourcing\Facades\Projectionist;

/*
|--------------------------------------------------------------------------
| T3 Q2 — a forced failure rolls back all three writes
|--------------------------------------------------------------------------
|
| Two materially distinct boundaries:
|
|   1. AFTER persist() returns, before the outer transaction commits. The
|      ordinary case: the command handler decides to abort once every write
|      has already happened.
|
|   2. FROM INSIDE the synchronous projector, after the stored event and the
|      outbox row exist. This one cannot be replaced by the first: it is the
|      only test that proves the LIVE Projectionist does not swallow a handler
|      exception. The catch_exceptions config assertion in
|      SpikeAtomicPersistenceTest cannot prove that, because Projectionist
|      copies the flag into $this->catchExceptions when the singleton is built
|      and the config value says nothing about the already-resolved instance.
|
| A failure here is a blocking finding for the Payments slice: it would mean
| the spec's atomic write cannot be built on this package.
|
*/

beforeEach(function (): void {
    SpikeDatabase::configure();
    SpikeDatabase::useSpikeModels();
    SpikeDatabase::ensureSchema();

    // Fresh per test, and singletons so the container-rebuilt writer receives
    // the same instances this test holds.
    app()->singleton(SpikeProbeLog::class);
    app()->singleton(SpikeFailureSwitch::class);

    Projectionist::addProjector(resolve(SpikeOutboxWriter::class));
});

afterEach(function (): void {
    SpikeDatabase::teardown();
});

it('rolls back receipt, stored event and outbox when the failure comes after persist returns', function (): void {
    $context = SpikeCommandContext::make();
    $spike = DB::connection(SpikeDatabase::CONNECTION);

    expect(fn () => $spike->transaction(function () use ($context): void {
        resolve(SpikeInitiationHandler::class)($context);

        // Everything is written and nothing is committed yet.
        throw new SpikeInjectedFailure('Injected spike failure at: after persist returned');
    }, 1))->toThrow(SpikeInjectedFailure::class, 'after persist returned');

    // Nothing survives, seen from the OBSERVER connection.
    expect(DB::table('spike_payment_receipts')->count())->toBe(0)
        ->and(DB::table('stored_events')->count())->toBe(0)
        ->and(DB::table('spike_payment_outbox')->count())->toBe(0);

    // The writer's own connection agrees, and is no longer in a transaction.
    expect($spike->table('stored_events')->count())->toBe(0)
        ->and($spike->transactionLevel())->toBe(0);

    // Marker order places the failure after persist() had fully returned: all
    // four markers were recorded, including the handler's own.
    expect(resolve(SpikeProbeLog::class)->markers())
        ->toBe(['receipt', 'objects-built', 'outbox-handler', 'after-persist']);
});

it('rolls back receipt, stored event and outbox when the synchronous projector throws', function (): void {
    $context = SpikeCommandContext::make();
    $spike = DB::connection(SpikeDatabase::CONNECTION);
    $failure = resolve(SpikeFailureSwitch::class);

    $failure->arm();

    expect(fn () => $spike->transaction(
        fn () => resolve(SpikeInitiationHandler::class)($context),
        1,
    ))->toThrow(SpikeInjectedFailure::class, 'inside outbox writer');

    // THE BEHAVIOURAL PROOF: the exception escaped the live Projectionist rather
    // than being caught and logged. Had it been swallowed, persist() would have
    // returned normally, the transaction would have committed, and the three
    // counts below would be 1.
    expect(DB::table('spike_payment_receipts')->count())->toBe(0)
        ->and(DB::table('stored_events')->count())->toBe(0)
        ->and(DB::table('spike_payment_outbox')->count())->toBe(0);

    expect($spike->table('stored_events')->count())->toBe(0)
        ->and($spike->transactionLevel())->toBe(0);

    // Marker order places the failure INSIDE the writer: the handler recorded
    // itself, and 'after-persist' never ran because persist() never returned.
    expect(resolve(SpikeProbeLog::class)->markers())
        ->toBe(['receipt', 'objects-built', 'outbox-handler'])
        ->and($failure->trippedAt())->toBe('inside outbox writer, after the outbox row');
});
