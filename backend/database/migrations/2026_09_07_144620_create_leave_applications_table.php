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
        Schema::create('leave_applications', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('financial_year_id')->nullable()->index('idx_financial_year');
            $table->integer('employee_id');
            $table->integer('leave_type_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('days_requested');
            $table->text('reason');
            $table->text('deduction_details')->nullable()->comment('JSON storage of deduction plan');
            $table->integer('primary_days')->nullable()->default(0)->comment('Days deducted from primary leave type');
            $table->integer('annual_days')->nullable()->default(0)->comment('Days deducted from annual leave');
            $table->integer('unpaid_days')->nullable()->default(0)->comment('Days that are unpaid');
            $table->integer('applied_by_user_id')->nullable();
            $table->enum('status', ['pending', 'pending_section_head', 'pending_dept_head', 'pending_managing_director', 'pending_hr_manager', 'approved', 'rejected', 'pending_bod_chair', 'pending_subsection_head', 'pending_manager', 'invalidated', 'pending_hr', 'cancelled'])->default('pending');
            $table->timestamp('applied_at')->useCurrent();
            $table->enum('section_head_approval', ['pending', 'approved', 'rejected'])->nullable()->default('pending');
            $table->string('section_head_approved_by', 50)->nullable();
            $table->timestamp('section_head_approved_at')->nullable();
            $table->enum('dept_head_approval', ['pending', 'approved', 'rejected'])->nullable()->default('pending');
            $table->string('dept_head_approved_by', 50)->nullable();
            $table->timestamp('dept_head_approved_at')->nullable();
            $table->string('hr_processed_by', 50)->nullable();
            $table->timestamp('hr_processed_at')->nullable();
            $table->text('hr_comments')->nullable();
            $table->integer('approver_id')->nullable();
            $table->integer('section_head_emp_id')->nullable();
            $table->integer('dept_head_emp_id')->nullable();
            $table->integer('delegate_emp_id')->nullable()->index('fk_leave_delegate_emp');
            $table->string('delegate_role', 50)->nullable();
            $table->integer('manager_emp_id')->nullable();
            $table->integer('days_deducted')->nullable()->default(0);
            $table->integer('days_from_annual')->nullable()->default(0);
            $table->integer('managing_director_approved_by')->nullable();
            $table->integer('hr_approved_by')->nullable();
            $table->dateTime('hr_approved_at')->nullable();
            $table->dateTime('managing_director_approved_at')->nullable();
            $table->integer('md_emp_id')->nullable();
            $table->integer('subsection_head_emp_id')->nullable();
            $table->enum('subsection_head_approval', ['pending', 'approved', 'rejected'])->nullable()->default('pending');
            $table->integer('subsection_head_approved_by')->nullable();
            $table->dateTime('subsection_head_approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
            $table->integer('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();

            $table->index(['employee_id', 'start_date'], 'idx_leave_emp_date');
            $table->index(['status', 'start_date', 'end_date'], 'idx_leave_status_dates');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('leave_applications');
    }
};

