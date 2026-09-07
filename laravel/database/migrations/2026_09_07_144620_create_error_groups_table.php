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
        Schema::create('error_groups', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('fingerprint', 191)->index('idx_fingerprint')->comment('Human readable grouping key e.g. attendance.runtime.database_timeout');
            $table->char('fingerprint_hash', 64)->unique('uk_fingerprint_hash')->comment('SHA-256 of canonical fingerprint parts (unique lookup key)');
            $table->string('title')->comment('Short display title');
            $table->string('module', 100)->default('System')->index('idx_module')->comment('HR module (matches AuditService modules)');
            $table->string('category', 50)->default('SYSTEM_ERROR');
            $table->string('severity', 20)->default('MEDIUM')->comment('DEBUG|INFO|LOW|MEDIUM|HIGH|CRITICAL');
            $table->string('status', 20)->default('NEW')->comment('NEW|ACKNOWLEDGED|INVESTIGATING|FIXED|VERIFIED|RESOLVED|IGNORED');
            $table->string('environment', 20)->default('production');
            $table->string('exception_class', 191)->nullable();
            $table->text('sample_message')->nullable();
            $table->string('sample_endpoint')->nullable();
            $table->string('sample_http_method', 10)->nullable();
            $table->string('sample_file')->nullable();
            $table->unsignedInteger('sample_line')->nullable();
            $table->unsignedInteger('occurrence_count')->default(0);
            $table->unsignedInteger('affected_user_count')->default(0);
            $table->dateTime('first_seen_at')->index('idx_first_seen');
            $table->dateTime('last_seen_at')->index('idx_last_seen');
            $table->dateTime('last_notified_at')->nullable()->comment('Last alert emission (cooldown control)');
            $table->unsignedInteger('assigned_to')->nullable()->index('idx_assigned_to')->comment('users.id of assigned developer/admin');
            $table->dateTime('resolved_at')->nullable();
            $table->unsignedInteger('resolved_by')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->string('fixed_version', 50)->nullable()->comment('Application version that fixed the issue');
            $table->json('tags')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent();

            $table->index(['severity', 'status'], 'idx_severity_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('error_groups');
    }
};

