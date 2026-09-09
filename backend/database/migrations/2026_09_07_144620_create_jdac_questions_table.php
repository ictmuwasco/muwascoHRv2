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
        Schema::create('jdac_questions', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('section', 100)->index('section');
            $table->integer('section_order')->default(0);
            $table->text('question_text');
            $table->enum('question_type', ['text', 'textarea', 'select', 'multiselect', 'rating', 'file'])->nullable()->default('textarea');
            $table->json('options')->nullable();
            $table->boolean('is_required')->nullable()->default(true);
            $table->boolean('is_active')->nullable()->default(true)->index('is_active');
            $table->dateTime('created_at')->nullable()->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('jdac_questions');
    }
};

