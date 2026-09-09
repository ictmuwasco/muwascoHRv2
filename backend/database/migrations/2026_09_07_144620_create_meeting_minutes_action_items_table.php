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
        Schema::create('meeting_minutes_action_items', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('minutes_id')->index('idx_actions_minutes')->comment('FK to meeting_minutes.id');
            $table->text('action');
            $table->integer('assigned_to')->nullable()->index('idx_actions_assigned_to')->comment('FK to employees.id');
            $table->integer('department_id')->nullable()->index('fk_actions_department')->comment('FK to departments.id');
            $table->date('due_date')->nullable()->index('idx_actions_due_date');
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'overdue', 'deferred', 'cancelled'])->default('pending')->index('idx_actions_status');
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('meeting_minutes_action_items');
    }
};

