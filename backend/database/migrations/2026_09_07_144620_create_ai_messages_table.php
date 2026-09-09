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
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->comment('AI chat turns â€” sanitized content only');
            $table->bigIncrements('id');
            $table->char('conversation_id', 36);
            $table->integer('user_id')->comment('Denormalized owner for cheap owner-scoped queries');
            $table->enum('role', ['user', 'assistant', 'system']);
            $table->mediumText('content')->comment('Sanitized plain text; never HTML, never raw provider payloads');
            $table->text('sources')->nullable()->comment('JSON array of source chips, e.g. [{"type":"data","label":"..."}]');
            $table->text('tools_used')->nullable()->comment('JSON array of controlled tool names used for this turn');
            $table->string('provider', 50)->nullable();
            $table->string('model', 100)->nullable();
            $table->enum('status', ['ok', 'error'])->default('ok');
            $table->string('error_code', 50)->nullable()->comment('Sanitized failure code (e.g. TIMEOUT) â€” never provider internals');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['conversation_id', 'id'], 'idx_ai_msg_conv');
            $table->index(['user_id', 'id'], 'idx_ai_msg_user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('ai_messages');
    }
};

