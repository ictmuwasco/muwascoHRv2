<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Contracts\PushSubscriptionRepositoryInterface;
use App\Helpers\Database;

/**
 * Push Subscription Repository - all push_subscriptions SQL.
 */
class PushSubscriptionRepository implements PushSubscriptionRepositoryInterface
{
    private \mysqli $conn;

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function upsert(int $userId, array $data): int
    {
        $hash = hash('sha256', (string) $data['endpoint']);

        // Upsert keyed on endpoint hash; resubscribing the same browser
        // refreshes keys and clears any previous revocation.
        $sql = "INSERT INTO push_subscriptions
                    (user_id, endpoint_hash, endpoint, p256dh_key, auth_key,
                     device_name, platform, user_agent, revoked_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)
                ON DUPLICATE KEY UPDATE
                    user_id      = VALUES(user_id),
                    endpoint     = VALUES(endpoint),
                    p256dh_key   = VALUES(p256dh_key),
                    auth_key     = VALUES(auth_key),
                    device_name  = VALUES(device_name),
                    platform     = VALUES(platform),
                    user_agent   = VALUES(user_agent),
                    revoked_at   = NULL";

        $stmt = $this->conn->prepare($sql);
        $deviceName = $data['device_name'] ?? null;
        $platform   = $data['platform'] ?? null;
        $userAgent  = $data['user_agent'] ?? null;

        $stmt->bind_param(
            'isssssss',
            $userId,
            $hash,
            $data['endpoint'],
            $data['p256dh'],
            $data['auth'],
            $deviceName,
            $platform,
            $userAgent
        );
        $stmt->execute();

        $id = $stmt->affected_rows === 1 ? (int) $this->conn->insert_id : $this->findIdByHash($hash);
        $stmt->close();
        return $id > 0 ? $id : $this->findIdByHash($hash);
    }

    private function findIdByHash(string $hash): int
    {
        $stmt = $this->conn->prepare("SELECT id FROM push_subscriptions WHERE endpoint_hash = ? LIMIT 1");
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['id'] ?? 0);
    }

    public function findActiveByUser(int $userId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT id, endpoint_hash, endpoint, p256dh_key, auth_key, device_name, platform,
                    last_used_at, created_at
             FROM push_subscriptions
             WHERE user_id = ? AND revoked_at IS NULL"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function activeCountMap(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $types = str_repeat('i', count($userIds));
        $stmt = $this->conn->prepare(
            "SELECT user_id, COUNT(*) AS cnt FROM push_subscriptions
             WHERE revoked_at IS NULL AND user_id IN ($placeholders) GROUP BY user_id"
        );
        $stmt->bind_param($types, ...$userIds);
        $stmt->execute();
        $map = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $map[(int) $row['user_id']] = (int) $row['cnt'];
        }
        $stmt->close();
        return $map;
    }

    public function revokeByEndpointHash(string $endpointHash): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE push_subscriptions SET revoked_at = NOW() WHERE endpoint_hash = ? AND revoked_at IS NULL"
        );
        $stmt->bind_param('s', $endpointHash);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }

    public function markLastUsed(string $endpointHash): void
    {
        $stmt = $this->conn->prepare(
            "UPDATE push_subscriptions SET last_used_at = NOW() WHERE endpoint_hash = ?"
        );
        $stmt->bind_param('s', $endpointHash);
        $stmt->execute();
        $stmt->close();
    }

    public function revokeAllForUser(int $userId): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE push_subscriptions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL"
        );
        $stmt->bind_param('i', $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }

    public function purgeRevoked(int $days): int
    {
        $stmt = $this->conn->prepare(
            "DELETE FROM push_subscriptions
             WHERE revoked_at IS NOT NULL AND revoked_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->bind_param('i', $days);
        $stmt->execute();
        $count = $stmt->affected_rows;
        $stmt->close();
        return $count;
    }

    public function findByEndpointHash(string $endpointHash): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT id, user_id, device_name, platform, revoked_at FROM push_subscriptions
             WHERE endpoint_hash = ? LIMIT 1"
        );
        $stmt->bind_param('s', $endpointHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }
}
