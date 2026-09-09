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
        Schema::table('error_group_users', function (Blueprint $table) {
            $table->foreign(['error_group_id'], 'fk_errgroupusers_group')->references(['id'])->on('error_groups')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled (shared inherited schema); restore from backups only (docs/PHASE_L1_REPORT.md).');
        Schema::table('error_group_users', function (Blueprint $table) {
            $table->dropForeign('fk_errgroupusers_group');
        });
    }
};

