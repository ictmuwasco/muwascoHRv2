<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Repositories\Contracts\PushSubscriptionRepositoryInterface;

/**
 * Push subscription management (registration/lookup/revoke).
 * Used by the authenticated subscribe API endpoints.
 */
class PushSubscriptionService
{
    private PushSubscriptionRepositoryInterface $repository;

    public function __construct(?PushSubscriptionRepositoryInterface $repository = null)
    {
        $this->repository = $repository ?? new \App\Repositories\PushSubscriptionRepository();
    }

    /**
     * Register (or refresh) one device subscription for the user.
     *
     * @param int    $userId     Authenticated user - NEVER client-declared
     * @param array  $data       endpoint, p256dh, auth, device_name?, platform?
     * @return int subscription row id
     */
    public function subscribe(int $userId, array $data): int
    {
        return $this->repository->upsert($userId, [
            'endpoint'    => (string) $data['endpoint'],
            'p256dh'      => (string) $data['keys']['p256dh'],
            'auth'        => (string) $data['keys']['auth'],
            'device_name' => isset($data['device_name']) ? substr(trim((string) $data['device_name']), 0, 120) : null,
            'platform'    => isset($data['platform']) ? substr(trim((string) $data['platform']), 0, 60) : null,
            'user_agent'  => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
        ]);
    }

    public function unsubscribe(int $userId, string $endpoint): bool
    {
        // Ownership enforced: only rows whose user_id matches are revoked.
        $hash = hash('sha256', $endpoint);
        $row  = $this->repository->findByEndpointHash($hash);
        if ($row === null || (int) $row['user_id'] !== $userId) {
            return false;
        }
        return $this->repository->revokeByEndpointHash($hash);
    }

    /** Safe list for UI: never exposes keys/endpoints to the client. */
    public function listForUser(int $userId): array
    {
        return array_map(static fn (array $r): array => [
            'id'           => (int) $r['id'],
            'device_name'  => $r['device_name'] ?? 'Browser',
            'platform'     => $r['platform'],
            'last_used_at' => $r['last_used_at'] ?? null,
            'created_at'   => $r['created_at'] ?? null,
        ], $this->repository->findActiveByUser($userId));
    }

    public function hasActiveSubscription(int $userId): bool
    {
        return $this->repository->findActiveByUser($userId) !== [];
    }

    /** Public application server key (safe to expose to browsers). */
    public function publicKey(): ?string
    {
        $key = (string) env('VAPID_PUBLIC_KEY', '');
        return $key !== '' ? $key : null;
    }

    public function purgeRevokedOlderThan(int $days): int
    {
        return $this->repository->purgeRevoked($days);
    }
}
