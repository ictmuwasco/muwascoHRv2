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
        Schema::create('meeting_minutes_aob_items', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('minutes_id')->index('idx_aob_minutes')->comment('FK to meeting_minutes.id');
            $table->string('item');
            $table->text('discussion')->nullable();
            $table->text('decision')->nullable();
            $table->text('action')->nullable();
            $table->integer('responsible_id')->nullable()->index('fk_aob_responsible')->comment('FK to employees.id');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('meeting_minutes_aob_items');
    }
};

