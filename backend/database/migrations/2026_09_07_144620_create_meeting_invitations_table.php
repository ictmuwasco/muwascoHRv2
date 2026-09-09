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
        Schema::create('meeting_invitations', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('meeting_id')->index('idx_invitations_meeting')->comment('FK to meetings.id');
            $table->integer('employee_id')->nullable()->index('idx_invitations_employee')->comment('FK to employees.id â€” NULL allowed for walk-in attendees not linked to an employee record');
            $table->integer('invited_by')->nullable()->index('fk_invitations_invited_by')->comment('User ID of the person who sent the invitation (FK to users.id)');
            $table->dateTime('invited_at')->nullable()->comment('Timestamp when the invitation was sent');
            $table->enum('invitation_type', ['hr_invited', 'qr_checkin'])->nullable()->default('hr_invited')->index('idx_invitations_type')->comment('How the attendee was added');
            $table->enum('response_status', ['pending', 'accepted', 'declined', 'tentative'])->nullable()->default('pending')->index('idx_invitations_response')->comment('Employee response to the invitation');
            $table->dateTime('responded_at')->nullable()->comment('Timestamp when the employee responded');
            $table->enum('attendance_status', ['present', 'absent', 'excused', 'not_marked'])->nullable()->default('not_marked')->index('idx_invitations_attendance')->comment('Actual attendance recorded');
            $table->dateTime('attendance_marked_at')->nullable()->comment('Timestamp when attendance was marked');
            $table->integer('attendance_marked_by')->nullable()->index('fk_invitations_attendance_marked_by')->comment('User ID who marked the attendance (usually the employee self via QR or HR)');
            $table->text('notes')->nullable()->comment('Optional notes (e.g., reason for declining)');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['meeting_id', 'attendance_status'], 'idx_invitations_meeting_attendance');
            $table->index(['meeting_id', 'response_status'], 'idx_invitations_meeting_response');
            $table->unique(['meeting_id', 'employee_id'], 'uk_meeting_employee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('meeting_invitations');
    }
};

