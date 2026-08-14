<?php

declare(strict_types=1);

use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Domain\Payment\Exceptions\InvalidPaymentInitiation;

/*
|--------------------------------------------------------------------------
| PaymentInitiated
|--------------------------------------------------------------------------
|
| Framework-free on purpose. tests/Pest.php binds TestCase and RefreshDatabase
| to Feature and Architecture only, so nothing here boots Laravel or touches
| PostgreSQL. That is the point: these are the invariants that hold without any
| infrastructure at all.
|
*/

function paymentInitiated(mixed ...$overrides): PaymentInitiated
{
    return new PaymentInitiated(...array_merge([
        'eventId' => '0197c9b0-1111-7000-8000-00000000aaaa',
        'paymentId' => '0197c9b0-2222-7000-9000-00000000bbbb',
        'merchantId' => 1,
        'amountMinor' => 1_250,
        'currency' => 'USD',
        'occurredAt' => '2026-08-14T10:11:12.123456+00:00',
    ], $overrides));
}

it('carries the six business fields and schema version 1', function (): void {
    $event = paymentInitiated();

    expect($event->eventId)->toBe('0197c9b0-1111-7000-8000-00000000aaaa')
        ->and($event->paymentId)->toBe('0197c9b0-2222-7000-9000-00000000bbbb')
        ->and($event->merchantId)->toBe(1)
        ->and($event->amountMinor)->toBe(1_250)
        ->and($event->currency)->toBe('USD')
        ->and($event->occurredAt)->toBe('2026-08-14T10:11:12.123456+00:00')
        ->and($event->eventVersion())->toBe(1);
});

it('accepts the documented amount boundaries', function (int $amount): void {
    expect(paymentInitiated(amountMinor: $amount)->amountMinor)->toBe($amount);
})->with([1, 100_000_000]);

it('refuses an amount outside the documented range', function (int $amount): void {
    expect(fn () => paymentInitiated(amountMinor: $amount))
        ->toThrow(InvalidPaymentInitiation::class);
})->with([0, 100_000_001]);

it('accepts only canonical uppercase USD', function (string $currency): void {
    // Canonicalisation is T6's HTTP concern. The domain takes the canonical form
    // only, so a handler that forgets to canonicalise fails here instead of
    // storing 'usd' in an immutable event.
    expect(fn () => paymentInitiated(currency: $currency))
        ->toThrow(InvalidPaymentInitiation::class);
})->with(['usd', 'EUR']);

it('refuses a paymentId that is not a canonical lowercase UUIDv7', function (string $id): void {
    expect(fn () => paymentInitiated(paymentId: $id))
        ->toThrow(InvalidPaymentInitiation::class);
})->with([
    '0197C9B0-2222-7000-9000-00000000BBBB',   // uppercase
    '0197c9b0-2222-4000-9000-00000000bbbb',   // version 4
    // A $-anchored pattern accepts this: PCRE's $ matches before a terminal
    // newline. The value would reach stored_events.aggregate_uuid with the
    // newline attached.
    "0197c9b0-2222-7000-9000-00000000bbbb\n",
]);

it('refuses an eventId that is not a canonical lowercase UUIDv7', function (string $id): void {
    // Its own test rather than another row on the paymentId dataset: the two
    // identifiers are separate constructor guards, and covering only one leaves
    // the other deletable with the suite still green.
    expect(fn () => paymentInitiated(eventId: $id))
        ->toThrow(InvalidPaymentInitiation::class);
})->with([
    '0197C9B0-1111-7000-8000-00000000AAAA',   // uppercase
    '0197c9b0-1111-4000-8000-00000000aaaa',   // version 4
    "0197c9b0-1111-7000-8000-00000000aaaa\n", // terminal newline; see the paymentId case
]);

it('refuses a non-positive merchant id', function (int $merchantId): void {
    // merchant_id is a NOT NULL bigint foreign key in T5's schema, so a zero or
    // negative value is nonsense the database would reject later and the domain
    // should reject now.
    expect(fn () => paymentInitiated(merchantId: $merchantId))
        ->toThrow(InvalidPaymentInitiation::class);
})->with([0, -1]);

it('refuses an occurrence time that is not canonical UTC microseconds', function (string $occurredAt): void {
    expect(fn () => paymentInitiated(occurredAt: $occurredAt))
        ->toThrow(InvalidPaymentInitiation::class);
})->with([
    '2026-08-14T10:11:12+00:00',              // no microseconds
    '2026-08-14T10:11:12.123456+02:00',       // not UTC
    '2026-02-30T10:11:12.123456+00:00',       // overflowing date the round-trip guard catches
]);
