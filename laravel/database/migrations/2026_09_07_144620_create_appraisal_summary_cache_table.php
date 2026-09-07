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
        Schema::create('appraisal_summary_cache', function (Blueprint $table) {
            $table->integer('id');
            $table->integer('appraisal_cycle_id');
            $table->string('quarter', 10);
            $table->integer('total_completed')->nullable()->default(0);
            $table->decimal('average_score', 5)->nullable()->default(0);
            $table->timestamp('last_updated')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('appraisal_summary_cache');
    }
};

