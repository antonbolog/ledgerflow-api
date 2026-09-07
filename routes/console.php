<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Registration only. The command bounds its own batch/start-budget, so this
// invocation is inert until something actually runs `schedule:work` - which a
// normal `make install` or `make start` never does. The scheduler and its
// Horizon-supervised consumer are activated together by `make projection-start`;
// see docs/specifications/payments/outbox-delivery.md.
Schedule::command('payments:outbox:relay')->everySecond();
