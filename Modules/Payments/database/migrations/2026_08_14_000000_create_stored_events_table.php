<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Identical to the spatie/laravel-event-sourcing 7.15.1 stub, which the package never runs itself: do not change the columns.
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
