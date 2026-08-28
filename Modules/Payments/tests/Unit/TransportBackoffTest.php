<?php

declare(strict_types=1);

use Modules\Payments\Infrastructure\Relay\TransportBackoff;

/*
|--------------------------------------------------------------------------
| Transport backoff
|--------------------------------------------------------------------------
|
| The delay a rejected publication puts on available_at. Pure arithmetic, so it
| is proved without Redis, PostgreSQL or a clock.
|
| The argument is the value consecutive_transport_failures will HOLD after the
| increment, not the value it held before. The relay reads the locked row, adds
| one in SQL, and asks for the delay that matches the new count — so the first
| rejection asks for secondsAfter(1).
|
*/

it('doubles per consecutive rejection until it reaches the cap', function (int $failures, int $expected): void {
    expect(new TransportBackoff(baseSeconds: 1, maxSeconds: 300)->secondsAfter($failures))->toBe($expected);
})->with([
    'first' => [1, 1],
    'second' => [2, 2],
    'third' => [3, 4],
    'fourth' => [4, 8],
    'fifth' => [5, 16],
    'sixth' => [6, 32],
    'seventh' => [7, 64],
    'eighth' => [8, 128],
    'ninth' => [9, 256],
    'tenth is the first capped step' => [10, 300],
    'eleventh stays capped' => [11, 300],
]);

it('stays capped and finite for a count no outage could reach', function (): void {
    // An arbitrarily long transport outage must never set exhausted_at, so this
    // counter is genuinely unbounded and 2 ** ($n - 1) would overflow into a float
    // — and (int) of a float above PHP_INT_MAX is undefined. The exponent is
    // clamped instead, so a corrupted or very old counter yields the cap rather
    // than a negative interval PostgreSQL would happily accept.
    $backoff = new TransportBackoff(baseSeconds: 1, maxSeconds: 300);

    foreach ([64, 1_000, PHP_INT_MAX] as $absurd) {
        expect($backoff->secondsAfter($absurd))->toBe(300);
    }
});

it('honours a base larger than one and never exceeds its own cap', function (): void {
    $backoff = new TransportBackoff(baseSeconds: 5, maxSeconds: 12);

    expect($backoff->secondsAfter(1))->toBe(5)
        ->and($backoff->secondsAfter(2))->toBe(10)
        // 20 would be the doubling; the cap wins.
        ->and($backoff->secondsAfter(3))->toBe(12);
});

it('treats a count below the first failure as the first failure', function (int $count): void {
    // Defensive rather than expected: the relay always passes at least 1. A zero or
    // negative reaching here would otherwise compute 2 ** -1 and produce a
    // sub-second float that silently rounds to a zero-second backoff, turning a
    // dead transport into a hot loop.
    expect(new TransportBackoff(baseSeconds: 1, maxSeconds: 300)->secondsAfter($count))->toBe(1);
})->with([0, -1, PHP_INT_MIN]);

it('refuses a configuration that could not produce a usable delay', function (int $base, int $max): void {
    expect(fn () => new TransportBackoff(baseSeconds: $base, maxSeconds: $max))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'zero base' => [0, 300],
    'negative base' => [-1, 300],
    'zero cap' => [1, 0],
    'cap below base' => [10, 5],
]);
