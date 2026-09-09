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
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('role', 50)->index('idx_role')->comment('Role name (e.g., super_admin, hr_manager, employee)');
            $table->string('module', 50)->index('idx_module')->comment('Module/page name (e.g., employees, attendance, leave)');
            $table->string('action', 50)->comment('Action (e.g., view, create, edit, delete)');
            $table->boolean('is_granted')->nullable()->default(true)->comment('1 = granted, 0 = denied');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['role', 'module', 'action'], 'uk_role_module_action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('role_permissions');
    }
};

