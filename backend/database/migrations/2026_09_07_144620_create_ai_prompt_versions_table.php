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
        Schema::create('ai_prompt_versions', function (Blueprint $table) {
            $table->comment('Versioned AI system prompts (the active row drives every chat)');
            $table->increments('id');
            $table->string('name', 80)->comment('Logical prompt name, e.g. muwasco_hr_assistant');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(false)->comment('Exactly one active row per name is enforced by the service layer');
            $table->mediumText('content')->comment('System prompt text (no secrets, no PII, no provider details)');
            $table->string('description')->nullable();
            $table->integer('created_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['name', 'is_active'], 'idx_ai_prompt_active');
            $table->unique(['name', 'version'], 'uk_ai_prompt_name_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('ai_prompt_versions');
    }
};

