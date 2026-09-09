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
        Schema::create('leave_history', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('leave_application_id');
            $table->string('action', 50);
            $table->integer('performed_by');
            $table->unsignedBigInteger('delegation_id')->nullable();
            $table->integer('acted_for_user_id')->nullable();
            $table->text('comments')->nullable();
            $table->timestamp('performed_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('leave_history');
    }
};

