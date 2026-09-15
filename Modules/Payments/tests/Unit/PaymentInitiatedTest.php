<?php

declare(strict_types=1);

use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Domain\Payment\Exceptions\InvalidPaymentInitiation;

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
    expect(fn () => paymentInitiated(currency: $currency))
        ->toThrow(InvalidPaymentInitiation::class);
})->with(['usd', 'EUR']);

it('refuses a paymentId that is not a canonical lowercase UUIDv7', function (string $id): void {
    expect(fn () => paymentInitiated(paymentId: $id))
        ->toThrow(InvalidPaymentInitiation::class);
})->with([
    'uppercase' => '0197C9B0-2222-7000-9000-00000000BBBB',
    'version 4' => '0197c9b0-2222-4000-9000-00000000bbbb',
    'terminal newline' => "0197c9b0-2222-7000-9000-00000000bbbb\n",
]);

it('refuses an eventId that is not a canonical lowercase UUIDv7', function (string $id): void {
    expect(fn () => paymentInitiated(eventId: $id))
        ->toThrow(InvalidPaymentInitiation::class);
})->with([
    'uppercase' => '0197C9B0-1111-7000-8000-00000000AAAA',
    'version 4' => '0197c9b0-1111-4000-8000-00000000aaaa',
    'terminal newline' => "0197c9b0-1111-7000-8000-00000000aaaa\n",
]);

it('refuses a non-positive merchant id', function (int $merchantId): void {
    expect(fn () => paymentInitiated(merchantId: $merchantId))
        ->toThrow(InvalidPaymentInitiation::class);
})->with([0, -1]);

it('refuses an occurrence time that is not canonical UTC microseconds', function (string $occurredAt): void {
    expect(fn () => paymentInitiated(occurredAt: $occurredAt))
        ->toThrow(InvalidPaymentInitiation::class);
})->with([
    'no microseconds' => '2026-08-14T10:11:12+00:00',
    'not UTC' => '2026-08-14T10:11:12.123456+02:00',
    'overflowing date' => '2026-02-30T10:11:12.123456+00:00',
]);
