<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Payments\Tests\Spike\SpikeCommandContext;
use Modules\Payments\Tests\Spike\SpikeDatabase;
use Modules\Payments\Tests\Spike\SpikeFailureSwitch;
use Modules\Payments\Tests\Spike\SpikeInitiationHandler;
use Modules\Payments\Tests\Spike\SpikeOutboxWriter;
use Modules\Payments\Tests\Spike\SpikeProbeLog;
use Spatie\EventSourcing\Facades\Projectionist;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/*
|--------------------------------------------------------------------------
| Harness regressions
|--------------------------------------------------------------------------
|
| These do not prove anything about Spatie or PostgreSQL. They prove the
| harness cannot lie: that a broken teardown is survivable and visible, and
| that data left behind by an earlier test cannot make a write attempt pass.
|
| Both defects were real. The receipt reservation used a key hash shared by
| every context and ignored insertOrIgnore()'s return, so a surviving row
| satisfied the next test's reservation and the write "succeeded" having
| reserved nothing.
|
*/

beforeEach(function (): void {
    SpikeDatabase::configure();
    SpikeDatabase::useSpikeModels();
    SpikeDatabase::ensureSchema();

    app()->singleton(SpikeProbeLog::class);
    app()->singleton(SpikeFailureSwitch::class);
    Projectionist::addProjector(resolve(SpikeOutboxWriter::class));
});

afterEach(function (): void {
    SpikeDatabase::teardown();
});

it('gives every context a unique 64 character key hash', function (): void {
    $first = SpikeCommandContext::make();
    $second = SpikeCommandContext::make();

    expect(strlen($first->keyHash))->toBe(64)
        ->and($first->keyHash)->not->toBe($second->keyHash)
        // Stable within one context, structurally rather than by convention: a
        // retry reuses the object, and the spec requires the fingerprint to be
        // immutable command data. A readonly class cannot recompute it.
        ->and(new ReflectionClass(SpikeCommandContext::class)->isReadOnly())->toBeTrue();
});

it('refuses to reserve a receipt when a stale row already holds the key', function (): void {
    $context = SpikeCommandContext::make();
    $spike = DB::connection(SpikeDatabase::CONNECTION);

    // Exactly what a failed teardown leaves behind.
    $spike->table('spike_payment_receipts')->insert([
        'merchant_id' => $context->merchantId,
        'idempotency_key_hash' => $context->keyHash,
    ]);

    expect(fn () => $spike->transaction(
        fn () => resolve(SpikeInitiationHandler::class)($context),
        1,
    ))->toThrow(RuntimeException::class, 'Receipt reservation inserted 0 rows');

    // The attempt left no durable state: the stale row is still the only row,
    // and nothing downstream of the reservation ran.
    expect($spike->table('spike_payment_receipts')->count())->toBe(1)
        ->and($spike->table('stored_events')->count())->toBe(0)
        ->and($spike->table('spike_payment_outbox')->count())->toBe(0)
        ->and(resolve(SpikeProbeLog::class)->markers())->toBe([]);
});

it('tears down cleanly when both connections are mid-transaction and the schema is incomplete', function (): void {
    $spike = DB::connection(SpikeDatabase::CONNECTION);
    $lock = DB::connection(SpikeDatabase::LOCK_CONNECTION);
    $context = SpikeCommandContext::make();

    // A committed row, which only cleanup's DELETE can remove.
    $spike->table('spike_payment_receipts')->insert([
        'merchant_id' => $context->merchantId,
        'idempotency_key_hash' => $context->keyHash,
    ]);

    // Both connections left mid-transaction, exactly as a test failing between
    // beginTransaction() and commit() would leave them.
    $spike->beginTransaction();
    $spike->table('spike_payment_receipts')->insert([
        'merchant_id' => $context->merchantId + 1,
        'idempotency_key_hash' => hash('sha256', 'uncommitted'),
    ]);
    $lock->beginTransaction();
    $lock->selectOne('select 1 as ok');

    // And a partially created schema, which a naive cleanup would abort on
    // before reaching the tables that do exist.
    Schema::connection(SpikeDatabase::CONNECTION)->drop('spike_lock_probe');

    SpikeDatabase::teardown();

    // Every step still ran: both transactions rolled back, the committed row
    // deleted despite the missing table, the models restored, and both
    // connections purged.
    expect(DB::connection(SpikeDatabase::CONNECTION)->transactionLevel())->toBe(0)
        ->and(DB::connection(SpikeDatabase::LOCK_CONNECTION)->transactionLevel())->toBe(0)
        ->and(DB::connection(SpikeDatabase::CONNECTION)->table('spike_payment_receipts')->count())->toBe(0)
        ->and(config('event-sourcing.stored_event_model'))->toBe(EloquentStoredEvent::class);

    // Idempotent: afterEach is about to call it again on this same state.
    SpikeDatabase::teardown();

    // ensureSchema() in the next test restores the dropped table, which is why
    // dropping it here is safe rather than destructive.
    SpikeDatabase::ensureSchema();
    expect(Schema::connection(SpikeDatabase::CONNECTION)->hasTable('spike_lock_probe'))->toBeTrue();
});
