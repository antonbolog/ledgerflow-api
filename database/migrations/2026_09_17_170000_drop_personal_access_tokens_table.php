<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sanctum tokens never expire, so dropping the table is what revokes every one of them (ADR 0006).
return new class extends Migration
{
    public function up(): void
    {
        Schema::drop('personal_access_tokens');
    }

    // Restores the schema only: revoked Sanctum tokens are not recoverable.
    public function down(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }
};
