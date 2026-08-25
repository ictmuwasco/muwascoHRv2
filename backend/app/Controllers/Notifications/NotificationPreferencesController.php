<?php

declare(strict_types=1);

namespace App\Controllers\Notifications;

use App\Controllers\BaseController;
use App\Services\AuditService;
use App\Services\Notification\PushSubscriptionService;
use App\Services\Notification\UserPreferenceService;

/**
 * Employee notification preferences API (own settings only).
 */
class NotificationPreferencesController extends BaseController
{
    private UserPreferenceService $preferences;
    private PushSubscriptionService $subscriptions;

    public function __construct()
    {
        $this->preferences  = new UserPreferenceService();
        $this->subscriptions = new PushSubscriptionService();
    }

    /** GET /api/notification-preferences */
    public function indexAction(): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }

        $view = $this->preferences->viewForUser($userId);
        $view['has_active_push'] = $this->subscriptions->hasActiveSubscription($userId);

        $this->success($view);
    }

    /** PUT /api/notification-preferences - body: {push_enabled, sms_enabled} */
    public function updateAction(): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }

        $data       = $this->getJsonBody();
        $pushEnable = $data['push_enabled'] ?? null;
        $smsEnabled = $data['sms_enabled'] ?? null;

        if (!is_bool($pushEnable) || !is_bool($smsEnabled)) {
            $this->error('push_enabled and sms_enabled must be booleans', 422);
        }

        $row = $this->preferences->saveOwn($userId, $pushEnable, $smsEnabled);

        AuditService::getInstance()->log(
            AuditService::MODULE_NOTIFICATIONS,
            AuditService::ACTION_UPDATE,
            'Updated notification preferences',
            ['new_values' => ['push_enabled' => $pushEnable, 'sms_enabled' => $smsEnabled]]
        );

        $this->success([
            'push_enabled' => (int) $row['push_enabled'] === 1,
            'sms_enabled'  => (int) $row['sms_enabled'] === 1,
        ], 'Notification preferences saved');
    }
}
