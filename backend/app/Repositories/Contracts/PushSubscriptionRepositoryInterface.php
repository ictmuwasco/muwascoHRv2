<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

/**
 * Repository contract for push_subscriptions.
 */
interface PushSubscriptionRepositoryInterface
{
    /** Upsert by endpoint hash; clears revoked_at on resubscribe. Returns row id. */
    public function upsert(int $userId, array $data): int;

    /** All active subscriptions for a user. */
    public function findActiveByUser(int $userId): array;

    /** Active subscription count per user id. @return array<int,int> */
    public function activeCountMap(array $userIds): array;

    /** Mark one endpoint revoked (unsubscribe or provider 410). */
    public function revokeByEndpointHash(string $endpointHash): bool;

    /** Record a successful send attempt on the endpoint. */
    public function markLastUsed(string $endpointHash): void;

    /** Revoke all of a user's subscriptions. */
    public function revokeAllForUser(int $userId): bool;

    /** Delete rows revoked earlier than $days days. Returns affected count. */
    public function purgeRevoked(int $days): int;

    /** Find user_id + keys by endpoint hash (webhook/audit use). */
    public function findByEndpointHash(string $endpointHash): ?array;
}
