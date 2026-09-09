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
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('token_id', 64)->unique('uq_token_id');
            $table->integer('user_id')->index('idx_user_id');
            $table->dateTime('expires_at')->index('idx_expires');
            $table->dateTime('revoked_at')->nullable()->index('idx_revoked');
            $table->dateTime('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('refresh_tokens');
    }
};

