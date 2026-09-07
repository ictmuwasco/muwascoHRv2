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
        Schema::table('leave_roster', function (Blueprint $table) {
            $table->foreign(['employee_id'], 'fk_leave_roster_employee')->references(['id'])->on('employees')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['financial_year_id'], 'fk_leave_roster_financial_year')->references(['id'])->on('financial_years')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled (shared inherited schema); restore from backups only (docs/PHASE_L1_REPORT.md).');
        Schema::table('leave_roster', function (Blueprint $table) {
            $table->dropForeign('fk_leave_roster_employee');
            $table->dropForeign('fk_leave_roster_financial_year');
        });
    }
};

