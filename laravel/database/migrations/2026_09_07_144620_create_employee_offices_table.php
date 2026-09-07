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
        Schema::create('employee_offices', function (Blueprint $table) {
            $table->integer('id');
            $table->string('employee_id', 50)->nullable();
            $table->integer('office_id')->nullable();
            $table->boolean('is_primary')->nullable()->default(true);
            $table->date('assigned_date')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('employee_offices');
    }
};

