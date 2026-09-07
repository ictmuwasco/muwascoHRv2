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
        Schema::table('meeting_minutes_action_items', function (Blueprint $table) {
            $table->foreign(['assigned_to'], 'fk_actions_assigned_to')->references(['id'])->on('employees')->onUpdate('cascade')->onDelete('set null');
            $table->foreign(['department_id'], 'fk_actions_department')->references(['id'])->on('departments')->onUpdate('cascade')->onDelete('set null');
            $table->foreign(['minutes_id'], 'fk_actions_minutes')->references(['id'])->on('meeting_minutes')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled (shared inherited schema); restore from backups only (docs/PHASE_L1_REPORT.md).');
        Schema::table('meeting_minutes_action_items', function (Blueprint $table) {
            $table->dropForeign('fk_actions_assigned_to');
            $table->dropForeign('fk_actions_department');
            $table->dropForeign('fk_actions_minutes');
        });
    }
};

