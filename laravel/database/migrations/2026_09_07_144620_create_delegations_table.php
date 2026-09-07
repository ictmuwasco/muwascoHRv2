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
        Schema::create('delegations', function (Blueprint $table) {
            $table->comment('Temporary delegation / acting authority (never a role change)');
            $table->bigIncrements('id');
            $table->integer('delegator_user_id')->comment('The supervisor whose authority is delegated');
            $table->integer('delegate_user_id')->comment('The user receiving the temporary authority');
            $table->string('delegated_role', 50)->comment('The DELEGATOR\'S role whose authority is transferred (role-aware Â§7)');
            $table->enum('scope_type', ['department', 'section', 'subsection', 'organization'])->comment('Delegated organizational scope (Â§8)');
            $table->unsignedInteger('scope_id')->default(0)->comment('Unit id for the scope (0 for organization-wide)');
            $table->text('permissions')->comment('Explicit JSON snapshot of delegated "module:action" strings (Â§22)');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('reason', 500)->nullable();
            $table->enum('status', ['pending', 'approved', 'active', 'expired', 'cancelled', 'rejected'])->default('pending');
            $table->integer('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['delegate_user_id', 'status', 'start_date', 'end_date'], 'idx_delegations_delegate');
            $table->index(['delegator_user_id', 'status'], 'idx_delegations_delegator');
            $table->index(['status', 'start_date', 'end_date'], 'idx_delegations_status_window');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('delegations');
    }
};

