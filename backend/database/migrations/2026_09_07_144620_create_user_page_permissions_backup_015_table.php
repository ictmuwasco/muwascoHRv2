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
        Schema::create('user_page_permissions_backup_015', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->default(0);
            $table->integer('user_id');
            $table->string('page_id', 100);
            $table->enum('permission_type', ['allow', 'deny']);
            $table->integer('granted_by')->nullable();
            $table->dateTime('granted_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('user_page_permissions_backup_015');
    }
};

