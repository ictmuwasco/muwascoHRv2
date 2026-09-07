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
        Schema::create('absent_deductions', function (Blueprint $table) {
            $table->integer('id');
            $table->integer('employee_id');
            $table->date('deduction_date');
            $table->integer('leave_type_id');
            $table->decimal('days_deducted', 5)->default(1);
            $table->integer('deducted_by');
            $table->dateTime('deducted_at');
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('absent_deductions');
    }
};

