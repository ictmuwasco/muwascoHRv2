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
        Schema::create('workplan_objective_cycles', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('objective_id');
            $table->integer('cycle_id')->index('cycle_id');

            $table->unique(['objective_id', 'cycle_id'], 'unique_objective_cycle');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('workplan_objective_cycles');
    }
};

