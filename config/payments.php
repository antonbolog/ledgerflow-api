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

    /*
    |--------------------------------------------------------------------------
    | Payments projection verification
    |--------------------------------------------------------------------------
    |
    | Bounds for the read-only `payments:projections:verify` audit.
    | Modules\Payments\Infrastructure\Verification\VerificationConfiguration
    | converts and validates this array once, at container resolution, which is
    | before the audit's REPEATABLE READ snapshot opens. A bound discovered after
    | the transaction is already open is not a bound.
    |
    | THE SPLIT FOLLOWS THE RELAY'S RULE. The three values an operator legitimately
    | tunes for their history size are env-driven; the three that are the audit's
    | own safety limits are literals, because a .env that widened them would remove
    | the guarantee that one invocation does bounded work and nothing would report it.
    |
    | These are initial operational limits, not measured throughput guarantees.
    | docs/specifications/payments/verification-and-rebuild.md owns the contract.
    |
    */

    'verification' => [

        // Canonical rows per keyset batch. Never zero: RelayConfiguration's
        // sibling rule applies here too, and a zero batch reads nothing while
        // reporting a clean snapshot of it.
        'batch_size' => (int) env('PAYMENTS_VERIFICATION_BATCH_SIZE', 100),

        // Retained diagnostic examples for the WHOLE invocation, not per batch.
        // Zero is legitimate: counts without detail is a coherent thing to ask for.
        'example_limit' => (int) env('PAYMENTS_VERIFICATION_EXAMPLE_LIMIT', 50),

        // Event age below which an incomplete, non-contradictory delivery is
        // in-flight rather than overdue. SEPARATE FROM the relay's
        // reconciliation_grace_seconds, and deliberately so: that one schedules
        // redelivery, this one decides what an operator is told. Age is measured
        // from canonical occurredAt to the audit timestamp, never from
        // last_published_at, or republication would rejuvenate an old event forever.
        'grace_seconds' => (int) env('PAYMENTS_VERIFICATION_GRACE_SECONDS', 30),

        /*
        | LITERALS. Each is a safety limit rather than an operational dial.
        */

        // One statement's bound, strictly below the transaction bound so a single
        // pathological query is distinguishable from an audit out of time.
        'statement_timeout_ms' => 2_000,

        // The whole snapshot's bound. PostgreSQL TERMINATES the session when this
        // fires, so the audit aborts rather than reconnecting into a lost snapshot.
        'transaction_timeout_ms' => 120_000,

        // The largest canonical payload this audit will transfer into PHP.
        // Enforced in SQL, before the value is transferred: a payload above it
        // makes the audit INCOMPLETE, because uninspected bytes may be declared
        // neither consistent nor corrupt.
        'max_payload_bytes' => 65_536,
    ],

    /*
    |--------------------------------------------------------------------------
    | Payments projection rebuild
    |--------------------------------------------------------------------------
    |
    | Bounds for `payments:projections:rebuild --confirm-rebuild`.
    | Modules\Payments\Infrastructure\Rebuild\RebuildConfiguration converts and
    | validates this array once, before the exclusive gate lock is even
    | requested.
    |
    | batch_size is an operational dial, same as the relay's and the audit's:
    | an operator tunes it for their history size. lock_timeout_ms and
    | statement_timeout_ms bound how long a destructive, gate-closing command
    | may wait or hold one statement; both are env-tunable for the same reason
    | the audit's own bounds are, but a widened value changes only how long the
    | rebuild waits or runs, never what it is permitted to do.
    |
    */

    'rebuild' => [

        // Canonical rows per replay batch, committed together with their
        // derived payment_details rows and projector receipts.
        'batch_size' => (int) env('PAYMENTS_REBUILD_BATCH_SIZE', 500),

        // Phase 1's bound: how long the exclusive lock acquisition waits for a
        // live rebuild - or every shared-holding traffic transaction already
        // in flight - to finish. A refusal here writes nothing at all.
        'lock_timeout_ms' => (int) env('PAYMENTS_REBUILD_LOCK_TIMEOUT_MS', 5_000),

        // Every later transaction's own SET LOCAL bound: the ownership claim,
        // the clear, each replay batch, and the ready transition.
        'statement_timeout_ms' => (int) env('PAYMENTS_REBUILD_STATEMENT_TIMEOUT_MS', 5_000),
    ],

];
