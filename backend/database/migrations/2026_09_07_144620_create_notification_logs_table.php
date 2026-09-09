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
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->comment('Recipient user (users.id)');
            $table->unsignedInteger('employee_id')->nullable()->comment('Denormalised employees.id for reporting');
            $table->string('notification_type', 60)->default('attendance_clock_in_reminder');
            $table->string('channel', 20)->comment('web_push | sms | email | in_app ...');
            $table->string('stage', 30)->default('reminder_1')->comment('reminder_1 | sms_fallback | reminder_2 ...');
            $table->date('business_date')->index('idx_nl_business_date')->comment('Org-timezone attendance day');
            $table->string('status', 30)->default('pending')->comment('pending|sent|failed|failed_permanent|retrying|skipped|revoked');
            $table->string('recipient', 200)->nullable()->comment('E.164 phone (SMS) or endpoint host+hash prefix (push)');
            $table->string('provider_message_id', 100)->nullable()->index('idx_nl_provider_msg')->comment('SMS provider message id / request id echo');
            $table->string('failure_reason', 500)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['status', 'business_date'], 'idx_nl_status_date');
            $table->index(['user_id', 'business_date'], 'idx_nl_user_date');
            $table->unique(['user_id', 'business_date', 'notification_type', 'channel', 'stage'], 'uq_notification_once');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('Baseline down() disabled — inherited shared schema; restore from backups only (docs/PHASE_L1_REPORT.md).'); // Schema::dropIfExists('notification_logs');
    }
};

