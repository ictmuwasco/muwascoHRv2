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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->nullable()->index('idx_user_id')->comment('Authenticated actor user id');
            $table->unsignedInteger('employee_id')->nullable()->index('idx_audit_employee_id')->comment('Affected employee (employees.id)');
            $table->unsignedInteger('office_id')->nullable()->index('idx_audit_office_id')->comment('Office id recorded against the action');
            $table->string('office_name')->nullable()->comment('Office name at time of event');
            $table->decimal('latitude', 10, 8)->nullable()->comment('GPS latitude (only when device provided a fix)');
            $table->decimal('longitude', 11, 8)->nullable()->comment('GPS longitude (only when device provided a fix)');
            $table->unsignedInteger('location_accuracy')->nullable()->comment('GPS accuracy in metres (NULL when no fix)');
            $table->string('location_source', 20)->nullable()->index('idx_audit_location_source')->comment('GPS|OFFICE|USER_SELECTED|UNVERIFIED|IP|UNKNOWN');
            $table->string('request_id', 64)->nullable()->index('idx_audit_request_id')->comment('Correlation/request id for end-to-end tracing');
            $table->string('channel', 50)->nullable()->index('idx_audit_channel')->comment('WEB|MOBILE_WEB|DESKTOP|ADMIN_PORTAL|API|SYSTEM|BACKGROUND_JOB');
            $table->string('device_type', 50)->nullable()->comment('mobile|tablet|desktop|bot|unknown');
            $table->string('browser', 100)->nullable()->comment('Best-effort browser family parsed from user agent');
            $table->string('operating_system', 100)->nullable()->comment('Best-effort OS parsed from user agent');
            $table->string('user_name_snapshot')->nullable()->comment('Display name of the actor at time of event');
            $table->string('user_role_snapshot', 100)->nullable()->comment('Role of the actor at time of event');
            $table->string('action', 100)->index('idx_action')->comment('Standardized action (LOGIN, CREATE, UPDATE, ...)');
            $table->string('module', 100)->index('idx_module')->comment('Module affected (Employees, Leave, Authentication, ...)');
            $table->text('description')->nullable()->comment('Human readable description');
            $table->string('target_type', 100)->nullable()->index('idx_target_type')->comment('Type of target record (Employee, LeaveRequest, ...)');
            $table->unsignedBigInteger('target_id')->nullable()->index('idx_target_id')->comment('Primary key of the target record');
            $table->string('target_name')->nullable()->comment('Display name of the target record');
            $table->string('ip_address', 45)->nullable()->index('idx_ip_address')->comment('Request IP (captured on the backend)');
            $table->text('user_agent')->nullable()->comment('Request user agent');
            $table->string('location')->nullable()->comment('Best-effort IP-derived location');
            $table->json('old_values')->nullable()->comment('Snapshot before the change');
            $table->json('new_values')->nullable()->comment('Snapshot after the change');
            $table->json('metadata')->nullable()->comment('Additional non-sensitive metadata');
            $table->string('status', 50)->default('SUCCESS')->index('idx_status')->comment('SUCCESS | FAILED');
            $table->dateTime('created_at')->useCurrent()->index('idx_created_at');

            $table->index(['target_type', 'target_id'], 'idx_audit_target_type_id');
            $table->index(['module', 'action'], 'idx_module_action');
            $table->index(['user_id', 'action'], 'idx_user_action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('audit_logs');
    }
};

