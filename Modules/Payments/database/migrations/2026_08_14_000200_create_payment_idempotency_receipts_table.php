<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merchant-scoped request arbitration and exact response replay.
 *
 * T5 owns the shape; T6 owns the writer. Nothing inserts here until the atomic
 * initiation handler exists.
 *
 * Decisions a reader cannot infer from the column list:
 *
 * - response_body is TEXT, never JSONB. The contract is that an identical retry
 *   replays the exact bytes the first caller received. jsonb reorders keys and
 *   strips insignificant whitespace, so it would silently return a different
 *   document that merely means the same thing.
 *
 * - The RAW idempotency key is stored NOWHERE. Only its SHA-256 hex digest is,
 *   which gives fixed-width lookup and reduces casual plaintext exposure. The
 *   specification is explicit that this is not encryption.
 *
 * - char(64) rather than varchar: a SHA-256 hex digest is always exactly 64
 *   characters. Note that bpchar comparison ignores TRAILING SPACES, so a
 *   shorter value stored here would compare equal to its space-padded form.
 *   Harmless for fixed-length hex; worth knowing before anyone stores anything
 *   else in this column.
 *
 * - restrictOnDelete, not cascade. A financial record is never removed as a side
 *   effect of deleting the merchant that owns it.
 *
 * - No standalone merchant_id index. PostgreSQL does not index a foreign key
 *   automatically, but both keys below LEAD with merchant_id, which is what the
 *   planner uses. A third index would be dead weight.
 *
 * - The three Payments-authored keys below are named EXPLICITLY; the primary key
 *   and the foreign key keep Laravel's generated names, which are comfortably
 *   short. Laravel's generated name for the merchant/key
 *   unique would be payment_idempotency_receipts_merchant_id_idempotency_key_hash_unique,
 *   68 characters. PostgreSQL truncates identifiers at 63 bytes on creation, and
 *   a later dropUnique() computes the untruncated name and finds nothing.
 *
 * - timestampTz(..., 6), never a bare timestamp(). Laravel's
 *   Schema\Builder::$defaultTimePrecision is 0, so $table->timestamp() yields
 *   timestamp(0) in PostgreSQL, which ROUNDS to whole seconds.
 */
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
            $table->text('response_body');
            $table->text('response_location');
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);

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
