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
        Schema::create('db_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->index('idx_user');
            $table->char('device_fp', 64)->comment('SHA-256 device fingerprint');
            $table->char('session_token', 64)->index('idx_token');
            $table->string('ip_address', 45)->default('');
            $table->string('user_agent', 512)->default('');
            $table->dateTime('last_activity')->index('idx_activity');
            $table->dateTime('displaced_at')->nullable()->comment('Set when a newer login displaces this session');
            $table->string('displaced_by', 120)->nullable()->comment('IP of the displacing device');
            $table->dateTime('created_at')->useCurrent();
            $table->string('persistent_device_id', 64)->nullable()->index('idx_persistent_device')->comment('Persistent device identifier (generated once and reused across sessions)');

            $table->unique(['user_id', 'device_fp'], 'uq_user_device');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('db_sessions');
    }
};

