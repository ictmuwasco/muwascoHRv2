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
        Schema::create('workplan_logs', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('objective_id')->index('idx_wpl_objective');
            $table->unsignedInteger('user_id')->nullable()->index('idx_wpl_user');
            $table->string('action_type', 50)->index('idx_wpl_action_type')->comment('progress_update|status_change|evidence_upload|objective_update');
            $table->json('old_values')->nullable()->comment('Snapshot of changed fields before the update');
            $table->json('new_values')->nullable()->comment('Snapshot of changed fields after the update');
            $table->unsignedTinyInteger('progress_percent')->nullable();
            $table->string('status', 50)->nullable();
            $table->text('evidence_path')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent()->index('idx_wpl_created_at');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('workplan_logs');
    }
};

