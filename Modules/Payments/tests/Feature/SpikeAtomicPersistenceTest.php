<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\Payments\Tests\Spike\SpikeCommandContext;
use Modules\Payments\Tests\Spike\SpikeDatabase;
use Modules\Payments\Tests\Spike\SpikeFailureSwitch;
use Modules\Payments\Tests\Spike\SpikeInitiationHandler;
use Modules\Payments\Tests\Spike\SpikeOutboxWriter;
use Modules\Payments\Tests\Spike\SpikeProbeLog;
use Modules\Payments\Tests\Spike\SpikeStoredEvent;
use Spatie\EventSourcing\Facades\Projectionist;

/*
|--------------------------------------------------------------------------
| T3 Q1 and Q3 — atomic persistence and synchronous handling
|--------------------------------------------------------------------------
|
| Q1  receipt + stored event + synchronous outbox share one PostgreSQL
|     transaction and one backend connection
| Q3  the synchronous handler finishes BEFORE persistence returns
|
| These are compatibility assertions about Laravel 13.24.0, Spatie
| laravel-event-sourcing 7.15.1 and PostgreSQL 18.4. A failure here is a
| blocking finding for the Payments slice, not a flaky test.
|
*/

beforeEach(function (): void {
    SpikeDatabase::configure();

    // BEFORE anything can resolve a stored-event repository.
    SpikeDatabase::useSpikeModels();

    // --- named-connection guard -------------------------------------------
    // Fails loudly rather than silently degrading to the default connection,
    // where writes would sit inside RefreshDatabase's wrapper transaction and
    // could never be observed committing.
    expect(config('event-sourcing.stored_event_model'))->toBe(SpikeStoredEvent::class)
        ->and((new SpikeStoredEvent)->getConnectionName())->toBe(SpikeDatabase::CONNECTION)
        ->and((new SpikeStoredEvent)->getTable())->toBe('stored_events');

    // --- catch_exceptions CONFIGURATION guard -----------------------------
    // This asserts the configured value, nothing more. Projectionist copies
    // catch_exceptions into $this->catchExceptions when the singleton is built,
    // so once it has been resolved this assertion says nothing about the flag
    // that instance is actually carrying.
    //
    // It is still worth keeping: EVENT_PROJECTOR_CATCH_EXCEPTIONS set to true in
    // an environment would make Projectionist swallow a throwing handler, and
    // catching that here points at the cause immediately.
    //
    // The BEHAVIOURAL proof is Task 3's, where a throwing synchronous projector
    // must propagate and roll back the receipt, the stored event and the outbox
    // row together. Only that test can prove what the live instance does.
    expect(config('event-sourcing.catch_exceptions'))->toBeFalse();

    // --- connection topology guard ----------------------------------------
    expect(DB::connection(SpikeDatabase::CONNECTION)->selectOne('select current_database() as d')->d)->toBe('ledgerflow_test')
        ->and(DB::connection(SpikeDatabase::LOCK_CONNECTION)->selectOne('select current_database() as d')->d)->toBe('ledgerflow_test')
        ->and(DB::connection(SpikeDatabase::CONNECTION)->transactionLevel())->toBe(0)
        ->and(DB::connection(SpikeDatabase::LOCK_CONNECTION)->transactionLevel())->toBe(0)
        ->and(DB::connection()->transactionLevel())->toBe(1)
        ->and(DB::selectOne("select current_setting('transaction_isolation') as i")->i)->toBe('read committed');

    SpikeDatabase::ensureSchema();

    // --- schema routing guard ---------------------------------------------
    // If the copied migrations had run on the wrapped default connection the
    // tables would be invisible here, and every later failure would be a
    // confusing "relation does not exist" instead.
    expect(Schema::connection(SpikeDatabase::CONNECTION)->hasTable('stored_events'))->toBeTrue()
        ->and(Schema::connection(SpikeDatabase::CONNECTION)->hasTable('snapshots'))->toBeTrue()
        ->and(Schema::connection(SpikeDatabase::CONNECTION)->hasTable('spike_payment_receipts'))->toBeTrue()
        ->and(Schema::connection(SpikeDatabase::CONNECTION)->hasTable('spike_payment_outbox'))->toBeTrue();

    // One fresh probe log and one fresh projector per test application. Spatie
    // re-resolves the handler through the container at dispatch, so the log has
    // to be a singleton for the writer and the test to share it.
    app()->singleton(SpikeProbeLog::class);
    // Disarmed by default, so these tests exercise the success path. Task 3 arms
    // it to prove a throwing handler unwinds every write.
    app()->singleton(SpikeFailureSwitch::class);
    Projectionist::addProjector(resolve(SpikeOutboxWriter::class));
});

