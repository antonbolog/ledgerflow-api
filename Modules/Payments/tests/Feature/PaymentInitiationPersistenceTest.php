<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Domain\Payment\Exceptions\InvalidPaymentInitiation;
use Modules\Payments\Domain\Payment\Exceptions\PaymentAlreadyInitiated;
use Modules\Payments\Domain\Payment\PaymentAggregate;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;
use Symfony\Component\Finder\Finder;

function persistOnePayment(
    ?string $paymentId = null,
    string $occurredAt = '2026-08-14T10:11:12.123456+00:00',
): PaymentInitiated {
    $paymentId ??= (string) Str::uuid7();
    $merchantId = Merchant::factory()->create()->getKey();

    $aggregate = PaymentAggregate::retrieve($paymentId)->initiate(
        eventId: (string) Str::uuid7(),
        paymentId: $paymentId,
        merchantId: $merchantId,
        amountMinor: 1_250,
        currency: 'USD',
        occurredAt: $occurredAt,
    );

    // Before persist(), which clears the recorded events.
    $event = $aggregate->getRecordedEvents()[0];

    DB::transaction(fn () => $aggregate->persist());

    return $event;
}

it('pins catch_exceptions to a literal false in the application file itself', function (): void {
    // The file itself, not config(): the vendor default is also false, so a removed key would still read false.
    $path = base_path('config/event-sourcing.php');

    $declared = require $path;

    expect($declared)->toHaveKey('catch_exceptions')
        ->and($declared['catch_exceptions'])->toBeFalse();

    // env('...', false) also reads false while unset, so env() calls are found by token scan, ignoring comments.
    $tokens = token_get_all((string) file_get_contents($path));
    $envCalls = 0;

    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'env') {
            continue;
        }

        for ($i = $index + 1; $i < count($tokens); $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($tokens[$i] === '(') {
                $envCalls++;
            }

            break;
        }
    }

    expect($envCalls)->toBe(0, 'config/event-sourcing.php must not delegate any value to env().');
});

it('maps every production storable event, discovered rather than listed', function (): void {
    $discovered = [];

    foreach (Finder::create()->files()->name('*.php')->in(base_path('Modules'))->path('app/') as $file) {
        preg_match('/^namespace\s+(.+?);/m', (string) $file->getContents(), $namespace);

        if ($namespace === []) {
            continue;
        }

        $class = $namespace[1].'\\'.$file->getBasename('.php');

        if (class_exists($class) && is_subclass_of($class, ShouldBeStored::class)) {
            $discovered[] = $class;
        }
    }

    expect($discovered)->toContain(PaymentInitiated::class);

    expect($discovered)->toEqualCanonicalizing(array_values(config('event-sourcing.event_class_map')));
});

it('reads the event back out of the store unchanged', function (): void {
    $original = persistOnePayment();

    // A fresh query: EloquentStoredEvent returns its cached originalEvent when it has one.
    $event = EloquentStoredEvent::query()
        ->where('event_class', PaymentInitiated::ALIAS)
        ->sole()
        ->toStoredEvent()
        ->event;

    expect($event)->toBeInstanceOf(PaymentInitiated::class);

    expect($event)->not->toBe($original);

    expect($event->eventId)->toBe($original->eventId)
        ->and($event->paymentId)->toBe($original->paymentId)
        ->and($event->merchantId)->toBe($original->merchantId)
        ->and($event->amountMinor)->toBe($original->amountMinor)
        ->and($event->currency)->toBe($original->currency)
        ->and($event->occurredAt)->toBe($original->occurredAt);
});

it('restores initiated state when the aggregate is replayed from the event store', function (): void {
    $paymentId = (string) Str::uuid7();
    $merchantId = Merchant::factory()->create()->getKey();

    persistOnePayment(paymentId: $paymentId);

    $replayed = PaymentAggregate::retrieve($paymentId);

    expect(fn () => $replayed->initiate(
        eventId: (string) Str::uuid7(),
        paymentId: $paymentId,
        merchantId: $merchantId,
        amountMinor: 500,
        currency: 'USD',
        occurredAt: '2026-08-14T11:00:00.000000+00:00',
    ))->toThrow(PaymentAlreadyInitiated::class);
});

it('refuses a paymentId that disagrees with the bound aggregate uuid', function (): void {
    $paymentId = (string) Str::uuid7();

    expect(fn () => PaymentAggregate::retrieve($paymentId)->initiate(
        eventId: (string) Str::uuid7(),
        paymentId: (string) Str::uuid7(),
        merchantId: Merchant::factory()->create()->getKey(),
        amountMinor: 1_250,
        currency: 'USD',
        occurredAt: '2026-08-14T10:11:12.123456+00:00',
    ))->toThrow(InvalidPaymentInitiation::class);
});
