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
        Schema::create('cycle_indicators', function (Blueprint $table) {
            $table->integer('id');
            $table->integer('cycle_id');
            $table->integer('indicator_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('max_score');
            $table->integer('section_id')->nullable();
            $table->integer('subsection_id')->nullable();
            $table->integer('department_id')->nullable();
            $table->string('role', 50)->nullable();
            $table->boolean('is_active')->nullable()->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('cycle_indicators');
    }
};

