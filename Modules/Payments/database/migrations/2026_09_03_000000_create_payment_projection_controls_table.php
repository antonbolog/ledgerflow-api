<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T11's durable projection gate state: one row per projection.
 *
 * A FORWARD MIGRATION, for the reason every Payments migration since
 * 2026_08_21_000000 records: earlier migrations are merged and applied against
 * ledgerflow and ledgerflow_test, so an edit would take effect only after a
 * destructive reset and would leave applied migrations disagreeing with the
 * history that records them.
 *
 * Shapes are pinned in
 * [verification and rebuild](../../../../docs/specifications/payments/verification-and-rebuild.md),
 * "Projection gate", decided in
 * [ADR 0005](../../../../docs/adr/0005-payments-projection-gate-and-rebuild-ownership.md),
 * and read back from the PostgreSQL catalogs by PaymentsSchemaTest.
 *
 * THIS TABLE IS THE DURABLE HALF OF THE GATE. The advisory lock is the other
 * half, and neither is redundant: the lock is released by PostgreSQL when a
 * rebuild's backend goes away, at which point this row is the only thing still
 * saying the read model is half-cleared. A gate check reads both and fails
 * closed on either.
 *
 * Decisions a reader cannot infer from the column list:
 *
 * - THE SEEDED ROW IS PART OF THE MIGRATION, not of a seeder. A missing control
 *   row is treated as a CLOSED gate rather than as `ready`, so a projection
 *   whose row never existed would be permanently unreadable. Seeding it here
 *   makes "the row exists" a schema fact rather than an operational one.
 *
 * - `projection` IS THE PRIMARY KEY, and it is the durable projector name that
 *   `payment_projector_receipts.projector_name` already uses. The advisory key
 *   is a pair of integer constants owned by ProjectionGate; this column is the
 *   name that pair addresses. Keeping the name here and the integers in code
 *   means a second projection later adds one row and one constant, both
 *   reviewable, rather than a hash nobody can check for collisions.
 *
 * - `owner_pid` IS DIAGNOSTICS, NOT A FENCE. An earlier draft compared it
 *   against pg_backend_pid() as the ownership check. That is a proxy for "am I
 *   the same session" and is defeated by pid reuse, so the fence asks pg_locks
 *   whether this backend still HOLDS the gate. This column survives only to
 *   tell an operator which backend a stuck rebuild belonged to.
 *
 * - `cleared_at` IS NOT A PER-RUN TIMESTAMP. It means "the projection has been
 *   destructively cleared and no replay has completed since". Reading it as
 *   "when this run cleared" is exactly what would licence a takeover to reset
 *   it, and a reset would let guarded recovery declare `ready` over data that
 *   was never replayed. The CHECKs below constrain the current row only;
 *   rebuild and recovery code must preserve this marker across takeovers.
 *
 * - THE THREE CHECKS CONSTRAIN ROW SHAPE. Laravel's schema builder has no
 *   CHECK DSL here, so the constraints use raw SQL and explicit names that
 *   schema tests and down() can address.
 *
 *   `ready` requires every ownership and clear column to be null, which is the
 *   database enforcing the shape of a ready row, not proving that replay
 *   completed. `rebuilding` requires the ownership triple to be present.
 *   The rebuild and recovery transactions must enforce D7's transition rules.
 *
 * - VALIDATED IMMEDIATELY, and that is safe here for the reason
 *   2026_08_31_000000 records at length: this table is created by this same
 *   migration and holds exactly the row seeded below, so there is no existing
 *   data that could fail validation and no live traffic for ACCESS EXCLUSIVE to
 *   block. A future migration altering constraints on a populated table should
 *   reassess and prefer NOT VALID plus a separate VALIDATE CONSTRAINT.
 *
 * - NAMES, MEASURED AGAINST PostgreSQL's 63-BYTE IDENTIFIER LIMIT: 41, 41 and
 *   44 bytes. A truncated name cannot be dropped by the name a later migration
 *   computes. PaymentsSchemaTest asserts the lengths rather than trusting this
 *   note.
 *
 * - NO SECONDARY INDEX. The table holds one row per projection and is read by
 *   primary key on every gate check. An index on `state` would be speculative
 *   on a table that fits in one page.
 *
 * - timestampTz(..., 6) throughout, for the defaultTimePrecision = 0 reason
 *   documented on payment_idempotency_receipts.
 */
return new class extends Migration
{
    /**
     * The projections this gate governs, seeded `ready`.
     *
     * One entry, deliberately. `payment_details` is the only read model Payments
     * has, and a speculative second row would be a projection nothing projects.
     *
     * @var list<string>
     */
    private const array PROJECTIONS = ['payment_details'];

    public function up(): void
    {
        Schema::create('payment_projection_controls', function (Blueprint $table): void {
            $table->string('projection')->primary();

            // varchar(32), matching payment_details.status, rather than a native
            // enum type: adding a value to a PostgreSQL enum is a type migration,
            // while widening a CHECK is one statement.
            $table->string('state', 32);

            // NULLABLE, and null is the normal state. These three describe a run
            // that currently owns the projection; a `ready` projection has no
            // owner, which the ready CHECK below enforces rather than assumes.
            $table->uuid('owner_token')->nullable();
            $table->integer('owner_pid')->nullable();
            $table->timestampTz('started_at', 6)->nullable();

            $table->timestampTz('cleared_at', 6)->nullable();

            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            alter table payment_projection_controls
                add constraint payment_projection_controls_state_allowed
                check (state in ('ready', 'rebuilding'))
        SQL);

        // A ready row may carry neither ownership nor a clear marker. This
        // checks its resulting shape only: resetting cleared_at during takeover
        // would still satisfy the constraints. Rebuild and recovery code must
        // preserve the marker until a complete replay permits clearing it.
        DB::statement(<<<'SQL'
            alter table payment_projection_controls
                add constraint payment_projection_controls_ready_unowned
                check (
                    state <> 'ready'
                    or (
                        owner_token is null
                        and owner_pid is null
                        and started_at is null
                        and cleared_at is null
                    )
                )
        SQL);

        // cleared_at is deliberately ABSENT here: a rebuilding projection may or
        // may not have cleared yet, and which of the two it is IS the question
        // guarded recovery asks.
        DB::statement(<<<'SQL'
            alter table payment_projection_controls
                add constraint payment_projection_controls_rebuilding_owned
                check (
                    state <> 'rebuilding'
                    or (
                        owner_token is not null
                        and owner_pid is not null
                        and started_at is not null
                    )
                )
        SQL);

        // now(), not a PHP clock, for the reason every other Payments writer
        // records: transaction_timestamp() ties the row to the transaction that
        // created it.
        foreach (self::PROJECTIONS as $projection) {
            DB::table('payment_projection_controls')->insert([
                'projection' => $projection,
                'state' => 'ready',
                'created_at' => DB::raw('now()'),
                'updated_at' => DB::raw('now()'),
            ]);
        }
    }

    public function down(): void
    {
        // Reverse order of creation, and `if exists` so a partially applied up()
        // can still be rolled back. Dropping the table would take its constraints
        // with it; dropping them first keeps the statements independent of that
        // implicit behaviour.
        DB::statement('alter table payment_projection_controls drop constraint if exists payment_projection_controls_rebuilding_owned');
        DB::statement('alter table payment_projection_controls drop constraint if exists payment_projection_controls_ready_unowned');
        DB::statement('alter table payment_projection_controls drop constraint if exists payment_projection_controls_state_allowed');

        Schema::dropIfExists('payment_projection_controls');
    }
};
