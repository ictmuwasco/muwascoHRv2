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
        Schema::create('leave_roster', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('employee_id')->index('idx_employee');
            $table->integer('financial_year_id')->index('idx_financial_year');
            $table->string('scheduled_month', 20)->index('idx_scheduled_month');
            $table->integer('scheduled_year')->nullable()->index('idx_scheduled_year');
            $table->text('notes')->nullable();
            $table->integer('created_by');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['employee_id', 'financial_year_id'], 'uk_employee_financial_year');
            $table->unique(['employee_id', 'scheduled_month', 'scheduled_year'], 'unique_employee_month');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('leave_roster');
    }
};

