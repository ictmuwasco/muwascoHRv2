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
        Schema::create('appraisal_revision_log', function (Blueprint $table) {
            $table->integer('id');
            $table->integer('original_appraisal_id');
            $table->integer('employee_id');
            $table->integer('appraisal_cycle_id');
            $table->integer('appraiser_id');
            $table->integer('reviewer_id');
            $table->text('decision');
            $table->text('reviewer_comment')->nullable();
            $table->string('final_status', 50);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('appraisal_revision_log');
    }
};

