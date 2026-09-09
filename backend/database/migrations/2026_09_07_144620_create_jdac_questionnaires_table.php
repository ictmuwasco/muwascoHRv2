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
        Schema::create('jdac_questionnaires', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('employee_id')->index('employee_id');
            $table->integer('financial_year_id')->index('financial_year_id');
            $table->enum('status', ['draft', 'submitted', 'under_review', 'approved', 'rejected'])->nullable()->default('draft')->index('status');
            $table->text('employee_comments')->nullable();
            $table->text('supervisor_verification_notes')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->integer('reviewed_by')->nullable()->index('fk_jdac_reviewer');
            $table->dateTime('created_at')->nullable()->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();

            $table->index(['employee_id', 'status'], 'idx_jdac_emp_status');
            $table->index(['financial_year_id', 'status'], 'idx_jdac_fy_status');
            $table->unique(['employee_id', 'financial_year_id'], 'idx_jdac_unique_emp_fy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('jdac_questionnaires');
    }
};

