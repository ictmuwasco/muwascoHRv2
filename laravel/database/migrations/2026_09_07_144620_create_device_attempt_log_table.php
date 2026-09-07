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
        Schema::create('device_attempt_log', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('employee_id')->index('idx_employee');
            $table->integer('user_id')->nullable();
            $table->string('device_fingerprint', 64);
            $table->string('ip_address', 45);
            $table->text('user_agent')->nullable();
            $table->dateTime('attempted_at')->useCurrent()->index('idx_date');
            $table->string('action', 50)->default('login')->comment('login | clock_in | clock_out');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('device_attempt_log');
    }
};

