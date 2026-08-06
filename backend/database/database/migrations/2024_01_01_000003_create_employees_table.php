<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('profile_token')->nullable();
            $table->string('employee_id')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('surname')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->integer('national_id')->nullable();
            $table->string('email')->nullable();
            $table->string('designation')->nullable();
            $table->string('phone')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('address')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->string('position')->nullable();
            $table->decimal('salary', 10, 2)->nullable();
            $table->date('hire_date')->nullable();
            $table->enum('employment_type', ['permanent', 'contract', 'intern'])->default('permanent');
            $table->enum('employee_type', ['officer', 'dept_head', 'section_head', 'sub_section_head', 'managing_director'])->default('officer');
            $table->string('profile_image_url')->nullable();
            $table->enum('employee_status', ['active', 'inactive', 'resigned', 'terminated'])->default('active');
            $table->string('scale_id')->nullable();
            $table->json('next_of_kin')->nullable();
            $table->foreignId('subsection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};