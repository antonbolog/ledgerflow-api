<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema-identical to spatie/laravel-event-sourcing 7.15.1's published stub.
 *
 * WHY THIS FILE EXISTS AT ALL: the package publishes its migrations and never
 * loads them. EventSourcingServiceProvider::configurePackage() calls
 * hasMigrations() but never runsMigrations(), and ProcessMigrations only calls
 * loadMigrationsFrom() when that flag is true. Nothing creates stored_events
 * until an application migration does.
 *
 * WHY IT LIVES IN THE PAYMENTS MODULE: AGENTS.md scopes event sourcing to this
 * bounded context, and ADR 0001 gives modules migration locality.
 *
 * DO NOT "IMPROVE" THE COLUMN SET. It stays identical to the vendor stub. If the
 * vendor stub ever changes, reconcile deliberately rather than drifting.
 *
 * created_at keeps the stub's timestamp(0): business time lives in the event
 * payload at microsecond precision, and this column is the package's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('aggregate_uuid')->nullable();
            $table->unsignedBigInteger('aggregate_version')->nullable();
            $table->unsignedTinyInteger('event_version')->default(1);
            $table->string('event_class');
            $table->jsonb('event_properties');
            $table->jsonb('meta_data');
            $table->timestamp('created_at');
            $table->index('event_class');
            $table->index('aggregate_uuid');

            $table->unique(['aggregate_uuid', 'aggregate_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_events');
    }
};
