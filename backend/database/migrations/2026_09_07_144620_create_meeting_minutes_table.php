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
        Schema::create('meeting_minutes', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('meeting_id')->unique('uk_minutes_meeting')->comment('FK to meetings.id (one minutes set per meeting)');
            $table->string('reference_number', 50)->unique('uk_minutes_reference')->comment('Official minutes reference, e.g. MMS-{meeting_id}-{year}');
            $table->date('meeting_date')->nullable()->comment('Snapshot of meeting.meeting_date at creation');
            $table->time('start_time')->nullable()->comment('Snapshot of meeting.start_time');
            $table->time('end_time')->nullable()->comment('Snapshot of meeting.end_time');
            $table->string('venue')->nullable()->comment('Snapshot of meeting.location');
            $table->integer('chairperson_id')->nullable()->index('fk_minutes_chairperson')->comment('FK to employees.id');
            $table->integer('secretary_id')->nullable()->index('fk_minutes_secretary')->comment('FK to employees.id');
            $table->enum('status', ['draft', 'published'])->default('draft')->index('idx_minutes_status')->comment('Lifecycle: draft -> published (immutable until reopened)');
            $table->integer('version')->default(1)->comment('Version number; bumped on reopen/amend');
            $table->text('amendment_reason')->nullable()->comment('Why the minutes were reopened/amended');
            $table->text('aob')->nullable()->comment('Any-other-business catch-all text');
            $table->date('next_meeting_date')->nullable();
            $table->time('next_meeting_time')->nullable();
            $table->string('next_meeting_venue')->nullable();
            $table->text('next_meeting_notes')->nullable();
            $table->integer('prepared_by')->nullable()->index('idx_minutes_prepared_by')->comment('FK to users.id (minutes author)');
            $table->dateTime('prepared_at')->nullable();
            $table->integer('reviewed_by')->nullable()->comment('FK to users.id');
            $table->dateTime('reviewed_at')->nullable();
            $table->integer('approved_by')->nullable()->comment('FK to users.id');
            $table->dateTime('approved_at')->nullable();
            $table->integer('published_by')->nullable()->index('idx_minutes_published_by')->comment('FK to users.id');
            $table->dateTime('published_at')->nullable();
            $table->timestamp('created_at')->useCurrent()->index('idx_minutes_created_at');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('meeting_minutes');
    }
};

