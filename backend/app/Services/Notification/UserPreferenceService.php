<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Repositories\Contracts\NotificationPreferenceRepositoryInterface;
use App\Services\ReminderSettings;
use App\Services\Notification\Sms\PhoneNormalizer;

/**
 * Per-user notification preference handling with effective-value
 * resolution (employee choice + organisation mandate + defaults).
 */
class UserPreferenceService
{
    private NotificationPreferenceRepositoryInterface $repository;
    private ReminderSettings $settings;

    public function __construct(
        ?NotificationPreferenceRepositoryInterface $repository = null,
        ?ReminderSettings $settings = null
    ) {
        $this->repository = $repository ?? new \App\Repositories\NotificationPreferenceRepository();
        $this->settings   = $settings ?? new ReminderSettings();
    }

    /** Raw stored row or null. */
    public function raw(int $userId): ?array
    {
        return $this->repository->findByUser($userId);
    }

    /**
     * Effective view for the settings UI. Never exposes secrets.
     *
     * @return array{
     *   push_enabled:bool, sms_enabled:bool,
     *   effective_push_enabled:bool, effective_sms_enabled:bool,
     *   reminders_mandatory:bool,
     *   reminder_time:string, sms_fallback_delay_minutes:int,
     *   phone_masked:?string, has_active_push:bool
     * }
     */
    public function viewForUser(int $userId): array
    {
        $row      = $this->raw($userId);
        $pushOn   = $row === null ? true : (int) $row['push_enabled'] === 1;
        $smsOn    = $row === null ? true : (int) $row['sms_enabled'] === 1;
        $mandatory = $this->settings->remindersMandatory();

        return [
            'push_enabled'                => $pushOn,
            'sms_enabled'                 => $smsOn,
            'effective_push_enabled'      => $mandatory ? true : ($pushOn && $this->settings->pushEnabled()),
            'effective_sms_enabled'       => $mandatory ? true : ($smsOn && $this->settings->smsEnabled()),
            'reminders_mandatory'         => $mandatory,
            'reminder_time'               => $this->settings->reminderTime(),
            'sms_fallback_delay_minutes'  => $this->settings->smsFallbackDelayMinutes(),
            'phone_masked'                => PhoneNormalizer::mask($this->userPhone($userId)),
            'has_active_push'             => false, // filled by caller when needed
        ];
    }

    /** Save the employee's own toggles (mandate cannot be changed here). */
    public function saveOwn(int $userId, bool $pushEnabled, bool $smsEnabled): array
    {
        return $this->repository->save($userId, $pushEnabled, $smsEnabled);
    }

    /** Resolve the employee's official phone (server-side source of truth). */
    public function userPhone(?int $userId): ?string
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }
        $row = \db()->fetchOne(
            "SELECT e.phone FROM employees e JOIN users u ON u.employee_id = e.employee_id WHERE u.id = ? LIMIT 1",
            'i',
            [$userId]
        );
        return is_array($row) && !empty($row['phone']) ? (string) $row['phone'] : null;
    }
}
