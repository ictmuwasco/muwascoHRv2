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
        Schema::create('attendance', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('employee_id');
            $table->integer('office_id');
            $table->integer('clock_in_office_id')->nullable();
            $table->integer('clock_out_office_id')->nullable();
            $table->dateTime('clock_in')->nullable()->index('idx_attendance_clock_in_date');
            $table->dateTime('clock_out')->nullable();
            $table->decimal('lat', 10, 8)->nullable();
            $table->decimal('lng', 11, 8)->nullable();
            $table->float('accuracy')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->boolean('is_late')->nullable()->default(false);
            $table->boolean('auto_clocked_out')->nullable()->default(false);
            $table->string('device_fingerprint')->nullable();
            $table->string('status', 50)->nullable()->default('clocked_in');
            $table->dateTime('created_at')->nullable()->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
            $table->date('attendance_date')->nullable()->storedAs('cast(`clock_in` as date)');

            $table->index(['attendance_date', 'employee_id'], 'idx_attendance_date_emp');
            $table->index(['employee_id', 'clock_out'], 'idx_attendance_employee_active');
            $table->index(['employee_id', 'clock_in'], 'idx_attendance_employee_date');
            $table->index(['office_id', 'clock_in'], 'idx_attendance_office');
            $table->unique(['employee_id', 'attendance_date'], 'uk_attendance_employee_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('attendance');
    }
};

