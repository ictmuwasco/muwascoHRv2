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
        Schema::create('user_consents', function (Blueprint $table) {
            $table->comment('Stores employee data protection consent records');
            $table->integer('id', true);
            $table->integer('user_id');
            $table->string('full_name');
            $table->string('national_id', 100);
            $table->string('consent_version', 10)->default('1.0');
            $table->boolean('consent_given')->nullable()->default(true);
            $table->dateTime('consent_date');
            $table->string('ip_address', 45);
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['user_id', 'consent_version'], 'uk_user_consent_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('user_consents');
    }
};

