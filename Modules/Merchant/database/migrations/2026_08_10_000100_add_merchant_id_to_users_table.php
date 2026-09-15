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
        if (DB::table('users')->exists()) {
            throw new RuntimeException(
                'Refusing to add users.merchant_id: the users table is not empty and there is no '
                .'backfill policy. Recover persistent data with a corrective migration, such as a real '
                .'backfill or a nullable-then-tighten pair, or with a data restore. Only when losing all '
                .'local project data is acceptable, `make destroy CONFIRM=destroy` followed by '
                .'`make install` rebuilds an empty local stack.'
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->index('merchant_id', 'users_merchant_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['merchant_id']);
            $table->dropIndex('users_merchant_id_index');
            $table->dropColumn('merchant_id');
        });
    }
};
