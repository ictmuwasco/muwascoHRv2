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
        Schema::table('meeting_invitations', function (Blueprint $table) {
            $table->foreign(['attendance_marked_by'], 'fk_invitations_attendance_marked_by')->references(['id'])->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign(['employee_id'], 'fk_invitations_employee')->references(['id'])->on('employees')->onUpdate('cascade')->onDelete('set null');
            $table->foreign(['invited_by'], 'fk_invitations_invited_by')->references(['id'])->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign(['meeting_id'], 'fk_invitations_meeting')->references(['id'])->on('meetings')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled (shared inherited schema); restore from backups only (docs/PHASE_L1_REPORT.md).');
        Schema::table('meeting_invitations', function (Blueprint $table) {
            $table->dropForeign('fk_invitations_attendance_marked_by');
            $table->dropForeign('fk_invitations_employee');
            $table->dropForeign('fk_invitations_invited_by');
            $table->dropForeign('fk_invitations_meeting');
        });
    }
};

