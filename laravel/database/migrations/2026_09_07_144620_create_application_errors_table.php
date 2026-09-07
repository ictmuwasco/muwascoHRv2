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
        Schema::create('application_errors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('error_uuid', 36)->unique('uk_error_uuid');
            $table->unsignedBigInteger('error_group_id');
            $table->string('fingerprint', 191);
            $table->char('fingerprint_hash', 64);
            $table->string('request_id', 64)->nullable()->index('idx_request_id')->comment('Correlation id shared with audit_logs + response headers');
            $table->string('frontend_error_id', 64)->nullable()->index('idx_frontend_error_id')->comment('Browser-side generated id for client errors');
            $table->string('source', 10)->default('server')->comment('server|client');
            $table->string('environment', 20)->default('production');
            $table->string('application_version', 50)->nullable();
            $table->string('git_commit', 40)->nullable();
            $table->string('severity', 20)->default('MEDIUM')->index('idx_severity');
            $table->string('status', 20)->default('NEW');
            $table->string('category', 50)->default('SYSTEM_ERROR');
            $table->string('exception_class', 191)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('message')->nullable();
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->mediumText('stack_trace')->nullable()->comment('RESTRICTED - requires system_errors:view_sensitive');
            $table->string('http_method', 10)->nullable();
            $table->string('endpoint')->nullable()->index('idx_endpoint');
            $table->string('route_name', 100)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable()->index('idx_status_code');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('employee_id')->nullable()->index('idx_employee');
            $table->unsignedInteger('office_id')->nullable();
            $table->unsignedInteger('department_id')->nullable()->index('idx_department');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->mediumText('request_payload')->nullable()->comment('Sanitized JSON - RESTRICTED');
            $table->mediumText('request_query')->nullable()->comment('Sanitized JSON - RESTRICTED');
            $table->text('request_headers')->nullable()->comment('Sanitized subset - RESTRICTED');
            $table->json('response_metadata')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('component')->nullable();
            $table->string('browser', 100)->nullable();
            $table->string('browser_version', 50)->nullable();
            $table->string('operating_system', 100)->nullable();
            $table->string('device_type', 50)->nullable();
            $table->string('screen_size', 20)->nullable();
            $table->dateTime('created_at')->useCurrent()->index('idx_created_at');

            $table->index(['fingerprint_hash', 'created_at'], 'idx_fingerprint_created');
            $table->index(['error_group_id', 'created_at'], 'idx_group_created');
            $table->index(['source', 'created_at'], 'idx_source_created');
            $table->index(['user_id', 'created_at'], 'idx_user_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('application_errors');
    }
};

