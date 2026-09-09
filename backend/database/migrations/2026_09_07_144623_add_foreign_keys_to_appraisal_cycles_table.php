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
        Schema::table('appraisal_cycles', function (Blueprint $table) {
            $table->foreign(['financial_year_id'], 'fk_ac_financial_year')->references(['id'])->on('financial_years')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled (shared inherited schema); restore from backups only (docs/PHASE_L1_REPORT.md).');
        Schema::table('appraisal_cycles', function (Blueprint $table) {
            $table->dropForeign('fk_ac_financial_year');
        });
    }
};

