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
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->comment('AI completion telemetry â€” metadata only, never message content');
            $table->bigIncrements('id');
            $table->integer('user_id')->nullable()->comment('SET NULL so usage history survives user deletion');
            $table->char('conversation_id', 36)->nullable();
            $table->string('provider', 50)->comment('Driver label from config (local | nvidia_nim | openai_compatible)');
            $table->string('model', 100)->nullable();
            $table->string('status', 30)->comment('AiCompletionResult status (SUCCESS, TIMEOUT, ...)');
            $table->unsignedInteger('attempts')->default(1);
            $table->unsignedInteger('http_status')->nullable();
            $table->unsignedInteger('prompt_chars')->default(0);
            $table->unsignedInteger('response_chars')->default(0);
            $table->string('error_code', 50)->nullable();
            $table->string('request_id', 64)->nullable()->comment('X-Request-ID correlation with audit/error tracking');
            $table->dateTime('created_at')->useCurrent()->index('idx_ai_usage_created');

            $table->index(['provider', 'status', 'created_at'], 'idx_ai_usage_provider');
            $table->index(['user_id', 'created_at'], 'idx_ai_usage_user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('ai_usage_logs');
    }
};

