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
        Schema::table('delegations', function (Blueprint $table) {
            $table->foreign(['delegate_user_id'], 'fk_delegations_delegate')->references(['id'])->on('users')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['delegator_user_id'], 'fk_delegations_delegator')->references(['id'])->on('users')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled (shared inherited schema); restore from backups only (docs/PHASE_L1_REPORT.md).');
        Schema::table('delegations', function (Blueprint $table) {
            $table->dropForeign('fk_delegations_delegate');
            $table->dropForeign('fk_delegations_delegator');
        });
    }
};

