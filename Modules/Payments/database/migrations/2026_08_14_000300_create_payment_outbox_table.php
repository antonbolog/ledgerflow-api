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

        // Partial, so the relay's hot path scales with pending work rather than total history.
        DB::statement(<<<'SQL'
            create index payment_outbox_eligible_index
                on payment_outbox (available_at, event_id)
                where projection_completed_at is null and exhausted_at is null
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_outbox');
    }
};
