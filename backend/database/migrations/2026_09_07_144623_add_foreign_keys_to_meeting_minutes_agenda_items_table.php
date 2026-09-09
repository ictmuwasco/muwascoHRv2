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
        Schema::table('meeting_minutes_agenda_items', function (Blueprint $table) {
            $table->foreign(['minutes_id'], 'fk_agenda_minutes')->references(['id'])->on('meeting_minutes')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign(['presenter_id'], 'fk_agenda_presenter')->references(['id'])->on('employees')->onUpdate('cascade')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled (shared inherited schema); restore from backups only (docs/PHASE_L1_REPORT.md).');
        Schema::table('meeting_minutes_agenda_items', function (Blueprint $table) {
            $table->dropForeign('fk_agenda_minutes');
            $table->dropForeign('fk_agenda_presenter');
        });
    }
};

