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
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->foreign(['conversation_id'], 'fk_ai_msg_conversation')->references(['id'])->on('ai_conversations')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['user_id'], 'fk_ai_msg_user')->references(['id'])->on('users')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled (shared inherited schema); restore from backups only (docs/PHASE_L1_REPORT.md).');
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropForeign('fk_ai_msg_conversation');
            $table->dropForeign('fk_ai_msg_user');
        });
    }
};

