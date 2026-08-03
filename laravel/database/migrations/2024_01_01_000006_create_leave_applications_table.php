<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_year_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('days_requested');
            $table->text('reason')->nullable();
            $table->json('deduction_details')->nullable();
            $table->integer('primary_days')->default(0);
            $table->integer('annual_days')->default(0);
            $table->integer('unpaid_days')->default(0);
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('applied_at')->nullable();
            $table->string('section_head_approval')->default('pending')->nullable();
            $table->foreignId('section_head_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('section_head_approved_at')->nullable();
            $table->string('dept_head_approval')->default('pending')->nullable();
            $table->foreignId('dept_head_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dept_head_approved_at')->nullable();
            $table->foreignId('hr_processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hr_processed_at')->nullable();
            $table->text('hr_comments')->nullable();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('section_head_emp_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('dept_head_emp_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('manager_emp_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->integer('days_deducted')->default(0);
            $table->integer('days_from_annual')->default(0);
            $table->foreignId('managing_director_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('hr_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hr_approved_at')->nullable();
            $table->timestamp('managing_director_approved_at')->nullable();
            $table->foreignId('md_emp_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('subsection_head_emp_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('subsection_head_approval')->default('pending')->nullable();
            $table->foreignId('subsection_head_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('subsection_head_approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_applications');
    }
};