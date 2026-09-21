<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('payments:outbox:relay')->everySecond();

// Bookkeeping only. Validation reads `revoked` on every request, so a token that outlives a missed
// run is already refused; nothing about authentication depends on this having run.
Schedule::command('passport:purge')->daily();
