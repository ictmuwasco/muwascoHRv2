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
        Schema::create('strategic_targets', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('goal_id')->index('goal_id');
            $table->integer('strategic_plan_id')->index('strategic_plan_id');
            $table->integer('department_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('baseline_value', 100)->nullable();
            $table->string('target_value', 100)->nullable();
            $table->string('unit', 50)->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('strategic_targets');
    }
};

