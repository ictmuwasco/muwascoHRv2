<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Helpers\Database;

/**
 * NotificationInboxService
 *
 * Read-side of the in-app inbox: listing, unread counting and read-state
 * changes.
 *
 * NotificationService::getUnreadNotifications() already exists and is used by
 * the attendance reminder job, so it is left alone - but it returns ONLY unread
 * rows with a caller-chosen limit, which cannot back an inbox: a user who has
 * read everything would see an empty list rather than their history, and there
 * is no way to page.
 *
 * Every method is scoped to a single user_id. A notification row belongs to
 * exactly one account, so there is no case where a broader scope is legitimate.
 */
class NotificationInboxService
{
    public const FILTER_ALL    = 'all';
    public const FILTER_UNREAD = 'unread';
    public const FILTER_READ   = 'read';

    /** Hard cap so a caller cannot ask for the whole table in one page. */
    public const MAX_PER_PAGE = 50;

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * A page of one user's notifications, newest first.
     *
     * @param  array{filter?:string, category?:?string, page?:int, per_page?:int} $options
     * @return array{items:array<int,array>, total:int, unread:int, page:int, per_page:int, pages:int}
     */
    public function listForUser(int $userId, array $options = []): array
    {
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($options['per_page'] ?? 15)));
        $filter = (string) ($options['filter'] ?? self::FILTER_ALL);
        $category = isset($options['category']) && trim((string) $options['category']) !== ''
            ? trim((string) $options['category'])
            : null;

        [$where, $params, $types] = $this->buildWhere($userId, $filter, $category);

        $total = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM notifications WHERE {$where}",
            $types,
            $params
        );
        $unread = $this->unreadCount($userId);

        // A page past the end returns an empty list rather than an error, which
        // is what a UI needs when the user is on page 3 and reads everything.
        $offset = ($page - 1) * $perPage;
        $items = $offset >= $total ? [] : $this->db->fetchAll(
            "SELECT id, title, message, type, category, priority, action_url,
                    is_read, trigger_type, related_entity, related_id, created_at
             FROM notifications
             WHERE {$where}
             ORDER BY created_at DESC, id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $types,
            $params
        );

        return [
            'items'    => $items,
            'total'    => $total,
            'unread'   => $unread,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $total === 0 ? 0 : (int) ceil($total / $perPage),
        ];
    }

    /**
     * Unread notifications for the bell dropdown.
     *
     * Deliberately separate from listForUser(): the bell must never be able to
     * pull an unbounded result set for a user with thousands of unread items.
     *
     * @return array<int,array>
     */
    public function recentUnread(int $userId, int $limit = 5): array
    {
        $limit = min(self::MAX_PER_PAGE, max(1, $limit));
        return $this->db->fetchAll(
            "SELECT id, title, message, type, category, action_url, created_at
             FROM notifications
             WHERE user_id = ? AND is_read = 0
             ORDER BY created_at DESC, id DESC
             LIMIT {$limit}",
            'i',
            [$userId]
        );
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0',
            'i',
            [$userId]
        );
    }

    /**
     * Mark one notification read.
     *
     * Scoped by user_id in the WHERE clause, so a guessed id belonging to
     * somebody else updates nothing and reports "not found" rather than
     * silently succeeding and appearing to have worked.
     *
     * @return bool True when a row was actually updated.
     */
    public function markAsRead(int $notificationId, int $userId): bool
    {
        return $this->db->update(
            'notifications',
            ['is_read' => 1],
            'id = ? AND user_id = ?',
            'ii',
            [$notificationId, $userId]
        ) > 0;
    }

    /**
     * Mark every unread notification read for one user.
     */
    public function markAllAsRead(int $userId): int
    {
        return $this->db->update(
            'notifications',
            ['is_read' => 1],
            'user_id = ? AND is_read = 0',
            'i',
            [$userId]
        );
    }

    /**
     * The distinct categories a user actually has, for a filter control.
     *
     * @return list<string>
     */
    public function categoriesFor(int $userId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT category FROM notifications
             WHERE user_id = ? AND category <> '' ORDER BY category",
            'i',
            [$userId]
        );
        return array_values(array_map(static fn (array $r): string => (string) $r['category'], $rows));
    }

    /**
     * @return array{0:string,1:array<int,mixed>,2:string}
     */
    private function buildWhere(int $userId, string $filter, ?string $category): array
    {
        $where = 'user_id = ?';
        $params = [$userId];
        $types = 'i';

        if ($filter === self::FILTER_UNREAD) {
            $where .= ' AND is_read = 0';
        } elseif ($filter === self::FILTER_READ) {
            $where .= ' AND is_read = 1';
        }

        if ($category !== null) {
            $where .= ' AND category = ?';
            $params[] = $category;
            $types .= 's';
        }

        return [$where, $params, $types];
    }
}

