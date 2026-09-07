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
        Schema::create('performance_indicators', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->integer('max_score')->default(5);
            $table->string('activity_ids')->nullable();
            $table->string('role', 50)->nullable();
            $table->string('assigned_to_employee_ids')->nullable();
            $table->integer('department_id')->nullable();
            $table->boolean('is_active')->nullable()->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
            $table->integer('section_id')->nullable();
            $table->integer('subsection_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->boolean('is_recurrent')->nullable()->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('performance_indicators');
    }
};

