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
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->comment('AI assistant chat threads â€” owner-scoped');
            $table->char('id', 36)->primary()->comment('Opaque conversation id (UUIDv4 generated server-side)');
            $table->integer('user_id')->comment('Owner â€” the ONLY user who may read/write this conversation');
            $table->string('title', 120)->nullable()->comment('Short label derived from the first user message');
            $table->unsignedInteger('message_count')->default(0);
            $table->dateTime('last_message_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['user_id', 'last_message_at'], 'idx_ai_conv_user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('ai_conversations');
    }
};

