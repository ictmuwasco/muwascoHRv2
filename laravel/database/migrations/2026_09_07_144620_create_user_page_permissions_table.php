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
        Schema::create('user_page_permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->index('idx_user_id');
            $table->string('module', 50)->default('')->index('idx_user_page_module');
            $table->string('action', 50)->default('view')->index('idx_user_page_action');
            $table->string('page_id', 100)->index('idx_page_id');
            $table->enum('permission_type', ['allow', 'deny'])->index('idx_permission_type');
            $table->integer('granted_by')->nullable()->index('idx_granted_by');
            $table->integer('updated_by')->nullable()->index('fk_upp_updated_by');
            $table->dateTime('granted_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();
            $table->boolean('active')->default(true)->index('idx_active');
            $table->text('notes')->nullable();

            $table->index(['user_id', 'module', 'action', 'active'], 'idx_user_module_action_active');
            $table->unique(['user_id', 'module', 'action'], 'uq_user_module_action');
            $table->unique(['user_id', 'page_id'], 'uq_user_page');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('user_page_permissions');
    }
};

