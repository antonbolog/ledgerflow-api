<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_projector_receipts', function (Blueprint $table): void {
            $table->string('projector_name');
            $table->uuid('event_id');
            $table->timestampTz('created_at', 6);

            $table->primary(['projector_name', 'event_id']);
        });

        // No updated_at: a duplicate delivery must converge without rewriting business fields.
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
