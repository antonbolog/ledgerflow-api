<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const array PROJECTIONS = ['payment_details'];

    public function up(): void
    {
        Schema::create('payment_projection_controls', function (Blueprint $table): void {
            $table->string('projection')->primary();

            $table->string('state', 32);

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

        // CHECKs constrain row shape only: rebuild and recovery code must preserve cleared_at across takeovers.
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

        // cleared_at stays unconstrained: guarded recovery must tell a cleared rebuild from an uncleared one.
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

        // Seeded here, not in a seeder: a missing control row reads as a closed gate.
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
        DB::statement('alter table payment_projection_controls drop constraint if exists payment_projection_controls_rebuilding_owned');
        DB::statement('alter table payment_projection_controls drop constraint if exists payment_projection_controls_ready_unowned');
        DB::statement('alter table payment_projection_controls drop constraint if exists payment_projection_controls_state_allowed');

        Schema::dropIfExists('payment_projection_controls');
    }
};
