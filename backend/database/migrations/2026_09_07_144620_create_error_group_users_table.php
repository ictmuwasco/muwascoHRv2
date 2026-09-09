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
        Schema::create('error_group_users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('error_group_id');
            $table->unsignedInteger('user_id')->index('idx_user');
            $table->dateTime('first_seen_at');

            $table->unique(['error_group_id', 'user_id'], 'uk_group_user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('error_group_users');
    }
};

