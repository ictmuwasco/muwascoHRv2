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
        Schema::create('strategies', function (Blueprint $table) {
            $table->integer('id');
            $table->integer('strategic_plan_id');
            $table->unsignedBigInteger('objective_id');
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->text('activity');
            $table->text('kpi');
            $table->text('target');
            $table->integer('Y1');
            $table->integer('Y2');
            $table->integer('Y3');
            $table->integer('Y4');
            $table->integer('Y5');
            $table->text('Comment');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('strategies');
    }
};

