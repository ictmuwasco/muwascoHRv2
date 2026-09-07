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
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->unique('uq_pref_user');
            $table->boolean('push_enabled')->default(true)->comment('Web Push attendance reminders');
            $table->boolean('sms_enabled')->default(true)->comment('SMS attendance reminders');
            $table->boolean('email_enabled')->default(false)->comment('Future channel - reserved');
            $table->boolean('reminders_mandated')->default(false)->comment('Org policy: cannot be changed by employee');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('notification_preferences');
    }
};

