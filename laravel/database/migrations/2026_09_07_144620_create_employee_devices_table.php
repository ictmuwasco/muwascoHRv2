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
        Schema::create('employee_devices', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('employee_id')->index('idx_employee');
            $table->string('device_fingerprint', 64)->comment('Canvas/WebGL/hardware hash');
            $table->string('raw_device_fingerprint', 64)->nullable()->index('idx_employee_device_raw_fp')->comment('Physical-device fingerprint (no employee ID) â€” used for cross-employee checks');
            $table->string('device_token', 128)->unique('device_token')->comment('Long-lived token stored in localStorage');
            $table->dateTime('registered_at')->useCurrent();
            $table->dateTime('last_used')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->boolean('is_primary')->default(true);
            $table->boolean('is_active')->default(true)->comment('0 = revoked by HR');
            $table->string('device_label', 100)->nullable()->comment('e.g. Office PC, Work Phone');

            $table->index(['device_token'], 'idx_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('employee_devices');
    }
};

