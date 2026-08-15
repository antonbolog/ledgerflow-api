<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Infrastructure\Projectors\OutboxEnvelopeNotDerivable;
use Modules\Payments\Infrastructure\Projectors\PaymentOutboxWriter;
use Modules\Payments\Tests\Support\Initiation;
use Spatie\EventSourcing\Projectionist;
use Throwable;

/*
|--------------------------------------------------------------------------
| PaymentOutboxWriter — the replay path
|--------------------------------------------------------------------------
|
| WHAT THIS FILE PROVES: that a standard Spatie replay of this projector refuses
| loudly and writes nothing, rather than quietly re-creating outbox envelopes.
|
| WHY IT CANNOT BE LEFT TO THE storedEventId() GUARD: that guard does not fire on
| a replay. EloquentStoredEventRepository::persist() writes `stored-event-id` into
| meta_data (`:132`), and StoredEvent::instantiateEvent() restores the whole
| meta_data onto the reconstituted event (`setMetaData()`), so a REPLAYED event
| carries a NON-NULL storedEventId() and its stored_events row is right there to
| read. Both existing guards pass, and `event-sourcing:replay` re-creates every
| envelope it can — or, where one already exists, dies incidentally on
| payment_outbox's unique constraint, reporting the wrong problem.
|
| Replay is therefore a condition only Projectionist::isReplaying() can report,
| and T6 refuses it explicitly. Whether a rebuild re-creates envelopes stays T11's
| decision to make deliberately.
|
*/

it('refuses to write an envelope during a replay and leaves the outbox empty', function (): void {
    Initiation::send(merchant: Merchant::factory()->create())->assertStatus(202);

    // The live envelope is REMOVED so that "the outbox is empty" is a statement
    // about the replay. Left in place, the unique constraint — not the guard —
    // would be what stopped the second write, and this test would pass against a
    // writer that has no replay guard at all.
    DB::table('payment_outbox')->delete();

    // Non-vacuous precondition: there is exactly one event for the replay to find,
    // so a green run cannot mean "the replay iterated over nothing".
    expect(DB::table('stored_events')->where('event_class', PaymentInitiated::ALIAS)->count())->toBe(1)
        ->and(DB::table('payment_outbox')->count())->toBe(0);

    // The REAL entry point, not a hand-set flag: Projectionist::replay() is what
    // `event-sourcing:replay` calls, and it is the only thing that sets
    // isReplaying(). catch_exceptions is false, so the refusal propagates here
    // instead of being swallowed into handleException().
    $projectionist = $this->app->make(Projectionist::class);
    $writer = $this->app->make(PaymentOutboxWriter::class);

    $thrown = null;

    try {
        $projectionist->replay(collect([$writer]));
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(OutboxEnvelopeNotDerivable::class)
        // The DEDICATED path, not the null-id or missing-row one: those describe a
        // different defect and would send a maintainer looking for a missing row.
        ->and($thrown?->getMessage())->toContain('replay')
        ->and(DB::table('payment_outbox')->count())->toBe(0);
});
