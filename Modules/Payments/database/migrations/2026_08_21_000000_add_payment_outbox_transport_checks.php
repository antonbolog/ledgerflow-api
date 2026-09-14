<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T7's half of the non-negative counter invariant T5 deliberately deferred.
 *
 * A FORWARD MIGRATION, NOT AN EDIT TO 2026_08_14_000300. That migration is merged
 * and has already run against both ledgerflow and ledgerflow_test. Editing it would
 * take effect only after destroying the database and would leave an applied
 * migration disagreeing with the history that records it as applied.
 *
 * WHY A CHECK AT ALL, WHEN THE RELAY CANNOT PRODUCE A NEGATIVE. The relay writes
 * `publish_count = publish_count + 1` and `consecutive_transport_failures = 0` or
 * `+ 1` in SQL, from a row it holds locked, so it is structurally incapable of
 * storing a negative. PostgreSQL has no unsigned integer type — Laravel's
 * unsignedInteger() is a plain `integer`, which PaymentsSchemaTest reads from the
 * catalog — so this constraint is the only thing standing between these columns and
 * a writer that is not so careful: a future task, an operator UPDATE, a repair
 * command.
 *
 * MEASURED BEFORE IT WAS WRITTEN. With no constraint,
 * `update payment_outbox set publish_count = -1` succeeded. That is what makes this
 * load-bearing rather than decorative, and the red run of
 * PaymentsSchemaTest's two refusal tests is the record of it.
 *
 * projection_failure_count IS NOT CONSTRAINED HERE. It is T8's column, together
 * with exhausted_at and the threshold that links them. Its constraint arrives with
 * the writer that owns it.
 *
 * NAMES, MEASURED AGAINST PostgreSQL's 63-BYTE IDENTIFIER LIMIT — 41 and 58 bytes.
 * A truncated name cannot be dropped by the name a later migration computes, which
 * is the reason the T5 migration already names its own keys explicitly.
 * PaymentsSchemaTest asserts the lengths rather than trusting this note.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Laravel's schema builder has no CHECK-constraint DSL, and the constraint
        // must be named: an anonymous one gets a server-generated name that down()
        // could not address.
        DB::statement(<<<'SQL'
            alter table payment_outbox
                add constraint payment_outbox_publish_count_non_negative
                check (publish_count >= 0)
        SQL);

        DB::statement(<<<'SQL'
            alter table payment_outbox
                add constraint payment_outbox_consecutive_transport_failures_non_negative
                check (consecutive_transport_failures >= 0)
        SQL);
    }

    public function down(): void
    {
        // Dropped in reverse order of creation, and `if exists` so a partially
        // applied up() can still be rolled back.
        DB::statement(<<<'SQL'
            alter table payment_outbox
                drop constraint if exists payment_outbox_consecutive_transport_failures_non_negative
        SQL);

        DB::statement(<<<'SQL'
            alter table payment_outbox
                drop constraint if exists payment_outbox_publish_count_non_negative
        SQL);
    }
};
