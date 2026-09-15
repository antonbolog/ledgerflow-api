<?php

declare(strict_types=1);

// Literals are contracts that .env must not move; env() values are operational timings.
return [

    'relay' => [

        // Literals: Horizon supervises these names, and the heartbeat must never use the default cache store.
        'queue_connection' => 'payments-projection',
        'queue' => 'payments-projection',
        'cache_store' => 'payments-relay',

        'batch_size' => (int) env('PAYMENTS_RELAY_BATCH_SIZE', 50),

        'start_budget_ms' => (int) env('PAYMENTS_RELAY_START_BUDGET_MS', 750),

        'statement_timeout_ms' => (int) env('PAYMENTS_RELAY_STATEMENT_TIMEOUT_MS', 500),

        'reconciliation_grace_seconds' => (int) env('PAYMENTS_RELAY_GRACE_SECONDS', 30),

        'backoff_base_seconds' => (int) env('PAYMENTS_RELAY_BACKOFF_BASE_SECONDS', 1),
        'backoff_max_seconds' => (int) env('PAYMENTS_RELAY_BACKOFF_MAX_SECONDS', 300),

        'heartbeat_freshness_seconds' => (int) env('PAYMENTS_RELAY_HEARTBEAT_FRESHNESS_SECONDS', 3),

        'heartbeat_ttl_seconds' => 300,
    ],

    // statement_timeout < worker timeout < retry_after is one correctness chain; config/horizon.php reads these literals.
    'projection' => [

        'queue_connection' => 'payments-projection',
        'queue' => 'payments-projection',

        'statement_timeout_ms' => 2000,

        'worker_timeout_seconds' => 15,

        'tries' => 1,

        'processes' => 1,
    ],

    'verification' => [

        'batch_size' => (int) env('PAYMENTS_VERIFICATION_BATCH_SIZE', 100),

        'example_limit' => (int) env('PAYMENTS_VERIFICATION_EXAMPLE_LIMIT', 50),

        'grace_seconds' => (int) env('PAYMENTS_VERIFICATION_GRACE_SECONDS', 30),

        // Literals: safety limits, not operational dials.
        'statement_timeout_ms' => 2_000,

        'transaction_timeout_ms' => 120_000,

        'max_payload_bytes' => 65_536,
    ],

    // Env-tunable: a wider value changes only how long the rebuild waits or runs, never what it may do.
    'rebuild' => [

        'batch_size' => (int) env('PAYMENTS_REBUILD_BATCH_SIZE', 500),

        'lock_timeout_ms' => (int) env('PAYMENTS_REBUILD_LOCK_TIMEOUT_MS', 5_000),

        'statement_timeout_ms' => (int) env('PAYMENTS_REBUILD_STATEMENT_TIMEOUT_MS', 5_000),
    ],

];
