<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Helpers\AppTime;
use App\Repositories\Contracts\NotificationLogRepositoryInterface;
use App\Repositories\Contracts\NotificationPreferenceRepositoryInterface;
use App\Services\AttendanceReminderEligibilityService;
use App\Services\Contracts\CalendarContextServiceInterface;
use App\Services\ReminderSettings;
use Throwable;

/**
 * Notification Router
 *
 * Receives an eligibility-approved reminder decision and routes it to
 * the configured channels with stage policy, preference enforcement,
 * duplicate-proof logging and per-employee failure isolation.
 *
 *   eligibility (already decided upstream)
 *        -> claim log row (idempotency gate)
 *        -> channel->send()
 *        -> record outcome
 *
 * The router NEVER decides attendance facts; before every SMS it
 * re-evaluates eligibility fresh (race-condition guard).
 */
class NotificationRouter
{
    private AttendanceReminderEligibilityService $eligibility;
    private CalendarContextServiceInterface $calendar;
    private NotificationLogRepositoryInterface $logs;
    private NotificationPreferenceRepositoryInterface $preferences;
    private WebPushChannel $pushChannel;
    private SmsChannel $smsChannel;
    private ReminderSettings $settings;

    public function __construct(
        AttendanceReminderEligibilityService $eligibility,
        CalendarContextServiceInterface $calendar,
        NotificationLogRepositoryInterface $logs,
        ?NotificationPreferenceRepositoryInterface $preferences = null,
        ?WebPushChannel $pushChannel = null,
        ?SmsChannel $smsChannel = null,
        ?ReminderSettings $settings = null
    ) {
        $this->eligibility  = $eligibility;
        $this->calendar     = $calendar;
        $this->logs         = $logs;
        $this->preferences  = $preferences ?? new \App\Repositories\NotificationPreferenceRepository();
        $this->pushChannel  = $pushChannel ?? new WebPushChannel(new \App\Repositories\PushSubscriptionRepository());
        $this->smsChannel   = $smsChannel ?? new SmsChannel(new \App\Services\Notification\Sms\HttpSmsProvider());
        $this->settings     = $settings ?? new ReminderSettings();
    }

    /**
     * Route the primary reminder for one eligible employee.
     * $stage defaults to reminder_1; pass STAGE_REMINDER_2 for the
     * optional second push (unique key keeps stages independent).
     */
    public function routeReminder(array $candidate, string $date, string $stage = ReminderSettings::STAGE_REMINDER_1): array
    {
        $request = $this->buildRequest($candidate, $date, $stage);
        if ($request === null) {
            return ['status' => 'skipped', 'reason' => 'No linked user account'];
        }

        // Stage policy: push first when globally enabled and allowed by prefs.
        if (!$this->settings->pushEnabled() || !$this->prefAllows($request->userId, 'web_push')) {
            $this->claimSkipped(
                $request,
                !$this->settings->pushEnabled()
                    ? 'Organisation push reminders disabled'
                    : 'Disabled by employee preference'
            );
            return ['status' => 'skipped', 'reason' => 'Push disabled'];
        }

        return $this->dispatchToChannel($this->pushChannel, $request);
    }

