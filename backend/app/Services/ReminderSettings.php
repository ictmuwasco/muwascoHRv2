<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AppTime;

/**
 * Centralised reader for attendance reminder configuration.
 *
 * Every knob lives in the environment (.env) - no magic numbers and
 * no hardcoded times anywhere else in the notification stack.
 * (Non-final so unit tests can mock policy resolution.)
 */
class ReminderSettings
{
    public const NOTIFICATION_TYPE = 'attendance_clock_in_reminder';

    public const STAGE_REMINDER_1   = 'reminder_1';
    public const STAGE_SMS_FALLBACK = 'sms_fallback';
    public const STAGE_REMINDER_2   = 'reminder_2';

    /** Global master switches (organisation level). */
    public function pushEnabled(): bool
    {
        return $this->bool('ATTENDANCE_PUSH_REMINDER_ENABLED', true);
    }

    public function smsEnabled(): bool
    {
        return $this->bool('ATTENDANCE_SMS_REMINDER_ENABLED', true);
    }

    /** Local clock time "HH:MM" of the primary reminder. */
    public function reminderTime(): string
    {
        return (string) env('ATTENDANCE_REMINDER_TIME', '08:00');
    }

    public function reminderMinutes(): int
    {
        return AppTime::parseClockTime($this->reminderTime());
    }

    public function smsFallbackEnabled(): bool
    {
        return $this->bool('ATTENDANCE_SMS_FALLBACK_ENABLED', true);
    }

    public function smsFallbackDelayMinutes(): int
    {
        return max(0, (int) env('ATTENDANCE_SMS_FALLBACK_DELAY_MINUTES', 15));
    }

    /**
     * SMS policy: "fallback" sends SMS only when push is unavailable,
     * disabled or failed; "always" sends both channels.
     */
    public function smsPolicy(): string
    {
        $policy = strtolower((string) env('ATTENDANCE_SMS_POLICY', 'fallback'));
        return in_array($policy, ['fallback', 'always'], true) ? $policy : 'fallback';
    }

    public function secondReminderEnabled(): bool
    {
        return $this->bool('ATTENDANCE_SECOND_REMINDER_ENABLED', false);
    }

    public function secondReminderMinutes(): int
    {
        return max(0, (int) env('ATTENDANCE_SECOND_REMINDER_MINUTES', 120));
    }

    /** Cost control: hard cap on SMS attempts per employee per day. */
    public function maxSmsPerDay(): int
    {
        return max(1, (int) env('ATTENDANCE_MAX_SMS_PER_DAY', 2));
    }

    /** Retry budget for temporary provider failures, per log row. */
    public function maxSmsAttempts(): int
    {
        return max(1, (int) env('ATTENDANCE_MAX_SMS_ATTEMPTS', 3));
    }

    /**
     * When true, employees cannot disable attendance reminders
     * (organisation-mandated communication).
     */
    public function remindersMandatory(): bool
    {
        return $this->bool('ATTENDANCE_REMINDERS_MANDATORY', false);
    }

    private function bool(string $key, bool $default): bool
    {
        $raw = env($key, null);
        if ($raw === null || $raw === '') {
            return $default;
        }
        return in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'on'], true);
    }
}
