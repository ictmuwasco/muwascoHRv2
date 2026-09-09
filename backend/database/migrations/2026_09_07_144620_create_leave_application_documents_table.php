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
        Schema::create('leave_application_documents', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('leave_application_id')->index('idx_leave_application_id')->comment('Reference to leave_applications.id');
            $table->string('document_type', 50)->index('idx_document_type')->comment('Controlled document type');
            $table->string('original_filename')->comment('Original uploaded filename');
            $table->string('stored_filename')->comment('Secure server-side filename');
            $table->string('file_path', 500)->comment('Full path to stored file');
            $table->string('mime_type', 100)->comment('File MIME type');
            $table->bigInteger('file_size')->comment('File size in bytes');
            $table->integer('uploaded_by')->index('idx_uploaded_by')->comment('users.id who uploaded');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('leave_application_documents');
    }
};

