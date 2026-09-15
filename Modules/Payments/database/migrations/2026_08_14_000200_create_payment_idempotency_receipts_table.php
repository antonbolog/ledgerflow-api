<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_idempotency_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->char('idempotency_key_hash', 64);
            $table->char('request_fingerprint', 64);
            $table->uuid('payment_id');
            $table->unsignedSmallInteger('response_status');
            // TEXT, never JSONB: a replay must return the exact stored bytes.
            $table->text('response_body');
            $table->text('response_location');
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);

            // Explicit names: generated ones exceed PostgreSQL's 63-byte limit and could not be dropped by name.
            $table->unique(['merchant_id', 'idempotency_key_hash'], 'payment_receipts_merchant_key_unique');
            $table->unique('payment_id', 'payment_receipts_payment_id_unique');
            $table->index(['merchant_id', 'payment_id'], 'payment_receipts_merchant_payment_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_idempotency_receipts');
    }
};
