<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

/**
 * Repository contract for notification_preferences.
 */
interface NotificationPreferenceRepositoryInterface
{
    /** Raw preference row or null when the user never saved any. */
    public function findByUser(int $userId): ?array;

    /** Insert or update the user's own toggles. Returns the row. */
    public function save(int $userId, bool $pushEnabled, bool $smsEnabled): array;

    /** Preference rows for many users keyed by user_id (batch, no N+1). @return array<int,array> */
    public function mapByUsers(array $userIds): array;
}