    /**
     * SMS fallback stage. Policy:
     *  - fresh eligibility re-check FIRST (spec §29 race guard),
     *  - employee sms pref must allow,
     *  - daily cost cap enforced,
     *  - "fallback" policy requires push unavailable/failed; "always" ignores push outcome.
     */
    public function routeSmsFallback(array $candidate, string $date, bool $pushWasUnavailableOrFailed, bool $force = false): array
    {
        if (!$this->settings->smsEnabled()) {
            return ['status' => 'skipped', 'reason' => 'Organisation SMS reminders disabled'];
        }

        $request = $this->buildRequest($candidate, $date, ReminderSettings::STAGE_SMS_FALLBACK);
        if ($request === null) {
            return ['status' => 'skipped', 'reason' => 'No linked user account'];
        }

        // ---- REAL-TIME RECHECK (spec §29) ---------------------------------
        $fresh = $this->eligibility->evaluate((int) $candidate['employee_id'], $date);
        if (!$fresh->eligible) {
            $detail = $fresh->detail !== '' ? ' - ' . $fresh->detail : '';
            $this->claimSkipped($request, 'Eligibility changed: ' . $fresh->reason . $detail);
            return ['status' => 'skipped', 'reason' => 'Re-check: ' . $fresh->reason];
        }

        if (!$this->prefAllows($request->userId, 'sms')) {
            $this->claimSkipped($request, 'SMS disabled by employee preference');
            return ['status' => 'skipped', 'reason' => 'SMS disabled by preference'];
        }

        if (!$force && $this->settings->smsPolicy() === 'fallback' && !$pushWasUnavailableOrFailed) {
            $this->claimSkipped($request, 'Fallback not required: push delivered');
            return ['status' => 'skipped', 'reason' => 'Push already delivered'];
        }

        // ---- COST CONTROL (spec §23/§25) ----------------------------------
        $maxPerDay = $this->settings->maxSmsPerDay();
        if ($this->logs->countSmsAttempts($request->userId, $date) >= $maxPerDay) {
            $this->claimSkipped($request, "Daily SMS cap reached ({$maxPerDay})");
            return ['status' => 'skipped', 'reason' => 'Daily SMS cap reached'];
        }

        return $this->dispatchToChannel($this->smsChannel, $request);
    }

    /**
     * Retry one previously-failed (retryable) SMS row WITHOUT a new
     * claim - the row already exists, so we send and update it in place.
     * A fresh eligibility check still runs first.
     */
    public function routeSmsRetry(int $logId, array $candidate, string $date): array
    {
        $fresh = $this->eligibility->evaluate((int) $candidate['employee_id'], $date);
        if (!$fresh->eligible) {
            $detail = $fresh->detail !== '' ? ' - ' . $fresh->detail : '';
            $this->logs->markSkipped($logId, 'Eligibility changed on retry: ' . $fresh->reason . $detail);
            return ['status' => 'skipped', 'reason' => 'Re-check: ' . $fresh->reason];
        }

        if ($this->logs->countSmsAttempts($this->userIdOf($candidate), $date) >= $this->settings->maxSmsPerDay()) {
            $this->logs->markSkipped($logId, 'Daily SMS cap reached on retry');
            return ['status' => 'skipped', 'reason' => 'Daily SMS cap reached'];
        }

        $request = $this->buildRequest($candidate, $date, ReminderSettings::STAGE_SMS_FALLBACK);
        if ($request === null) {
            $this->logs->markFailed($logId, 'No linked user account for retry', false);
            return ['status' => 'failed', 'reason' => 'No linked user account'];
        }
        $request->stage = ReminderSettings::STAGE_SMS_FALLBACK;

        try {
            $result = $this->smsChannel->send($request);
        } catch (Throwable $e) {
            $result = ChannelResult::failedRetryable('Unhandled channel exception: ' . $e->getMessage());
        }

        switch ($result->getStatus()) {
            case ChannelResult::SENT:
                $this->logs->markSent($logId, $result->getProviderMessageId());
                break;
            case ChannelResult::FAILED_RETRYABLE:
                $this->logs->markFailed($logId, $result->getReason(), true);
                break;
            case ChannelResult::SKIPPED:
                $this->logs->markSkipped($logId, $result->getReason());
                break;
            default:
                $this->logs->markFailed($logId, $result->getReason(), false);
        }
        return ['status' => $result->getStatus(), 'reason' => $result->getReason()];
    }

    private function userIdOf(array $candidate): int
    {
        return (int) ($candidate['user_id'] ?? 0);
    }

