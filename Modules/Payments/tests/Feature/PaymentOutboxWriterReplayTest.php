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

it('refuses to write an envelope during a replay and leaves the outbox empty', function (): void {
    Initiation::send(merchant: Merchant::factory()->create())->assertStatus(202);

    // Removed so the unique constraint cannot mask a missing replay guard.
    DB::table('payment_outbox')->delete();

    expect(DB::table('stored_events')->where('event_class', PaymentInitiated::ALIAS)->count())->toBe(1)
        ->and(DB::table('payment_outbox')->count())->toBe(0);

    $projectionist = $this->app->make(Projectionist::class);
    $writer = $this->app->make(PaymentOutboxWriter::class);

    $thrown = null;

    try {
        $projectionist->replay(collect([$writer]));
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(OutboxEnvelopeNotDerivable::class)
        ->and($thrown?->getMessage())->toContain('replay')
        ->and(DB::table('payment_outbox')->count())->toBe(0);
});
