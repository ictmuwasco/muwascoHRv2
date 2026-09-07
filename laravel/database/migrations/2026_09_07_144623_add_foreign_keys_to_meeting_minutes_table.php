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
        Schema::table('meeting_minutes', function (Blueprint $table) {
            $table->foreign(['chairperson_id'], 'fk_minutes_chairperson')->references(['id'])->on('employees')->onUpdate('cascade')->onDelete('set null');
            $table->foreign(['meeting_id'], 'fk_minutes_meeting')->references(['id'])->on('meetings')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign(['prepared_by'], 'fk_minutes_prepared_by')->references(['id'])->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign(['published_by'], 'fk_minutes_published_by')->references(['id'])->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign(['secretary_id'], 'fk_minutes_secretary')->references(['id'])->on('employees')->onUpdate('cascade')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled (shared inherited schema); restore from backups only (docs/PHASE_L1_REPORT.md).');
        Schema::table('meeting_minutes', function (Blueprint $table) {
            $table->dropForeign('fk_minutes_chairperson');
            $table->dropForeign('fk_minutes_meeting');
            $table->dropForeign('fk_minutes_prepared_by');
            $table->dropForeign('fk_minutes_published_by');
            $table->dropForeign('fk_minutes_secretary');
        });
    }
};

