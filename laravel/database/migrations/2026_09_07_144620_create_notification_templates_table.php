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
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->integer('id');
            $table->string('name');
            $table->string('title_template', 500);
            $table->text('message_template');
            $table->string('type', 100);
            $table->string('category', 100)->default('general');
            $table->string('trigger_source', 100);
            $table->enum('default_priority', ['low', 'medium', 'high', 'urgent'])->nullable()->default('medium');
            $table->boolean('is_active')->nullable()->default(true);
            $table->json('roles_target')->nullable();
            $table->string('action_url_template', 500)->nullable();
            $table->integer('expires_after_days')->nullable()->default(30);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('notification_templates');
    }
};

