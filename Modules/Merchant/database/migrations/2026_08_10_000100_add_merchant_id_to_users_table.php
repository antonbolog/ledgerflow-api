<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every user belongs to exactly one merchant.
     *
     * NOT NULL on purpose. A nullable column for hypothetical operator
     * identities would make every consumer handle a null merchant for a case
     * that does not exist; roles, memberships and merchant switching are out of
     * scope. No unique constraint: one merchant may have several users later.
     * PostgreSQL does not index a foreign key column, so the index is explicit.
     */
    public function up(): void
    {
        // LOCAL DATABASES ARE DISPOSABLE, AND THIS MIGRATION DEPENDS ON IT.
        //
        // A NOT NULL column with no default cannot be added to a populated
        // table. T1's seeder is empty, but AGENTS.md blesses ad-hoc tinker, so a
        // developer's database may hold users. There is deliberately no
        // backfill: inventing a merchant for an orphan user would fabricate
        // ownership, which is worse than failing.
        //
        // The check is explicit rather than left to PostgreSQL because the
        // driver's own error names a constraint, not a remedy, and the person
        // reading it at that moment needs the remedy.
        if (DB::table('users')->exists()) {
            throw new RuntimeException(
                'Refusing to add users.merchant_id: the users table is not empty and there is no '
                .'backfill policy, because no deployed environment exists. Rebuild with '
                .'`make db-reset CONFIRM=db-reset`. Once a deployed environment exists, replace '
                .'this migration with a real backfill or a nullable-then-tighten pair.'
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
