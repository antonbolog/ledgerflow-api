<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Collection;

it('schedules the Passport purge once a day and nothing depends on it having run', function (): void {
    $events = Collection::make(resolve(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'passport:purge'));

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event->getExpression())->toBe('0 0 * * *')
        ->and($event->isRepeatable())->toBeFalse();
});

it('registers the purge command Passport ships', function (): void {
    expect(array_keys(resolve(Kernel::class)->all()))
        ->toContain('passport:purge');
});
