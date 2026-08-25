<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Repositories\Contracts\PushSubscriptionRepositoryInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Web Push delivery channel (Push API + VAPID).
 *
 * - Sends to EVERY active subscription of the employee (multi-device).
 * - Marks subscriptions revoked when the push service answers 410
 *   Gone (expired/unsubscribed) so dead endpoints are never retried.
 * - VAPID keys come from env; the private key NEVER leaves the server.
 */
class WebPushChannel implements ChannelInterface
{
    private PushSubscriptionRepositoryInterface $subscriptions;
    private ReminderTemplateService $templates;

    public function __construct(
        PushSubscriptionRepositoryInterface $subscriptions,
        ?ReminderTemplateService $templates = null
    ) {
        $this->subscriptions = $subscriptions;
        $this->templates     = $templates ?? new ReminderTemplateService();
    }

    public function name(): string
    {
        return 'web_push';
    }

    /** Public application server key for browser.subscribe(). */
    public function publicKey(): ?string
    {
        $key = (string) env('VAPID_PUBLIC_KEY', '');
        return $key !== '' ? $key : null;
    }

    public function send(NotificationRequest $request): ChannelResult
    {
        $rows = $this->subscriptions->findActiveByUser($request->userId);
        if ($rows === []) {
            return ChannelResult::skipped('No active push subscription');
        }

        $payload = $this->templates->buildPushPayload($request);
        $webPush = $this->buildWebPush();
        if ($webPush === null) {
            return ChannelResult::failed('VAPID not configured (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY / VAPID_SUBJECT)');
        }

        $sentAny     = false;
        $lastReason  = '';
        foreach ($rows as $row) {
            try {
                $subscription = Subscription::create([
                    'endpoint' => (string) $row['endpoint'],
                    'keys'     => [
                        'p256dh' => (string) $row['p256dh_key'],
                        'auth'   => (string) $row['auth_key'],
                    ],
                ]);
                $webPush->queueNotification($subscription, $payload);
            } catch (Throwable $e) {
                // Malformed stored keys - revoke so it never retries.
                $this->subscriptions->revokeByEndpointHash((string) $row['endpoint_hash']);
                $lastReason = 'Invalid subscription: ' . $e->getMessage();
            }
        }

        foreach ($webPush->flush() as $report) {
            $endpoint  = method_exists($report, 'getEndpoint') ? (string) $report->getEndpoint() : '';
            $hash      = hash('sha256', $endpoint);

            if ($report->isSuccess()) {
                $sentAny = true;
                $this->subscriptions->markLastUsed($hash);
                continue;
            }

            $statusCode = $report->getResponse()?->getStatusCode() ?? 0;
            if ($statusCode === 404 || $statusCode === 410) {
                // Permanently invalid: unsubscribe happened or expired.
                $this->subscriptions->revokeByEndpointHash($hash);
                $lastReason = "Subscription expired/invalid (HTTP {$statusCode}) - revoked";
            } else {
                $lastReason = substr((string) $report->getReason(), 0, 200);
            }
        }

        if ($sentAny) {
            return ChannelResult::sent(null);
        }
        return ChannelResult::failed($lastReason !== '' ? $lastReason : 'All subscriptions failed');
    }

    /** Build the library client from env VAPID config; null when unset. */
    private function buildWebPush(): ?WebPush
    {
        $publicKey  = (string) env('VAPID_PUBLIC_KEY', '');
        $privateKey = (string) env('VAPID_PRIVATE_KEY', '');
        $subject    = (string) env('VAPID_SUBJECT', 'mailto:hr@example.com');
        if ($publicKey === '' || $privateKey === '') {
            return null;
        }

        return new WebPush([
            'VAPID' => [
                'subject'    => $subject,
                'publicKey'  => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);
    }
}
