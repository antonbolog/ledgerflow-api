<?php

declare(strict_types=1);

// require, not config(): configuration loads alphabetically, so payments.php is not loaded yet when this file runs.
/** @var array{projection: array<string, mixed>} $payments */
$payments = require __DIR__.'/payments.php';

/** @var array{queue_connection: string, queue: string, processes: int, tries: int, worker_timeout_seconds: int} $projection */
$projection = $payments['projection'];

return [

    'use' => 'horizon',

    'path' => env('HORIZON_PATH', 'horizon'),

    'domain' => env('HORIZON_DOMAIN'),

    // No auth middleware: there is no web session, so the gate in HorizonServiceProvider controls access.
    'middleware' => ['web'],

    // Not a Horizon key: read by HorizonServiceProvider's dashboard gate.
    'dashboard' => [
        'enabled' => (bool) env('HORIZON_DASHBOARD_ENABLED', false),
        'environments' => ['local'],
    ],

    'defaults' => [
        'payments-projection' => [
            'connection' => $projection['queue_connection'],
            'queue' => [$projection['queue']],
            'balance' => 'off',
            'maxProcesses' => $projection['processes'],
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => $projection['tries'],
            'timeout' => $projection['worker_timeout_seconds'],
            'nice' => 0,
        ],
    ],

    // Empty on purpose: every declared environment runs the defaults unchanged.
    'environments' => [
        'local' => [],
        'production' => [],
        'testing' => [],
    ],

];
