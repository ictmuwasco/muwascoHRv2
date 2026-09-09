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
        Schema::table('user_page_permissions', function (Blueprint $table) {
            $table->foreign(['granted_by'], 'fk_upp_granted_by')->references(['id'])->on('users')->onUpdate('restrict')->onDelete('set null');
            $table->foreign(['updated_by'], 'fk_upp_updated_by')->references(['id'])->on('users')->onUpdate('restrict')->onDelete('set null');
            $table->foreign(['user_id'], 'fk_upp_user_id')->references(['id'])->on('users')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['user_id'], 'user_page_permissions_ibfk_1')->references(['id'])->on('users')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['granted_by'], 'user_page_permissions_ibfk_2')->references(['id'])->on('users')->onUpdate('restrict')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled (shared inherited schema); restore from backups only (docs/PHASE_L1_REPORT.md).');
        Schema::table('user_page_permissions', function (Blueprint $table) {
            $table->dropForeign('fk_upp_granted_by');
            $table->dropForeign('fk_upp_updated_by');
            $table->dropForeign('fk_upp_user_id');
            $table->dropForeign('user_page_permissions_ibfk_1');
            $table->dropForeign('user_page_permissions_ibfk_2');
        });
    }
};

