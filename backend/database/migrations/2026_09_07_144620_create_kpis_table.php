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
        Schema::create('kpis', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('performance_contract_id')->index('idx_performance_contract');
            $table->string('kpi_name', 500);
            $table->text('kpi_description')->nullable();
            $table->string('target')->nullable();
            $table->string('unit_of_measure', 100)->nullable();
            $table->string('data_source')->nullable();
            $table->string('frequency', 50)->nullable();
            $table->string('responsible_person')->nullable()->index('idx_responsible_person');
            $table->decimal('weight', 5)->nullable()->default(0);
            $table->string('y1_target', 100)->nullable();
            $table->string('y2_target', 100)->nullable();
            $table->string('y3_target', 100)->nullable();
            $table->string('y4_target', 100)->nullable();
            $table->string('y5_target', 100)->nullable();
            $table->string('y1_score', 100)->nullable();
            $table->string('y2_score', 100)->nullable();
            $table->string('y3_score', 100)->nullable();
            $table->string('y4_score', 100)->nullable();
            $table->string('y5_score', 100)->nullable();
            $table->integer('created_by')->nullable()->index('idx_created_by');
            $table->integer('updated_by')->nullable()->index('updated_by');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('kpis');
    }
};

