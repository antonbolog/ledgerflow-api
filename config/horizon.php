<?php

declare(strict_types=1);

/**
 * Reduced from laravel/horizon v5.48.3's published stub to the surface this
 * project actually uses.
 *
 * WHAT WAS REMOVED, AND WHY EACH REMOVAL IS SAFE. Horizon reads every key below
 * through `config()` with its own fallback, so an absent key takes the package
 * default rather than breaking. The stub's `waits`, `trim`, `silenced`,
 * `silenced_tags`, `metrics`, `fast_termination`, `memory_limit` and `watch`
 * blocks are all left to those defaults: none of them is a decision this slice has
 * evidence for, and a copied block that merely restates a default is a value
 * someone will later assume was chosen.
 *
 * ONE SUPERVISOR, ONE QUEUE. `payments-projection` is the only logical queue this
 * project has, and Horizon must not be given `default`: the outbox relay publishes
 * nowhere else, and a supervisor watching a queue nothing writes to would report
 * healthy while consuming nothing.
 *
 * THE THREE ORDERED TIMEOUTS ARE NOT DECLARED HERE. `statement_timeout <
 * worker timeout < retry_after` is one correctness chain, and this file reads its
 * link from config/payments.php rather than restating it — a duplicate is exactly
 * how such a chain breaks with nothing failing.
 * Modules\Payments\Infrastructure\Projection\ProjectionConfiguration validates the
 * whole chain at container resolution.
 */
/*
| THE PROJECTION SETTINGS ARE `require`d, NOT READ WITH config().
|
| MEASURED, NOT ASSUMED: config() is not usable while configuration is still being
| loaded, and the loader walks config/ in alphabetical order — `horizon.php` is
| read BEFORE `payments.php`. Every config('payments.projection.*') call here
| returned null, which cast to 0 processes, 0 tries and a 0-second timeout: a
| supervisor Horizon would have refused to start (ProvisioningPlan::deploy() skips
| any supervisor whose maxProcesses is not above zero), silently consuming nothing.
| The Horizon supervision test is what caught it.
|
| Requiring the file is order-independent and keeps the single source of truth: a
| config file is a plain PHP array, and reading it directly is the only way to have
| one at load time.
*/
/** @var array{projection: array<string, mixed>} $payments */
$payments = require __DIR__.'/payments.php';

/** @var array{queue_connection: string, queue: string, processes: int, tries: int, worker_timeout_seconds: int} $projection */
$projection = $payments['projection'];

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | THE METADATA CONNECTION, DELIBERATELY NOT `payments`. Horizon stores its own
    | bookkeeping — job records, supervisor state, metrics — on this connection,
    | and it must never be the Payments transport:
    |
    | - `database.redis.payments` is tuned for the relay's locked transaction:
    |   0.25-second connect and read timeouts and `max_retries => 0`. Those bounds
    |   exist because publication happens inside an open PostgreSQL transaction
    |   holding a row lock. Horizon's long-lived bookkeeping reads would fail
    |   against them, and widening them to suit Horizon would silently remove the
    |   relay's database-occupancy control.
    |
    | - Horizon trims and expires its own keys on its own schedule. Sharing a
    |   logical database with the queue would put that housekeeping in the same
    |   keyspace as undelivered messages.
    |
    | `horizon` is its own connection on its own logical database, keeping the
    | existing partitioning: 0 default, 1 cache, 2 payments, 3 horizon.
    */

    'use' => 'horizon',

    'path' => env('HORIZON_PATH', 'horizon'),

    'domain' => env('HORIZON_DOMAIN'),

    /*
    | The package default, kept deliberately. Adding `auth` here would bind the
    | dashboard to a SESSION guard, and this application authenticates merchants
    | through Sanctum tokens with no web session at all — so `auth` would not
    | tighten access, it would make the dashboard unreachable for the local
    | operator it exists for, while looking like a security control. The real lock
    | is the gate below.
    */
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Dashboard access (READ BY App\Providers\HorizonServiceProvider)
    |--------------------------------------------------------------------------
    |
    | NOT A HORIZON KEY. laravel/horizon v5.48.3 has no `enabled` switch: its
    | routes are registered unconditionally by HorizonServiceProvider::boot(), and
    | the ONLY access control the package offers is `Horizon::check()`, which falls
    | back to `app()->environment('local')` when no gate is set.
    |
    | RELYING ON THAT FALLBACK WOULD BE A DEFAULT NOBODY DECIDED. The dashboard
    | lists job payloads — payment envelopes here — and its controls retry and
    | delete jobs, which for this queue means replaying a projection outside the
    | outbox's own delivery accounting. So the gate is written explicitly, and it
    | requires BOTH the local environment AND an operator opting in.
    |
    | `.env.example` carries the flag as false, so a copied environment is closed
    | rather than open.
    */

    'dashboard' => [
        'enabled' => (bool) env('HORIZON_DASHBOARD_ENABLED', false),
        'environments' => ['local'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | `defaults` is the whole configuration: there is no per-environment override,
    | because the accepted starting policy is one process everywhere and an
    | environment that quietly ran more would invalidate the evidence gathered
    | against one.
    |
    | `balance => 'off'` — SupervisorOptions defaults to the string 'off' and
    | autoScales() tests `in_array($balance, ['simple', 'auto'])`, so 'off' is the
    | package's own spelling for no balancing. A single supervisor on a single
    | queue has nothing to balance, and `auto` would introduce scaling this slice
    | has no measurement to justify.
    */

    'defaults' => [
        'payments-projection' => [
            // THE LITERAL CONNECTION AND QUEUE NAMES, read from the same config the
            // relay publishes with, so a supervisor watching the wrong queue is a
            // test failure rather than a silent no-op.
            'connection' => $projection['queue_connection'],
            'queue' => [$projection['queue']],
            'balance' => 'off',
            'maxProcesses' => $projection['processes'],
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            // ONE EXECUTION PER PUBLISHED JOB. Redelivery is the outbox relay's and
            // the poison budget is the failure recorder's; a second retry owner here
            // would make attempts invisible to both.
            'tries' => $projection['tries'],
            // The middle link of the ordering chain.
            'timeout' => $projection['worker_timeout_seconds'],
            'nice' => 0,
        ],
    ],

    /*
    | EMPTY, AND THAT IS THE CONFIGURATION. Horizon merges `environments[APP_ENV]`
    | over `defaults`; with no entry for an environment it uses `defaults`
    | unchanged, which is what "one process everywhere" means. An empty array also
    | makes `horizon:status` refuse to start in an environment nobody declared,
    | so this is left empty rather than removed.
    */

    'environments' => [
        'local' => [],
        'production' => [],
        'testing' => [],
    ],

];
