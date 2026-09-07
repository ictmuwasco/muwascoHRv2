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
        Schema::create('meeting_minutes_agenda_items', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('minutes_id')->index('idx_agenda_minutes')->comment('FK to meeting_minutes.id');
            $table->integer('position')->default(1)->comment('Agenda ordering (1-based)');
            $table->string('agenda_number', 20)->nullable()->comment('e.g. 1.0, 2.1');
            $table->string('title');
            $table->integer('presenter_id')->nullable()->index('fk_agenda_presenter')->comment('FK to employees.id');
            $table->text('discussion')->nullable();
            $table->text('decision')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('meeting_minutes_agenda_items');
    }
};

