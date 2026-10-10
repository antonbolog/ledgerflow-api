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
        Schema::table('payment_idempotency_receipts', function (Blueprint $table): void {
            // Nullable without backfill: historical receipts were never sent an intent.
            $table->uuid('intent_id')->nullable();

            // Merchant-scoped, never global, so equal intent ids under two merchants stay independent.
            $table->unique(['merchant_id', 'intent_id'], 'payment_receipts_merchant_intent_unique');
        });

        // Partial: only intent-bearing receipts are ever listed for recovery.
        DB::statement(<<<'SQL'
            create index payment_receipts_merchant_recent_intent_index
                on payment_idempotency_receipts (merchant_id, created_at desc, id desc)
                where intent_id is not null
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop index if exists payment_receipts_merchant_recent_intent_index');

        Schema::table('payment_idempotency_receipts', function (Blueprint $table): void {
            $table->dropUnique('payment_receipts_merchant_intent_unique');
            $table->dropColumn('intent_id');
        });
    }
};
