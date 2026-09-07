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
        Schema::create('activities', function (Blueprint $table) {
            $table->integer('id');
            $table->integer('strategy_id');
            $table->text('activity');
            $table->string('kpi')->nullable();
            $table->string('target')->nullable();
            $table->decimal('Y1', 10)->nullable();
            $table->decimal('Y2', 10)->nullable();
            $table->decimal('Y3', 10)->nullable();
            $table->decimal('Y4', 10)->nullable();
            $table->decimal('Y5', 10)->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('activities');
    }
};

