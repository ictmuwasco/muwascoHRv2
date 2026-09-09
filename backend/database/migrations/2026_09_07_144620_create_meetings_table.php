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
        Schema::create('meetings', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('title')->comment('Meeting title / subject');
            $table->text('description')->nullable()->comment('Detailed description of the meeting');
            $table->text('agenda')->nullable()->comment('Meeting agenda items');
            $table->date('meeting_date')->index('idx_meetings_date')->comment('Date of the meeting');
            $table->time('start_time')->comment('Scheduled start time');
            $table->time('end_time')->comment('Scheduled end time');
            $table->string('location')->nullable()->comment('Meeting location or virtual link');
            $table->enum('status', ['scheduled', 'ongoing', 'completed', 'cancelled'])->nullable()->default('scheduled')->index('idx_meetings_status')->comment('Meeting lifecycle status');
            $table->integer('created_by')->index('idx_meetings_created_by')->comment('User ID who created the meeting (FK to users.id)');
            $table->string('attendance_token', 64)->nullable()->index('idx_meetings_attendance_token')->comment('Unique token for QR-code attendance check-in');
            $table->dateTime('notification_sent_at')->nullable()->comment('Timestamp when email notifications were sent (deferred feature)');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['meeting_date', 'status'], 'idx_meetings_date_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('meetings');
    }
};

