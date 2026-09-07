<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Payments outbox relay
    |--------------------------------------------------------------------------
    |
    | At the root because the enforced module layout forbids a module config
    | directory. RelayConfiguration converts and validates this array once, so
    | nothing downstream reads a mixed.
    |
    | Literal versus env() is a contract distinction, not convenience: a literal
    | cannot be changed from .env because doing so would break a contract something
    | else depends on, with nothing reporting it. An env() is an operational timing.
    |
    */

    'relay' => [

        /*
        | LITERALS. A .env that moved the queue identity without also moving Horizon's
        | supervision would leave the relay publishing where nothing consumes,
        | silently. The heartbeat's cache store must never fall back to the default
        | one, whose driver and Redis connection are env-driven and unbounded.
        */
        'queue_connection' => 'payments-projection',
        'queue' => 'payments-projection',
        'cache_store' => 'payments-relay',

        /*
        | OPERATIONAL TIMINGS, tunable without weakening acceptance tests. See
        | docs/specifications/payments/outbox-delivery.md.
        |
        | Cast to int here because RelayConfiguration refuses a string, so a forgotten
        | cast fails at resolution rather than becoming a zero-valued limit at run time.
        */

        // Rows per invocation. At one invocation per second this is the steady
        // drain rate; the start-budget below usually stops a pass first.
        'batch_size' => (int) env('PAYMENTS_RELAY_BATCH_SIZE', 50),

        // A start budget, not a kill deadline: checked before each next row
        // transaction, and a row already begun always finishes. Below the one-second
        // cadence, so a pass normally ends before its successor is due.
        'start_budget_ms' => (int) env('PAYMENTS_RELAY_START_BUDGET_MS', 750),

        // Bounds how long one row transaction can hold its connection. Publication
        // happens inside that transaction, so this is a database-occupancy control.
        'statement_timeout_ms' => (int) env('PAYMENTS_RELAY_STATEMENT_TIMEOUT_MS', 500),

        // How long an accepted row waits before becoming eligible again, if the
        // consumer has not written projection_completed_at. Never zero:
        // RelayConfiguration refuses it, or every accepted row republishes at once.
        'reconciliation_grace_seconds' => (int) env('PAYMENTS_RELAY_GRACE_SECONDS', 30),

        // 1, 2, 4 ... capped. An arbitrarily long outage produces an arbitrarily
        // large count and never exhausts the row.
        'backoff_base_seconds' => (int) env('PAYMENTS_RELAY_BACKOFF_BASE_SECONDS', 1),
        'backoff_max_seconds' => (int) env('PAYMENTS_RELAY_BACKOFF_MAX_SECONDS', 300),

        // Three missed one-second ticks. Env-tunable because it is the alerting
        // threshold an operator adjusts, not a correctness value.
        'heartbeat_freshness_seconds' => (int) env('PAYMENTS_RELAY_HEARTBEAT_FRESHNESS_SECONDS', 3),

        // Literal, and it must exceed the freshness window: a TTL at or below it
        // makes `stale` unreachable, so a stopped scheduler would be
        // indistinguishable from one that never ran. RelayConfiguration enforces it.
        'heartbeat_ttl_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Payments projection consumer
    |--------------------------------------------------------------------------
    |
    | The single source of the three ordered timeouts, read from here by both the
    | consumer and config/horizon.php:
    |
    |     statement_timeout (2s) < worker timeout (15s) < retry_after (90s)
    |
    | A correctness chain, not three independent tunings. PostgreSQL cancels a stalled
    | transaction before the worker's alarm fires, so the failure is a classifiable
    | 57014 rather than a SIGALRM inside an open transaction; and the worker gives up
    | before the queue reservation lapses, so Redis never hands the same message to a
    | second worker while the first holds the envelope row lock.
    |
    | All literals, and horizon.php reads them from here rather than duplicating them,
    | because a .env moving one link would break the chain with nothing failing.
    | ProjectionConfiguration validates the ordering at container resolution.
    |
    */

    'projection' => [

        // Repeated rather than referenced, so a supervisor watching the wrong queue
        // is a test failure instead of a silent no-op. The config test asserts the
        // publish and consume sides are equal.
        'queue_connection' => 'payments-projection',
        'queue' => 'payments-projection',

        // Applied as `SET LOCAL`, so it dies with the consumer's transaction rather
        // than leaking into the connection's next use.
        'statement_timeout_ms' => 2000,

        // Horizon's per-job wall clock. Read by config/horizon.php.
        'worker_timeout_seconds' => 15,

        // One execution per published job. Redelivery belongs to the relay and the
        // poison budget to the failure recorder; a retry owner here would retry a
        // message with none of it visible in PostgreSQL.
        'tries' => 1,

        // One process to start. Concurrency is safe (the envelope row lock serialises
        // duplicates), but raising it belongs with measurement.
        'processes' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Payments projection verification
    |--------------------------------------------------------------------------
    |
    | Bounds for the read-only `payments:projections:verify` audit, validated at
    | container resolution - before the REPEATABLE READ snapshot opens, because a
    | bound discovered after the transaction is open is not a bound.
    |
    | The literal/env split follows the relay's rule: what an operator tunes for their
    | history size is env-driven, the audit's own safety limits are literals.
    |
    | Initial operational limits, not measured throughput guarantees. See
    | docs/specifications/payments/verification-and-rebuild.md.
    |
    */

    'verification' => [

        // Canonical rows per keyset batch. Never zero: a zero batch reads nothing
        // while reporting a clean snapshot of it.
        'batch_size' => (int) env('PAYMENTS_VERIFICATION_BATCH_SIZE', 100),

        // Retained diagnostic examples for the WHOLE invocation, not per batch.
        // Zero is legitimate: counts without detail is a coherent thing to ask for.
        'example_limit' => (int) env('PAYMENTS_VERIFICATION_EXAMPLE_LIMIT', 50),

        // Event age below which an incomplete delivery is in-flight rather than
        // overdue. Deliberately separate from the relay's reconciliation grace: that
        // one schedules redelivery, this one decides what an operator is told.
        // Measured from canonical occurredAt, never from last_published_at, or
        // republication would rejuvenate an old event forever.
        'grace_seconds' => (int) env('PAYMENTS_VERIFICATION_GRACE_SECONDS', 30),

        /*
        | LITERALS. Each is a safety limit rather than an operational dial.
        */

        // One statement's bound, strictly below the transaction bound so a single
        // pathological query is distinguishable from an audit out of time.
        'statement_timeout_ms' => 2_000,

        // The whole snapshot's bound. PostgreSQL terminates the session when it
        // fires, so the audit aborts rather than reconnecting into a lost snapshot.
        'transaction_timeout_ms' => 120_000,

        // The largest canonical payload this audit transfers into PHP, enforced in
        // SQL before transfer. A payload above it makes the audit incomplete:
        // uninspected bytes may be declared neither consistent nor corrupt.
        'max_payload_bytes' => 65_536,
    ],

    /*
    |--------------------------------------------------------------------------
    | Payments projection rebuild
    |--------------------------------------------------------------------------
    |
    | Bounds for `payments:projections:rebuild --confirm-rebuild`, validated before
    | the exclusive gate lock is requested.
    |
    | All three are env-tunable: a widened value changes only how long the rebuild
    | waits or runs, never what it is permitted to do.
    |
    */

    'rebuild' => [

        // Canonical rows per replay batch, committed together with their
        // derived payment_details rows and projector receipts.
        'batch_size' => (int) env('PAYMENTS_REBUILD_BATCH_SIZE', 500),

        // Phase 1's bound: how long the exclusive lock acquisition waits for a live
        // rebuild, or for in-flight shared-holding traffic, to finish. A refusal
        // here writes nothing at all.
        'lock_timeout_ms' => (int) env('PAYMENTS_REBUILD_LOCK_TIMEOUT_MS', 5_000),

        // Every later transaction's own SET LOCAL bound: the ownership claim,
        // the clear, each replay batch, and the ready transition.
        'statement_timeout_ms' => (int) env('PAYMENTS_REBUILD_STATEMENT_TIMEOUT_MS', 5_000),
    ],

];
