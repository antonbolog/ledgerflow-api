<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Payments outbox relay
    |--------------------------------------------------------------------------
    |
    | It lives at the root because tests/Architecture/ModuleBoundaryTest.php
    | forbids a module carrying a config directory — the same reason routes/api.php
    | is at the root. Modules\Payments\Infrastructure\Relay\RelayConfiguration
    | converts and validates this array once, so nothing downstream reads a mixed.
    |
    | THE SPLIT BETWEEN LITERAL AND env() IS DELIBERATE, AND IT IS NOT ABOUT
    | CONVENIENCE. A literal is a value that cannot be changed by editing .env
    | because changing it there would break a contract something else depends on
    | and nothing would report it. An env() is an operational timing the load-test
    | milestone is expected to tune.
    |
    */

    'relay' => [

        /*
        | LITERALS.
        |
        | The queue identity is named by docs/operations/runtime-contract.md and by
        | the Horizon supervision T8 adds. A .env that moved one and not the other
        | would leave the relay publishing where nothing consumes, silently.
        |
        | The cache store is a literal for the same reason plus one more: the
        | heartbeat must never reach the DEFAULT cache store, whose driver and
        | Redis connection are env-driven and unbounded. `payments-relay` is
        | defined in config/cache.php against the bounded `payments` Redis
        | connection.
        */
        'queue_connection' => 'payments-projection',
        'queue' => 'payments-projection',
        'cache_store' => 'payments-relay',

        /*
        | OPERATIONAL TIMINGS. docs/specifications/payments/outbox-delivery.md:
        | "Initial timings are operational defaults, not domain rules ... The
        | compatibility and load-test milestone may tune them without weakening
        | acceptance tests."
        |
        | Every one is cast to int here; RelayConfiguration refuses a string, so a
        | forgotten cast fails at container resolution rather than becoming a
        | zero-valued limit at run time.
        */

        // Rows per invocation. At one invocation per second this is the steady
        // drain rate; the start-budget below usually stops a pass first.
        'batch_size' => (int) env('PAYMENTS_RELAY_BATCH_SIZE', 50),

        // A START budget, not a kill deadline: it is checked BEFORE each next row
        // transaction, and a row already begun always finishes committing or
        // rolling back. Below the one-second cadence, so a pass normally ends
        // before its successor is due.
        'start_budget_ms' => (int) env('PAYMENTS_RELAY_START_BUDGET_MS', 750),

        // Bounds how long one row transaction can hold its connection. Publication
        // happens inside that transaction, so this is a database-occupancy control.
        'statement_timeout_ms' => (int) env('PAYMENTS_RELAY_STATEMENT_TIMEOUT_MS', 500),

        // How long an accepted row waits before it becomes eligible again. If T8's
        // consumer has not written projection_completed_at by then, the row is
        // redelivered. Never zero: RelayConfiguration refuses it, because a
        // zero-second grace republishes every accepted row on the very next pass.
        'reconciliation_grace_seconds' => (int) env('PAYMENTS_RELAY_GRACE_SECONDS', 30),

        // 1, 2, 4 ... capped. An arbitrarily long outage produces an arbitrarily
        // large count and never exhausts the row.
        'backoff_base_seconds' => (int) env('PAYMENTS_RELAY_BACKOFF_BASE_SECONDS', 1),
        'backoff_max_seconds' => (int) env('PAYMENTS_RELAY_BACKOFF_MAX_SECONDS', 300),

        // Three missed one-second ticks. Env-tunable because it is the alerting
        // threshold an operator adjusts, not a correctness value.
        'heartbeat_freshness_seconds' => (int) env('PAYMENTS_RELAY_HEARTBEAT_FRESHNESS_SECONDS', 3),

        // LITERAL, and it must exceed the freshness window. The status contract
        // reports `stale` and `missing` as different states; a TTL at or below
        // freshness makes `stale` unreachable, so a stopped scheduler would be
        // indistinguishable from one that never ran. RelayConfiguration enforces it.
        'heartbeat_ttl_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Payments projection consumer
    |--------------------------------------------------------------------------
    |
    | THE SINGLE SOURCE OF TRUTH FOR THE THREE ORDERED TIMEOUTS, read from here by
    | both the consumer and config/horizon.php. The ordering
    |
    |     statement_timeout (2s) < worker timeout (15s) < retry_after (90s)
    |
    | is a correctness chain, not three independent tunings, and it is what makes
    | "one Horizon execution per published job" true:
    |
    | - a projection transaction that stalls is cancelled by PostgreSQL BEFORE the
    |   worker's alarm fires, so the failure arrives as a classifiable 57014 on a
    |   connection the process still owns, rather than as a SIGALRM in the middle
    |   of an open transaction;
    | - the worker gives up long BEFORE the queue's reservation lapses, so Redis
    |   never hands the same message to a second worker while the first is still
    |   holding the envelope's row lock.
    |
    | Duplicating any of them into config/horizon.php would let the chain break
    | with nothing failing, which is why horizon.php reads them from here instead.
    | ProjectionConfiguration validates the ordering at container resolution.
    |
    | ALL LITERALS. Unlike the relay's timings, none of these is an operational
    | dial: each is one link of the chain above, and a .env that moved one without
    | the others would break an invariant no test could see at run time.
    |
    */

    'projection' => [

        // The same two names the relay publishes to, repeated here rather than
        // referenced, so a supervisor watching the wrong queue is a test failure
        // instead of a silent no-op. RelayConfiguration owns the publish side;
        // ProjectionConfiguration owns the consume side; the config test asserts
        // they are equal.
        'queue_connection' => 'payments-projection',
        'queue' => 'payments-projection',

        // Applied as `SET LOCAL` inside the consumer's own transaction, so it dies
        // with that transaction rather than leaking into whatever the connection
        // does next. Two seconds is generous for four indexed single-row
        // statements and still an order of magnitude below the worker timeout.
        'statement_timeout_ms' => 2000,

        // Horizon's per-job wall clock. Read by config/horizon.php.
        'worker_timeout_seconds' => 15,

        // ONE EXECUTION PER PUBLISHED JOB. Horizon does not own a retry budget:
        // redelivery is the outbox relay's, driven by reconciliation grace and
        // durable in available_at, and the poison budget is the failure recorder's.
        // A second retry owner here would let a message be retried by Horizon
        // without any of that being visible in PostgreSQL.
        'tries' => 1,

        // One process to start. Concurrency is safe — the envelope row lock
        // serialises duplicate deliveries — but a starting value is not evidence,
        // and raising it belongs with measurement.
        'processes' => 1,
    ],

];
