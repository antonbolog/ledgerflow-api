<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema-identical to spatie/laravel-event-sourcing 7.15.1's published stub.
 *
 * NOTHING IN M1-M5 WRITES THIS TABLE, and it is still required.
 * AggregateRoot::reconstituteFromEvents() calls
 * getSnapshotRepository()->retrieve($uuid) unconditionally, and that is the only
 * body reachable from either uuid-binding path — retrieve() and loadUuid().
 * EloquentSnapshotRepository::retrieve() selects from `snapshots`, so every
 * PaymentAggregate::retrieve() reads it. No configuration switch skips it.
 *
 * The alternative — overriding reconstituteFromEvents() to a no-op, or pointing
 * $snapshotRepository at a null object — removes the read at the cost of an
 * aggregate that cannot be replayed. One empty table is the cheaper trade. See
 * [development environment](../../../../docs/specifications/foundation/development-environment.md),
 * "What T5 added".
 *
 * Same "do not improve the column set" rule as stored_events: the T3/T4 spikes
 * run against this definition from T5 onward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('aggregate_uuid');
            $table->unsignedBigInteger('aggregate_version');
            $table->jsonb('state');

            $table->timestamps();

            $table->index('aggregate_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshots');
    }
};
