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
        Schema::create('notifications', function (Blueprint $table) {
            $table->integer('id');
            $table->integer('user_id');
            $table->string('title');
            $table->text('message');
            $table->string('type', 100);
            $table->string('category', 100)->default('general');
            $table->enum('trigger_type', ['action', 'scheduled'])->nullable()->default('action');
            $table->string('trigger_source', 100)->nullable()->default('system');
            $table->boolean('is_read')->nullable()->default(false);
            $table->boolean('is_sent')->nullable()->default(true);
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->nullable()->default('medium');
            $table->string('related_entity', 100)->nullable();
            $table->integer('related_id')->nullable();
            $table->string('action_url', 500)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('notifications');
    }
};

