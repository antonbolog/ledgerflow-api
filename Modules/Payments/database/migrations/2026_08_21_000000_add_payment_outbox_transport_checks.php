<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// unsignedInteger() is a plain PostgreSQL integer: these CHECKs are the only non-negative enforcement.
return new class extends Migration
{
    public function up(): void
    {
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
