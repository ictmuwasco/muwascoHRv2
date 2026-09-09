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
        Schema::create('meeting_minutes_decisions', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('minutes_id')->index('idx_decisions_minutes')->comment('FK to meeting_minutes.id');
            $table->string('decision_number', 20)->nullable()->comment('e.g. D-01');
            $table->text('resolution');
            $table->integer('responsible_id')->nullable()->index('idx_decisions_responsible')->comment('FK to employees.id');
            $table->integer('department_id')->nullable()->index('fk_decisions_department')->comment('FK to departments.id');
            $table->date('due_date')->nullable()->index('idx_decisions_due_date');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'deferred', 'cancelled'])->default('pending')->index('idx_decisions_status');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('meeting_minutes_decisions');
    }
};

