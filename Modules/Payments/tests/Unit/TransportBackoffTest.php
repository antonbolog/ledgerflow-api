<?php

declare(strict_types=1);

use Modules\Payments\Infrastructure\Relay\TransportBackoff;

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
    $backoff = new TransportBackoff(baseSeconds: 1, maxSeconds: 300);

    foreach ([64, 1_000, PHP_INT_MAX] as $absurd) {
        expect($backoff->secondsAfter($absurd))->toBe(300);
    }
});

it('honours a base larger than one and never exceeds its own cap', function (): void {
    $backoff = new TransportBackoff(baseSeconds: 5, maxSeconds: 12);

    expect($backoff->secondsAfter(1))->toBe(5)
        ->and($backoff->secondsAfter(2))->toBe(10)
        ->and($backoff->secondsAfter(3))->toBe(12);
});

it('treats a count below the first failure as the first failure', function (int $count): void {
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
