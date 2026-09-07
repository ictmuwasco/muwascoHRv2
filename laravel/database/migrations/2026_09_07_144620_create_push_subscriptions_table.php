<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->comment('Authenticated user (users.id)');
            $table->char('endpoint_hash', 64)->unique('uq_push_endpoint_hash')->comment('SHA-256 of endpoint URL (unique upsert key)');
            $table->text('endpoint')->comment('Push service endpoint URL');
            $table->text('p256dh_key')->comment('Client public key (base64url)');
            $table->text('auth_key')->comment('Auth secret (base64url)');
            $table->string('device_name', 120)->nullable()->comment('Friendly device label supplied by the employee');
            $table->string('platform', 60)->nullable()->comment('Browser platform hint (android/windows/...)');
            $table->string('user_agent', 500)->nullable()->comment('User agent at registration time');
            $table->dateTime('last_used_at')->nullable()->comment('Last successful send attempt');
            $table->dateTime('revoked_at')->nullable()->comment('Set when unsubscribed or endpoint invalid (410)');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['user_id', 'revoked_at'], 'idx_push_user_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('push_subscriptions');
    }
};

