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
        Schema::create('workplan_objectives', function (Blueprint $table) {
            $table->integer('strategic_target_id')->nullable()->index('idx_wpo_target')->comment('Organisation-level strategic target');
            $table->integer('id', true);
            $table->integer('performance_contract_id')->nullable()->index('performance_contract_id')->comment('Department performance contract backing this activity (NULL = organisation-level activity)');
            $table->text('objective');
            $table->string('kpi');
            $table->string('measure_unit', 50)->comment('e.g., Percentage, Number');
            $table->integer('section_id')->nullable()->index('idx_section_id');
            $table->integer('subsection_id')->nullable()->index('idx_subsection_id');
            $table->enum('level', ['organisation', 'department', 'section', 'subsection'])->nullable()->index('idx_wpo_level')->comment('Position in the organisational cascade');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
            $table->string('cycle_ids')->nullable();
            $table->string('Y1')->nullable();
            $table->string('Y2')->nullable();
            $table->string('Y3')->nullable();
            $table->string('Y4')->nullable();
            $table->string('Y5')->nullable();
            $table->integer('goal_id')->nullable()->index('idx_wpo_goal')->comment('Strategic goal perspective (goals.id)');
            $table->integer('parent_objective_id')->nullable()->index('idx_wpo_parent')->comment('Self-referencing FK to parent objective');
            $table->unsignedTinyInteger('progress_percent')->default(0)->comment('0-100 completion percentage');
            $table->enum('status', ['not_started', 'in_progress', 'completed', 'at_risk', 'off_track'])->default('not_started')->index('idx_wpo_status');
            $table->text('evidence_path')->nullable()->comment('Path or reference to uploaded evidence');
            $table->decimal('budget_amount', 15)->default(0)->comment('Allocated budget');
            $table->text('resource_notes')->nullable()->comment('Free-text resource / funding notes');
            $table->integer('responsible_officer_id')->nullable()->index('idx_wpo_officer')->comment('Employee responsible (employees.id)');
            $table->integer('created_by')->nullable()->index('idx_wpo_created_by')->comment('User who created the objective');
            $table->date('planned_start_date')->nullable()->comment('Plan start date');
            $table->date('planned_end_date')->nullable()->comment('Plan end date');
            $table->date('actual_completion_date')->nullable()->comment('Actual completion date');
            $table->json('dependencies')->nullable()->comment('JSON array of cross-workplan dependency links');
            $table->boolean('is_integrated')->default(false)->index('idx_wpo_integrated')->comment('1 = visible in org-level integrated view');
            $table->boolean('soft_deleted')->default(false)->index('idx_wpo_soft_deleted')->comment('Soft-delete flag');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('workplan_objectives');
    }
};

