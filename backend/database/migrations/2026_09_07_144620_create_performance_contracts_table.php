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
        Schema::create('performance_contracts', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('strategic_plan_id')->index('strategic_plan_id');
            $table->integer('goal_id')->index('goal_id');
            $table->integer('target_id')->nullable()->index('fk_target_id');
            $table->string('name');
            $table->text('kra')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
            $table->integer('department_id')->index('fk_performance_contracts_department');
            $table->integer('financial_year_id')->index('fk_performance_contracts_financial_year');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('performance_contracts');
    }
};

