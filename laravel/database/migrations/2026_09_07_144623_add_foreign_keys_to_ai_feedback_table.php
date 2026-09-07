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
        Schema::table('ai_feedback', function (Blueprint $table) {
            $table->foreign(['conversation_id'], 'fk_ai_feedback_conv')->references(['id'])->on('ai_conversations')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['message_id'], 'fk_ai_feedback_msg')->references(['id'])->on('ai_messages')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['user_id'], 'fk_ai_feedback_user')->references(['id'])->on('users')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled (shared inherited schema); restore from backups only (docs/PHASE_L1_REPORT.md).');
        Schema::table('ai_feedback', function (Blueprint $table) {
            $table->dropForeign('fk_ai_feedback_conv');
            $table->dropForeign('fk_ai_feedback_msg');
            $table->dropForeign('fk_ai_feedback_user');
        });
    }
};

