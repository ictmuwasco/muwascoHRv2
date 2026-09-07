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
        Schema::create('employees', function (Blueprint $table) {
            $table->integer('id', true);
            $table->char('profile_token', 64)->nullable();
            $table->string('employee_id', 50)->nullable()->unique('uk_employees_employee_id');
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('surname', 100)->nullable();
            $table->string('gender', 10);
            $table->integer('national_id');
            $table->string('email')->nullable();
            $table->string('designation', 50)->nullable();
            $table->string('phone', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('address')->nullable();
            $table->integer('department_id')->nullable();
            $table->integer('section_id')->nullable();
            $table->string('position', 100)->nullable();
            $table->decimal('salary', 10)->nullable();
            $table->date('hire_date')->nullable();
            $table->string('employment_type', 20);
            $table->string('employee_type', 20);
            $table->string('profile_image_url', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
            $table->enum('employee_status', ['active', 'inactive', 'resigned', 'fired', 'retired'])->default('active');
            $table->string('scale_id', 10)->nullable();
            $table->text('next_of_kin')->nullable();
            $table->text('dependants')->nullable();
            $table->integer('subsection_id')->nullable();
            $table->integer('office_id')->nullable();
            $table->date('contract_start_date')->nullable()->comment('Start date for contract employees');
            $table->date('contract_end_date')->nullable()->index('idx_employees_contract_dates')->comment('End date for contract employees');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('employees');
    }
};

