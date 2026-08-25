<?php

declare(strict_types=1);

namespace App\Controllers\Notifications;

use App\Controllers\BaseController;
use App\Helpers\AppTime;
use App\Services\AuditService;
use App\Services\AttendanceReminderEligibilityService;
use App\Services\CalendarContextService;
use App\Services\Notification\NotificationRequest;
use App\Services\Notification\NotificationRouter;
use App\Services\Notification\RateLimiter;
use App\Services\Notification\Sms\PhoneNormalizer;
use App\Services\ReminderSettings;

/**
 * Controlled admin test mechanism (spec §39).
 *
 * POST /api/admin/notifications/test-send
 *   { employee_id: int, channels: ["push","sms"], force?: bool }
 *
 * - Permission protected (notifications.manage).
 * - Rate limited (5/hour/IP) so it can never become an SMS cost hole.
 * - Audit logged with the acting admin identity.
 */
class NotificationTestController extends BaseController
{
    private const STAGE_TEST = 'test';

    public function sendAction(): void
    {
        $this->requirePermission('notifications', 'manage');

        if (!RateLimiter::getInstance()->hit('admin_test_notification', 5, 3600)) {
            $this->error('Test limit reached (5 per hour). Try again later.', 429);
        }

        $data     = $this->getJsonBody();
        $empId    = (int) ($data['employee_id'] ?? 0);
        $channels = (array) ($data['channels'] ?? ['push']);
        $force    = (bool) ($data['force'] ?? false);

        if ($empId <= 0 || (!in_array('sms', $channels, true) && !in_array('push', $channels, true))) {
            $this->error('employee_id and at least one channel (push|sms) are required', 422);
        }

        $candidate = \db()->fetchOne(
            "SELECT e.id AS employee_id, e.phone,
                    TRIM(CONCAT(e.first_name, ' ', COALESCE(e.last_name, ''))) AS name,
                    u.id AS user_id
             FROM employees e JOIN users u ON u.employee_id = e.employee_id
             WHERE e.id = ? LIMIT 1",
            'i',
            [$empId]
        );
        if ($candidate === null) {
            $this->notFound('Employee not found or has no linked user account');
        }

        // Eligibility gate unless explicitly forced (permission-gated).
        $eligibility = new AttendanceReminderEligibilityService();
        $result      = $eligibility->evaluate($empId, AppTime::today());
        if (!$result->eligible && !$force) {
            $this->json([
                'success' => false,
                'message' => "Employee is not currently eligible for a reminder ({$result->reason}). "
                    . 'Pass force:true to override for testing.',
                'meta'    => ['eligibility' => $result->toArray()],
            ], 409);
        }

        $this->dispatchTest($eligibility, $candidate, $channels);
    }

    /** Build the request, route to each requested channel, audit + respond. */
    private function dispatchTest(
        AttendanceReminderEligibilityService $eligibility,
        array $candidate,
        array $channels
    ): void {
        $date    = AppTime::today();
        $name    = (string) ($candidate['name'] ?? '');
        $request = new NotificationRequest(
            (int) $candidate['user_id'],
            (int) $candidate['employee_id'],
            $name,
            ReminderSettings::NOTIFICATION_TYPE,
            self::STAGE_TEST,
            $date,
            PhoneNormalizer::normalize($candidate['phone'] ?? null),
            ['employee_name' => $name, 'date' => $date]
        );

        $router = new NotificationRouter(
            $eligibility,
            new CalendarContextService(),
            new \App\Repositories\NotificationLogRepository()
        );

        $outcomes = [];
        foreach ($channels as $channel) {
            if ($channel === 'push') {
                $outcomes['web_push'] = $router->routeTest($request, 'web_push');
            } elseif ($channel === 'sms') {
                if ($request->phone === null) {
                    $outcomes['sms'] = ['status' => 'skipped', 'reason' => 'Missing/invalid phone number'];
                } else {
                    $outcomes['sms'] = $router->routeTest($request, 'sms');
                }
            }
        }

        AuditService::getInstance()->log(
            AuditService::MODULE_NOTIFICATIONS,
            AuditService::ACTION_CREATE,
            'Sent test notification',
            [
                'target_employee_id' => (int) $candidate['employee_id'],
                'channels'           => $channels,
                'outcomes'           => $outcomes,
            ]
        );

        $message = 'Test notification dispatched';
        if (in_array('sms', $channels, true)) {
            $message .= '. Note: real SMS may incur charges.';
        }

        $this->success(['outcomes' => $outcomes], $message);
    }
}
