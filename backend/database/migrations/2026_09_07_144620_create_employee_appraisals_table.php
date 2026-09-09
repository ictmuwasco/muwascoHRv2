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
        Schema::create('employee_appraisals', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('employee_id');
            $table->integer('employee_department_id')->nullable();
            $table->integer('appraiser_id');
            $table->integer('appraisal_cycle_id');
            $table->text('employee_comment')->nullable();
            $table->integer('employee_satisfied');
            $table->timestamp('employee_comment_date')->nullable();
            $table->text('supervisors_comment');
            $table->dateTime('supervisors_comment_date')->useCurrent();
            $table->timestamp('submitted_at')->nullable();
            $table->enum('status', ['draft', 'awaiting_employee', 'submitted', 'completed', 'awaiting_submission', 'pending_dept_approval', 'under_review', 'rejected', 'cancelled'])->nullable()->default('draft');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
            $table->boolean('escalated_to_dept_head')->nullable()->default(false);
            $table->string('escalation_level', 50)->nullable();
            $table->dateTime('escalated_date')->nullable();
            $table->text('dept_head_decision')->nullable();
            $table->text('dept_head_comment')->nullable();
            $table->enum('dept_head_resolution', ['resolved', 'further_action'])->nullable();
            $table->text('dept_head_resolution_notes')->nullable();
            $table->dateTime('dept_head_review_date')->nullable();
            $table->dateTime('dept_head_decision_date')->nullable();
            $table->integer('dept_head_reviewer_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('employee_appraisals');
    }
};