    /** Shared claim+send+record pipeline for one channel. */
    private function dispatchToChannel(ChannelInterface $channel, NotificationRequest $request): array
    {
        $logId = $this->logs->claim(
            $request->userId,
            $request->employeeId,
            $request->notificationType,
            $channel->name(),
            $request->stage,
            $request->businessDate
        );
        if ($logId === null) {
            return ['status' => 'duplicate', 'reason' => 'Already handled for this date/stage/channel'];
        }

        try {
            $result = $channel->send($request);
        } catch (Throwable $e) {
            // One failed employee must never stop the batch (spec §31/§53).
            \logger()->error('Channel threw unexpectedly', [
                'channel' => $channel->name(),
                'user_id' => $request->userId,
                'error'   => $e->getMessage(),
            ]);
            $result = ChannelResult::failedRetryable('Unhandled channel exception: ' . $e->getMessage());
        }

        switch ($result->getStatus()) {
            case ChannelResult::SENT:
                $this->logs->markSent($logId, $result->getProviderMessageId());
                break;
            case ChannelResult::FAILED_RETRYABLE:
                $this->logs->markFailed($logId, $result->getReason(), true);
                break;
            case ChannelResult::SKIPPED:
                $this->logs->markSkipped($logId, $result->getReason());
                break;
            default:
                $this->logs->markFailed($logId, $result->getReason(), false);
        }

        return ['status' => $result->getStatus(), 'reason' => $result->getReason()];
    }

    /** Claim + immediately record a skip (audit trail of WHY nothing sent). */
    private function claimSkipped(NotificationRequest $request, string $reason): void
    {
        // Log under the stage's primary channel so unique keys still
        // prevent double rows for the same decision.
        $channel = $request->stage === ReminderSettings::STAGE_SMS_FALLBACK ? 'sms' : 'web_push';
        $logId = $this->logs->claim(
            $request->userId,
            $request->employeeId,
            $request->notificationType,
            $channel,
            $request->stage,
            $request->businessDate
        );
        if ($logId !== null) {
            $this->logs->markSkipped($logId, $reason);
        }
    }

    /**
     * Admin/developer test path (stage=test). Bypasses stage policy and
     * daily caps - permission + rate limiting live in the controller -
     * but still writes an auditable log row per attempt.
     */
    public function routeTest(NotificationRequest $request, string $channelName): array
    {
        $channel = $channelName === 'sms' ? $this->smsChannel : $this->pushChannel;
        return $this->dispatchToChannel($channel, $request);
    }

    /** Build the delivery request; null when candidate lacks a user account. */
    private function buildRequest(array $candidate, string $date, string $stage): ?NotificationRequest
    {
        $userId = (int) ($candidate['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $name = (string) ($candidate['name'] ?? '');

        return new NotificationRequest(
            $userId,
            isset($candidate['employee_id']) ? (int) $candidate['employee_id'] : null,
            $name,
            ReminderSettings::NOTIFICATION_TYPE,
            $stage,
            $date,
            \App\Services\Notification\Sms\PhoneNormalizer::normalize($candidate['phone'] ?? null),
            [
                'employee_name' => $name,
                'date'          => \DateTimeImmutable::createFromFormat('Y-m-d', $date)
                    ? (\DateTimeImmutable::createFromFormat('Y-m-d', $date))->format('d M Y')
                    : $date,
            ]
        );
    }

    /**
     * Employee preference check honouring organisation mandate.
     * When ATTENDANCE_REMINDERS_MANDATORY=true, individual opt-out is
     * overridden (organisation-mandated communication, spec §40).
     */
    private function prefAllows(int $userId, string $channel): bool
    {
        if ($this->settings->remindersMandatory()) {
            return true;
        }

        $row = $this->preferences->findByUser($userId);
        if ($row === null) {
            return true; // Default: enabled until the employee opts out.
        }

        $column = $channel === 'web_push' ? 'push_enabled' : ($channel === 'sms' ? 'sms_enabled' : '');
        if ($column === '') {
            return false;
        }
        return (int) $row[$column] === 1;
    }
}
