<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Durable delivery intent for Payments events.
 *
 * T5 owns the shape only. Nothing writes here yet, and the writers arrive in
 * three distinct tasks:
 *
 * - T6 INSERTS the initial envelope, inside the same transaction as the
 *   idempotency receipt and the stored event. It is the first writer.
 * - T7 MUTATES TRANSPORT STATE ONLY: available_at, last_published_at,
 *   publish_count, consecutive_transport_failures, last_transport_error. It
 *   never writes exhausted_at — an arbitrarily long transport outage must not
 *   exhaust a row.
 * - T8 RECORDS THE CONSUMER OUTCOME AND OWNS EXHAUSTION:
 *   projection_completed_at on success, and on a confirmed handler failure
 *   projection_failure_count, last_projection_error and the threshold-driven
 *   exhausted_at IN ONE TRANSACTION. Exhaustion belongs with the counter that
 *   triggers it; splitting them across two owners makes the threshold check race.
 * - `payments:outbox:retry {eventId}` is the only path that CLEARS exhausted_at.
 *
 * Decisions a reader cannot infer from the column list:
 *
 * - event_type carries the ALIAS (payments.payment_initiated), not the
 *   fully-qualified class name, so the namespace can move without rewriting
 *   history. config/event-sourcing.php owns the mapping.
 *
 * - THREE SEPARATE COUNTERS and TWO SEPARATE ERROR COLUMNS. The specification's
 *   whole failure model rests on this: an arbitrarily long transport outage must
 *   never consume poison budget, and only a confirmed consumer-handler exception
 *   may. One shared counter cannot express that distinction.
 *
 * - unique (aggregate_uuid, aggregate_version) mirrors the key stored_events
 *   already enforces, so a duplicate envelope for one aggregate version is
 *   impossible rather than merely detectable by M5's verifier. It can never fire
 *   spuriously, because the source table cannot produce a colliding pair. This
 *   goes beyond the literal specification text and is recorded in
 *   [outbox delivery](../../../../docs/specifications/payments/outbox-delivery.md),
 *   "Envelope and active index".
 *
 * - NO CHECK CONSTRAINTS on the counters. PostgreSQL has no unsigned integer, so
 *   a CHECK would be the only enforcement of a non-negative invariant. T5 ships
 *   none because NO PRODUCTION WRITER EXISTS YET: a constraint added now would
 *   guard columns nothing writes.
 *
 *   The decision splits along the same ownership line as the columns themselves.
 *   T7 owns it for publish_count and consecutive_transport_failures; T8 owns it
 *   for projection_failure_count. T6 creates the row and should let all three
 *   take their zero defaults rather than supplying them.
 *
 * - No foreign key to stored_events. That table is kept deliberately identical to
 *   the vendor stub, and a foreign key referencing it would make every future
 *   reconciliation a schema negotiation. Atomicity comes from the shared
 *   transaction T3 measured; the relationship is M5's verifier's to assert.
 *
 * - timestampTz(..., 6) throughout, for the defaultTimePrecision = 0 reason
 *   documented on payment_idempotency_receipts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_outbox', function (Blueprint $table): void {
            $table->uuid('event_id')->primary();
            $table->string('event_type');
            $table->unsignedSmallInteger('schema_version');
            $table->uuid('aggregate_uuid');
            $table->unsignedBigInteger('aggregate_version');
            $table->jsonb('payload');
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('available_at', 6);
            $table->timestampTz('last_published_at', 6)->nullable();
            $table->timestampTz('projection_completed_at', 6)->nullable();
            $table->timestampTz('exhausted_at', 6)->nullable();
            $table->unsignedInteger('publish_count')->default(0);
            $table->unsignedInteger('consecutive_transport_failures')->default(0);
            $table->unsignedInteger('projection_failure_count')->default(0);
            $table->text('last_transport_error')->nullable();
            $table->text('last_projection_error')->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);

            $table->unique(['aggregate_uuid', 'aggregate_version'], 'payment_outbox_aggregate_version_unique');
        });

        // Laravel's schema builder has no partial-index DSL, and the predicate is
        // the entire point: without it the index carries every completed and
        // exhausted row forever, so the relay's hot path would degrade with total
        // history rather than with pending work.
        DB::statement(<<<'SQL'
            create index payment_outbox_eligible_index
                on payment_outbox (available_at, event_id)
                where projection_completed_at is null and exhausted_at is null
        SQL);
    }

    public function down(): void
    {
        // Dropping the table drops its indexes, the partial one included.
        Schema::dropIfExists('payment_outbox');
    }
};
