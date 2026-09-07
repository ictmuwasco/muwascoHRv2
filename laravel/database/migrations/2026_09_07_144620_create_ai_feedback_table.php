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
        Schema::create('ai_feedback', function (Blueprint $table) {
            $table->comment('Per-message AI feedback (one vote per user, re-voting updates)');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('message_id');
            $table->char('conversation_id', 36)->index('fk_ai_feedback_conv');
            $table->integer('user_id')->index('fk_ai_feedback_user')->comment('Must be the conversation owner (enforced again in the service layer)');
            $table->enum('rating', ['helpful', 'not_helpful']);
            $table->string('comment', 500)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['rating', 'created_at'], 'idx_ai_feedback_rating');
            $table->unique(['message_id', 'user_id'], 'uk_ai_feedback_message_user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('ai_feedback');
    }
};

