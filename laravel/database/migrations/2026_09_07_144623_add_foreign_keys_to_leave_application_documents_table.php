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
        Schema::table('leave_application_documents', function (Blueprint $table) {
            $table->foreign(['leave_application_id'], 'fk_leave_doc_application')->references(['id'])->on('leave_applications')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['uploaded_by'], 'fk_leave_doc_uploader')->references(['id'])->on('users')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled (shared inherited schema); restore from backups only (docs/PHASE_L1_REPORT.md).');
        Schema::table('leave_application_documents', function (Blueprint $table) {
            $table->dropForeign('fk_leave_doc_application');
            $table->dropForeign('fk_leave_doc_uploader');
        });
    }
};

