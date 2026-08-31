<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T8's durable projection surface: the projector receipt, the read row, and the
 * half of the counter invariant T5 and T7 both deliberately left to this task.
 *
 * A FORWARD MIGRATION, NOT AN EDIT to 2026_08_14_000300 or 2026_08_21_000000, for
 * the reason the transport-checks migration already records: both are merged and
 * applied against ledgerflow and ledgerflow_test, so an edit would take effect
 * only after a destructive reset and would leave applied migrations disagreeing
 * with the history that records them.
 *
 * Shapes are pinned in
 * [projection and query](../../../../docs/specifications/payments/projection-and-query.md),
 * "Durable projection schema", and read back from the PostgreSQL catalogs by
 * PaymentsSchemaTest. Decisions a reader cannot infer from the column list:
 *
 * - payment_projector_receipts CARRIES NO FOREIGN KEY, to payment_outbox or to
 *   stored_events. The outbox is rebuildable delivery state and stored_events is
 *   kept deliberately identical to the vendor stub; the receipt is the record that
 *   one projector has already consumed one event and must outlive both. Its
 *   composite primary key IS the idempotency mechanism: T8's consumer inserts
 *   (projector_name, event_id) with ON CONFLICT DO NOTHING, and the conflict is
 *   what tells a duplicate delivery from a first one.
 *
 * - projector_name is part of the key rather than assumed, so a second projector
 *   over the same events later gets its own receipt row instead of colliding with
 *   this one's.
 *
 * - payment_details HAS NO updated_at, and that absence is load-bearing rather
 *   than an omission. At-least-once delivery means a duplicate MUST converge
 *   without rewriting business fields; with no mutable column, a duplicate has
 *   nothing legitimate to touch, and the unique keys turn a contradictory
 *   payment_id or source_event_id into a visible failure instead of a silent
 *   overwrite. The consumer never upserts this table.
 *
 * - initiated_at is DERIVED FROM THE EVENT's occurred_at, never from processing
 *   time. PostgreSQL now() is used only for the operational receipt and
 *   completion-marker timestamps, so a replay reproduces the same read row.
 *
 * - restrictOnDelete on merchant_id, matching payment_idempotency_receipts: a
 *   financial record is never removed as a side effect of deleting its owner.
 *
 * - NO SECONDARY INDEX. The point lookups T8 performs are already served by the
 *   primary and unique keys above. A (merchant_id, payment_id), receipt event_id
 *   or standalone merchant_id index would be speculative here; T9's query path
 *   may add one on measured query-plan evidence.
 *
 * - projection_failure_generation is a MONOTONIC FENCE, not a counter of
 *   anything. Operator retry increments it; a failure recorder captures it under
 *   the projection lock and may only write failure metadata while it still
 *   matches, which is what lets a late recorder tell a delayed delivery apart
 *   from a new retry cycle rather than poisoning it.
 *
 * - The two CHECKs are this task's half of the non-negative invariant. T5 shipped
 *   none because no production writer existed, T7 added its two transport
 *   counters with its relay, and projection_failure_count arrives here with the
 *   consumer that owns it and with exhausted_at, the threshold it drives.
 *   PostgreSQL has no unsigned integer type — Laravel's unsignedInteger() is a
 *   plain `integer` — so a CHECK is the only enforcement there is.
 *
 * - BOTH CHECKS ARE VALIDATED IMMEDIATELY, and that is deliberate rather than
 *   overlooked. `ADD CONSTRAINT ... CHECK` takes ACCESS EXCLUSIVE and holds it
 *   for a full table scan; `ADD CONSTRAINT ... NOT VALID` takes the same lock but
 *   only for a catalog write, moving the scan into a later
 *   `VALIDATE CONSTRAINT`, which runs under SHARE UPDATE EXCLUSIVE and blocks
 *   neither reads nor writes. Measured on this schema, not assumed.
 *
 *   Immediate validation is correct HERE because payment_outbox is empty and has
 *   no deployed writer for these two columns: the failure recorder and the
 *   operator retry command both arrive in later T8 slices, so there is no
 *   existing data that could fail validation and no live traffic to block. The
 *   split would also need TWO migrations to buy anything, since Laravel wraps one
 *   migration in a single transaction — which is not what H2 approved.
 *
 *   A future migration that alters constraints on a materially populated
 *   payment_outbox should reassess this and prefer NOT VALID plus a separate
 *   VALIDATE CONSTRAINT. No row-count threshold is stated because none was
 *   measured.
 *
 * - NAMES, MEASURED AGAINST PostgreSQL's 63-BYTE IDENTIFIER LIMIT: 52 and 57
 *   bytes. A truncated name cannot be dropped by the name a later migration
 *   computes. The generated key names on the two new tables are comfortably
 *   short, so they keep Laravel's, exactly as the receipts migration reasons.
 *   PaymentsSchemaTest asserts the lengths rather than trusting this note.
 *
 * - timestampTz(..., 6) throughout, for the defaultTimePrecision = 0 reason
 *   documented on payment_idempotency_receipts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_projector_receipts', function (Blueprint $table): void {
            $table->string('projector_name');
            $table->uuid('event_id');
            $table->timestampTz('created_at', 6);

            // (projector_name, event_id), in this order: the leading column is the
            // one a second projector would differ on.
            $table->primary(['projector_name', 'event_id']);
        });

        Schema::create('payment_details', function (Blueprint $table): void {
            $table->uuid('payment_id')->primary();
            $table->uuid('source_event_id')->unique();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 32);
            $table->unsignedBigInteger('aggregate_version');
            $table->timestampTz('initiated_at', 6);
        });

        // Laravel's schema builder has no CHECK-constraint DSL, and the constraints
        // must be named: an anonymous one gets a server-generated name that down()
        // could not address.
        Schema::table('payment_outbox', function (Blueprint $table): void {
            $table->unsignedInteger('projection_failure_generation')->default(0);
        });

        DB::statement(<<<'SQL'
            alter table payment_outbox
                add constraint payment_outbox_projection_failure_count_non_negative
                check (projection_failure_count >= 0)
        SQL);

        DB::statement(<<<'SQL'
            alter table payment_outbox
                add constraint payment_outbox_projection_failure_generation_non_negative
                check (projection_failure_generation >= 0)
        SQL);
    }

    public function down(): void
    {
        // Reverse order of creation, and `if exists` so a partially applied up()
        // can still be rolled back. Dropping the column would take its own
        // constraint with it; dropping the constraint first keeps the two
        // statements independent of that implicit behaviour.
        DB::statement(<<<'SQL'
            alter table payment_outbox
                drop constraint if exists payment_outbox_projection_failure_generation_non_negative
        SQL);

        DB::statement(<<<'SQL'
            alter table payment_outbox
                drop constraint if exists payment_outbox_projection_failure_count_non_negative
        SQL);

        Schema::table('payment_outbox', function (Blueprint $table): void {
            $table->dropColumn('projection_failure_generation');
        });

        Schema::dropIfExists('payment_details');
        Schema::dropIfExists('payment_projector_receipts');
    }
};