afterEach(function (): void {
    // One call, so the ordering and the failure-safety live with the harness
    // rather than being re-stated (and eventually mis-stated) per test file.
    SpikeDatabase::teardown();
});

it('commits one receipt, one stored event and one outbox row', function (): void {
    $context = SpikeCommandContext::make();

    DB::connection(SpikeDatabase::CONNECTION)->transaction(
        fn () => resolve(SpikeInitiationHandler::class)($context),
        1,
    );

    // Asserted from the OBSERVER connection, never from the writer's.
    expect(DB::table('spike_payment_receipts')->count())->toBe(1)
        ->and(DB::table('stored_events')->count())->toBe(1)
        ->and(DB::table('spike_payment_outbox')->count())->toBe(1)
        ->and(DB::table('stored_events')->value('aggregate_uuid'))->toBe($context->paymentId)
        ->and(DB::table('stored_events')->value('aggregate_version'))->toBe(1)
        ->and(DB::table('spike_payment_outbox')->value('event_id'))->toBe($context->eventId);
});

it('writes all three inside one transaction on one backend', function (): void {
    $context = SpikeCommandContext::make();
    $spike = DB::connection(SpikeDatabase::CONNECTION);
    $observer = DB::connection();

    // Manual begin/commit rather than transaction(), so the mid-flight
    // assertions below can run while the transaction is still open. Same
    // top-level transaction either way.
    $spike->beginTransaction();

    resolve(SpikeInitiationHandler::class)($context);

    // The writer's own session sees all three rows...
    expect($spike->table('spike_payment_receipts')->count())->toBe(1)
        ->and($spike->table('stored_events')->count())->toBe(1)
        ->and($spike->table('spike_payment_outbox')->count())->toBe(1);

    // ...and the observer sees none of them, because they are uncommitted.
    // Uncommitted rows are visible only to the session that wrote them, so this
    // pair is direct proof that Spatie's INSERT used the spike connection.
    expect($observer->table('spike_payment_receipts')->count())->toBe(0)
        ->and($observer->table('stored_events')->count())->toBe(0)
        ->and($observer->table('spike_payment_outbox')->count())->toBe(0);

    $spike->commit();

    expect($observer->table('spike_payment_receipts')->count())->toBe(1)
        ->and($observer->table('stored_events')->count())->toBe(1)
        ->and($observer->table('spike_payment_outbox')->count())->toBe(1);

    // PRIMARY Q1 EVIDENCE. xmin is the transaction id PostgreSQL recorded against
    // each tuple. The receipt is written by our own insertOrIgnore on the spike
    // connection, so equality is the database's own statement that Spatie's write
    // and the handler's write joined that same transaction.
    //
    // Holds despite EloquentStoredEventRepository::persist() calling save() twice
    // (INSERT then UPDATE): an in-transaction UPDATE keeps the inserting
    // transaction's xid.
    $xmins = [
        SpikeDatabase::xmin('spike_payment_receipts'),
        SpikeDatabase::xmin('stored_events'),
        SpikeDatabase::xmin('spike_payment_outbox'),
    ];

    expect(array_unique($xmins))->toHaveCount(1);

    $log = resolve(SpikeProbeLog::class);

    // One backend for every probe, and it is the spike connection's backend,
    // not the observer's.
    expect(array_unique($log->backendPids()))->toHaveCount(1)
        ->and($log->backendPids()[0])->not->toBe((int) $observer->selectOne('select pg_backend_pid() as pid')->pid);

    // AggregateRoot::persist() opens no transaction of its own, so the expected
    // depth is exactly 1 throughout. A 2 would mean the package started nesting
    // and is a finding to record, not a failure to paper over.
    expect(array_unique($log->transactionLevels()))->toBe([1]);
});

it('runs the outbox handler synchronously and never enqueues it', function (): void {
    Queue::fake();

    $context = SpikeCommandContext::make();
    $spike = DB::connection(SpikeDatabase::CONNECTION);

    $spike->transaction(function () use ($context, $spike): void {
        resolve(SpikeInitiationHandler::class)($context);

        // The outbox row exists on the line after persist() returned, inside the
        // same open transaction.
        expect($spike->table('spike_payment_outbox')->where('event_id', $context->eventId)->exists())->toBeTrue();
    }, 1);

    // The handler ran BEFORE persist() returned.
    expect(resolve(SpikeProbeLog::class)->markers())
        ->toBe(['receipt', 'objects-built', 'outbox-handler', 'after-persist']);

    // Without this the ordering assertion would pass for a QUEUED handler too:
    // phpunit.xml forces QUEUE_CONNECTION=sync, under which a queued job still
    // executes inline at dispatch. assertNothingPushed is what separates
    // "synchronous" from "queued onto a driver that happens to run inline".
    Queue::assertNothingPushed();
});
