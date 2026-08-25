<?php

declare(strict_types=1);

namespace App\Controllers\Notifications;

use App\Controllers\BaseController;
use App\Helpers\AppTime;
use App\Repositories\NotificationLogRepository;
use App\Services\AttendanceReminderEligibilityService;
use App\Services\CalendarContextService;
use App\Services\ReminderSettings;

/**
 * HR/Admin visibility into the notification system.
 * All endpoints require the `notifications` module permission.
 * Test sending lives in NotificationTestController (rate limited).
 */
class AdminNotificationController extends BaseController
{
    private ReminderSettings $settings;
    private AttendanceReminderEligibilityService $eligibility;
    private CalendarContextService $calendar;
    private NotificationLogRepository $logs;

    public function __construct()
    {
        $this->settings     = new ReminderSettings();
        $this->eligibility  = new AttendanceReminderEligibilityService();
        $this->calendar     = new CalendarContextService();
        $this->logs         = new NotificationLogRepository();
    }

    /** GET /api/admin/notifications/stats?date=YYYY-MM-DD */
    public function statsAction(): void
    {
        $this->requirePermission('notifications', 'view');

        $date = $this->normaliseDate($_GET['date'] ?? AppTime::today());

        $activeEmployees = (int) \db()->fetchValue(
            "SELECT COUNT(*) FROM employees
             WHERE employee_status = 'active' OR employee_status IS NULL"
        );
        $clockedIn = (int) \db()->fetchValue(
            "SELECT COUNT(DISTINCT employee_id) FROM attendance WHERE DATE(clock_in) = ?",
            's',
            [$date]
        );

        $stats = [
            'date'       => $date,
            'is_weekend' => $this->calendar->isWeekend($date),
            'holiday'    => $this->calendar->getHolidayName($date),
            'employees'  => [
                'active'            => $activeEmployees,
                'on_approved_leave' => count($this->calendar->getApprovedLeavesOnDate($date)),
                'clocked_in'        => $clockedIn,
            ],
            'config' => [
                'reminder_time'              => $this->settings->reminderTime(),
                'push_enabled'               => $this->settings->pushEnabled(),
                'sms_enabled'                => $this->settings->smsEnabled(),
                'sms_fallback_enabled'       => $this->settings->smsFallbackEnabled(),
                'sms_fallback_delay_minutes' => $this->settings->smsFallbackDelayMinutes(),
                'sms_policy'                 => $this->settings->smsPolicy(),
                'max_sms_per_day'            => $this->settings->maxSmsPerDay(),
                'vapid_configured'           => env('VAPID_PUBLIC_KEY', '') !== '',
                'sms_provider'               => env('SMS_PROVIDER', 'httpsms'),
                'sms_provider_configured'    => env('HTTPSMS_API_KEY', '') !== '',
            ],
            'delivery' => [],
        ];

        foreach ($this->logs->statsForDate($date) as $row) {
            $key = $row['channel'] . '_' . $row['stage'];
            $stats['delivery'][$key] = $stats['delivery'][$key] ?? [];
            $stats['delivery'][$key][$row['status']] = (int) $row['cnt'];
        }

        $this->success($stats);
    }

    /**
     * GET /api/admin/notifications/audit/{employeeId}?date=
     * Diagnostic timeline for one employee-day (spec §36).
     */
    public function auditAction(int $employeeId): void
    {
        $this->requirePermission('notifications', 'view');

        $date = $this->normaliseDate($_GET['date'] ?? AppTime::today());

        $userRow = \db()->fetchOne(
            'SELECT id FROM users WHERE employee_id = (SELECT employee_id FROM employees WHERE id = ?) LIMIT 1',
            'i',
            [$employeeId]
        );
        if ($userRow === null) {
            $this->notFound('Employee or linked user account not found');
        }
        $userId = (int) $userRow['id'];

        $attendance = \db()->fetchOne(
            "SELECT clock_in, clock_out, status, is_late FROM attendance
             WHERE employee_id = ? AND clock_in IS NOT NULL AND DATE(clock_in) = ?
             ORDER BY clock_in DESC LIMIT 1",
            'is',
            [$employeeId, $date]
        );

        $timeline = [];
        foreach ($this->logs->findByUserAndDate($userId, $date) as $log) {
            $timeline[] = [
                'stage'           => $log['stage'],
                'channel'         => $log['channel'],
                'status'          => $log['status'],
                'reason'          => $log['failure_reason'],
                'provider_msg_id' => $log['provider_message_id'],
                'attempts'        => (int) $log['attempts'],
                'sent_at'         => $log['sent_at'],
                'created_at'      => $log['created_at'],
            ];
        }

        $leaves = $this->calendar->getApprovedLeavesOnDate($date);

        $this->success([
            'employee_id'   => $employeeId,
            'date'          => $date,
            'eligibility'   => $this->eligibility->evaluate($employeeId, $date)->toArray(),
            'day_context'   => [
                'is_weekend' => $this->calendar->isWeekend($date),
                'holiday'    => $this->calendar->getHolidayName($date),
                'on_leave'   => isset($leaves[$employeeId]),
            ],
            'attendance'    => $attendance,
            'notifications' => $timeline,
        ]);
    }

    private function normaliseDate($raw): string
    {
        $raw = trim((string) $raw);
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if ($dt === false || $dt->format('Y-m-d') !== $raw) {
            $this->error('Invalid date. Expected YYYY-MM-DD.', 422);
        }
        return $raw;
    }
}
