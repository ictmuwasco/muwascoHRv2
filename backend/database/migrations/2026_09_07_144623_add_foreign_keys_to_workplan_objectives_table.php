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
        Schema::table('workplan_objectives', function (Blueprint $table) {
            $table->foreign(['parent_objective_id'], 'fk_wpo_parent_objective')->references(['id'])->on('workplan_objectives')->onUpdate('restrict')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled (shared inherited schema); restore from backups only (docs/PHASE_L1_REPORT.md).');
        Schema::table('workplan_objectives', function (Blueprint $table) {
            $table->dropForeign('fk_wpo_parent_objective');
        });
    }
};

