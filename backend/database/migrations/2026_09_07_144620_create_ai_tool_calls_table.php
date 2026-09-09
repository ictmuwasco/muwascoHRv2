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
        Schema::create('ai_tool_calls', function (Blueprint $table) {
            $table->comment('Controlled HR data tool invocations performed for AI turns');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('message_id')->index('idx_ai_tool_msg');
            $table->char('conversation_id', 36)->index('fk_ai_tool_conv');
            $table->integer('user_id');
            $table->string('tool_name', 100)->comment('Registered controlled-tool name â€” never free-form SQL');
            $table->text('arguments')->nullable()->comment('JSON of VALIDATED arguments (after authorization + scoping)');
            $table->enum('result_status', ['ok', 'denied', 'error'])->default('ok');
            $table->string('result_summary', 500)->nullable()->comment('Non-sensitive summary (counts / labels only)');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['tool_name', 'created_at'], 'idx_ai_tool_name');
            $table->index(['user_id', 'created_at'], 'idx_ai_tool_user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('ai_tool_calls');
    }
};

