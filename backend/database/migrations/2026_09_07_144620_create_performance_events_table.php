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
        Schema::create('performance_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('request_id', 64)->nullable()->index('idx_perf_request');
            $table->string('endpoint')->nullable()->index('idx_perf_endpoint');
            $table->string('http_method', 10)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('threshold_level', 20)->default('warning')->comment('warning|slow|critical');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('memory_kb')->nullable();
            $table->unsignedInteger('query_count')->nullable();
            $table->unsignedInteger('query_ms')->nullable();
            $table->unsignedInteger('max_query_ms')->nullable();
            $table->unsignedInteger('auth_ms')->nullable();
            $table->unsignedInteger('authorization_ms')->nullable();
            $table->string('environment', 20)->nullable();
            $table->string('application_version', 50)->nullable();
            $table->dateTime('created_at')->useCurrent()->index('idx_perf_created');
            $table->unsignedInteger('controller_ms')->nullable();
            $table->unsignedInteger('serialization_ms')->nullable();
            $table->unsignedInteger('ai_provider_ms')->nullable();
            $table->unsignedSmallInteger('ai_provider_calls')->nullable();
            $table->unsignedSmallInteger('ai_tool_calls')->nullable();
            $table->unsignedInteger('external_http_ms')->nullable();

            $table->index(['threshold_level', 'created_at'], 'idx_perf_level_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('performance_events');
    }
};

