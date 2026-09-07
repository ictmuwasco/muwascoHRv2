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
        Schema::create('users', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('email')->nullable();
            $table->string('employee_id', 50)->nullable()->index('fk_users_employee');
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('surname', 50);
            $table->string('gender', 10);
            $table->string('password')->nullable();
            $table->enum('role', ['bod_chairman', 'super_admin', 'hr_manager', 'dept_head', 'section_head', 'manager', 'officer', 'managing_director', 'sub_section_head'])->nullable()->default('officer');
            $table->string('designation', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->string('profile_image_url', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
            $table->string('session_token')->nullable();
            $table->string('login_identifier', 64)->nullable();
            $table->boolean('is_active')->nullable()->default(true);
            $table->timestamp('last_activity')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('users');
    }
};

