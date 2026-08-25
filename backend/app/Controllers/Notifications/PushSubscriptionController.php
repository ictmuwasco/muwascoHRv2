<?php

declare(strict_types=1);

namespace App\Controllers\Notifications;

use App\Controllers\BaseController;
use App\Services\AuditService;
use App\Services\Notification\PushSubscriptionService;
use App\Services\Notification\RateLimiter;

/**
 * Web Push subscription API.
 *
 * SECURITY:
 * - The user id is ALWAYS resolved from the authenticated session/JWT;
 *   the client cannot subscribe on behalf of anyone else.
 * - Registration is rate-limited (file-backed counter, works despite
 *   session_write_close for API requests).
 */
class PushSubscriptionController extends BaseController
{
    private PushSubscriptionService $service;

    public function __construct()
    {
        $this->service = new PushSubscriptionService();
    }

    /** GET /api/push/vapid-public-key - public key only (never private). */
    public function vapidPublicKeyAction(): void
    {
        $key = $this->service->publicKey();
        if ($key === null) {
            $this->error('Web Push is not configured on this server', 503);
        }
        $this->success(['public_key' => $key]);
    }

    /** POST /api/push/subscribe - register/refresh a device subscription. */
    public function subscribeAction(): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }

        if (!RateLimiter::getInstance()->hit('push_subscribe', 20, 3600)) {
            $this->error('Too many subscription attempts. Please try later.', 429);
        }

        $data = $this->getJsonBody();

        // ---- Input validation -----------------------------------------
        $endpoint = trim((string) ($data['endpoint'] ?? ''));
        $p256dh   = (string) ($data['keys']['p256dh'] ?? '');
        $auth     = (string) ($data['keys']['auth'] ?? '');

        if ($endpoint === '' || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            $this->error('A valid push endpoint URL is required', 422);
        }
        if (!preg_match('#^https://#i', $endpoint)) {
            $this->error('Push endpoints must use HTTPS', 422);
        }
        if ($p256dh === '' || $auth === '') {
            $this->error('Subscription keys (p256dh, auth) are required', 422);
        }
        if (strlen($p256dh) > 512 || strlen($auth) > 256) {
            $this->error('Subscription keys exceed maximum length', 422);
        }

        $id = $this->service->subscribe($userId, [
            'endpoint'    => $endpoint,
            'keys'        => ['p256dh' => $p256dh, 'auth' => $auth],
            'device_name' => $data['device_name'] ?? null,
            'platform'    => $data['platform'] ?? null,
        ]);

        AuditService::getInstance()->log(
            AuditService::MODULE_NOTIFICATIONS,
            AuditService::ACTION_CREATE,
            'Registered web push subscription',
            ['subscription_id' => $id]
        );

        $this->success([
            'subscription_id' => $id,
            'devices'         => $this->service->listForUser($userId),
        ], 'Notifications enabled for this device');
    }

    /** DELETE /api/push/subscribe?endpoint=... - remove own device. */
    public function unsubscribeAction(): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }

        $data     = $this->getJsonBody();
        $endpoint = trim((string) ($data['endpoint'] ?? ($_GET['endpoint'] ?? '')));
        if ($endpoint === '') {
            $this->error('Endpoint is required', 422);
        }

        if (!$this->service->unsubscribe($userId, $endpoint)) {
            // Not found / not owned: respond success-shaped but no-op to
            // avoid endpoint-existence probing; audit records it.
            AuditService::getInstance()->log(
                AuditService::MODULE_NOTIFICATIONS,
                AuditService::ACTION_DELETE,
                'Unsubscribe attempt for unknown or foreign endpoint',
                []
            );
            $this->success(['devices' => $this->service->listForUser($userId)], 'Device removed');
        }

        AuditService::getInstance()->log(
            AuditService::MODULE_NOTIFICATIONS,
            AuditService::ACTION_DELETE,
            'Removed web push subscription',
            []
        );
        $this->success(['devices' => $this->service->listForUser($userId)], 'Device removed');
    }

    /** GET /api/push/subscriptions - list own devices. */
    public function indexAction(): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }
        $this->success([
            'has_vapid' => $this->service->publicKey() !== null,
            'devices'   => $this->service->listForUser($userId),
        ]);
    }
}
