<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Stored in PostgreSQL rather than Redis so that the "completed" marker can be
 * written in the same transaction as the order itself: either both exist or neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('idempotency_key');
            $table->string('scope', 50);
            $table->char('request_fingerprint', 64);
            $table->string('status', 20); // processing | completed
            $table->unsignedSmallInteger('response_status')->nullable();
            // `json` (not `jsonb`): keeps the stored body byte-for-byte, incl. key order,
            // so a replay is identical to the original response.
            $table->json('response_body')->nullable();
            // Fencing token: only the request holding the current token may complete or release the key.
            $table->uuid('lock_token');
            $table->timestampTz('locked_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();

            // Keys are scoped per user + endpoint so two cashiers can never collide.
            $table->unique(['user_id', 'scope', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
