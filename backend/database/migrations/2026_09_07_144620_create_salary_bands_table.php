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
        Schema::create('salary_bands', function (Blueprint $table) {
            $table->string('scale_id', 10);
            $table->decimal('min_salary', 10)->nullable();
            $table->decimal('max_salary', 10)->nullable();
            $table->decimal('house_allowance', 10)->nullable();
            $table->decimal('commuter_allowance', 10)->nullable();
            $table->decimal('leave_allowance', 10)->nullable();
            $table->decimal('Dirty_allowance', 10)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('salary_bands');
    }
};

