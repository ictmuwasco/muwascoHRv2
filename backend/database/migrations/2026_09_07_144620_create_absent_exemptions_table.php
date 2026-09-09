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
        Schema::create('absent_exemptions', function (Blueprint $table) {
            $table->integer('id');
            $table->integer('employee_id');
            $table->date('exemption_date');
            $table->text('reason')->nullable();
            $table->integer('exempted_by');
            $table->dateTime('exempted_at');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('absent_exemptions');
    }
};

