<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * MUWASCO migration baseline note:
 *
 * The `users` table is OWNED BY THE INHERITED SCHEMA (the legacy PHP
 * application's `users` table — see backend/database/). Laravel must never
 * create, alter or drop it here; the L1 baseline migrations generated from
 * the live database are the authoritative representation. This skeleton
 * migration therefore only creates the Laravel-infrastructure tables that
 * do not exist in the inherited schema:
 *   - password_reset_tokens (Laravel password reset flow, ported in L2)
 *   - sessions (SESSION_DRIVER=database)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
