<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * getMyNotifications — the caller's OWN in-app inbox, strictly scoped by the
 * server-side user id (never a model-supplied id). A notification row belongs
 * to exactly one account, so no unit scope applies here: the WHERE clause pins
 * every query to ctx->userId() and the tool never accepts an id argument.
 *
 * ROLE GATE: settings:notifications — the self-service own-notifications
 * permission seeded to every role (migration 088), NOT the administration gate
 * notifications:view (hr / MD / super admin delivery dashboards only).
 *
 * READ-ONLY: lists and counts only. Marking rows read stays in the inbox UI —
 * the model must never mutate state through a "get" tool.
 */
final class GetMyNotificationsTool implements AiToolInterface
{
    private const MAX_LIMIT = 10;

    public function name(): string
    {
        return 'getMyNotifications';
    }

    public function description(): string
    {
        return 'List the signed-in user\'s own in-app notifications (title, message, '
            . 'category, priority and read state), newest first, together with their '
            . 'total and unread counts. Optionally filter to unread or read items, or '
            . 'to one category such as "leave". Use this for questions like "do I have '
            . 'any unread notifications?", "what notifications have I received?" or '
            . '"show my recent notifications". Read-only: it never marks notifications '
            . 'as read.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'filter' => [
                    'type'        => 'string',
                    'enum'        => ['all', 'unread', 'read'],
                    'description' => 'Which notifications to list. Default: all.',
                ],
                'category' => [
                    'type'        => 'string',
                    'description' => 'Optional exact category filter, e.g. "leave" or "general". Default: all categories.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => 10,
                    'description' => 'How many notifications to return (newest first). Default 5, max 10.',
                ],
            ],
            'required'   => [],
        ];
    }

    /** Self-service own-notifications permission, seeded to every role. */
    public function requiredPermission(): string
    {
        return 'settings:notifications';
    }

    public function execute(AiToolContext $ctx, array $args): array
    {
        $userId = $ctx->userId();
        if ($userId <= 0) {
            return [
                'error' => 'No signed-in user could be resolved, so notifications cannot be shown.',
            ];
        }

        $filter = isset($args['filter']) && is_string($args['filter'])
            ? strtolower($args['filter'])
            : 'all';
        if (!in_array($filter, ['all', 'unread', 'read'], true)) {
            $filter = 'all';
        }

        $category = isset($args['category']) && is_string($args['category'])
            ? trim($args['category'])
            : '';
        if ($category === '' || strlen($category) > 100) {
            $category = '';
        }

        $limit = isset($args['limit']) ? (int) $args['limit'] : 5;
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        // Counts always cover the WHOLE inbox (unfiltered), so "how many unread
        // do I have?" is answered even when the list itself is filtered.
        $unreadCount = (int) $ctx->db()->fetchValue(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0',
            'i',
            [$userId]
        );
        $totalCount = (int) $ctx->db()->fetchValue(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ?',
            'i',
            [$userId]
        );


        $where  = 'user_id = ?';
        $types  = 'i';
        $params = [$userId];

        if ($filter === 'unread') {
            $where .= ' AND is_read = 0';
        } elseif ($filter === 'read') {
            $where .= ' AND is_read = 1';
        }
        if ($category !== '') {
            $where .= ' AND category = ?';
            $types .= 's';
            $params[] = $category;
        }

        $rows = $ctx->db()->fetchAll(
            'SELECT title, message, type, category, priority, is_read, created_at'
            . ' FROM notifications'
            . ' WHERE ' . $where
            . ' ORDER BY created_at DESC, id DESC'
            . ' LIMIT ' . $limit,
            $types,
            $params
        );

        $notifications = [];
        foreach ($rows as $row) {
            $notifications[] = [
                'title'      => (string) $row['title'],
                'message'    => (string) $row['message'],
                'type'       => (string) $row['type'],
                'category'   => (string) $row['category'],
                'priority'   => (string) $row['priority'],
                'is_read'    => (bool) $row['is_read'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return [
            'filter'        => $filter,
            'category'      => $category !== '' ? $category : null,
            'total_count'   => $totalCount,
            'unread_count'  => $unreadCount,
            'count'         => count($notifications),
            'notifications' => $notifications,
            'note'          => 'Read-only: this tool never marks notifications as read. '
                . 'Counts cover your whole inbox; the list may be filtered.',
        ];
    }

    public function summarize(array $payload): string
    {
        if (!empty($payload['error'])) {
            return 'unavailable: ' . $payload['error'];
        }

        return (int) ($payload['count'] ?? 0) . ' notification(s) shown; '
            . (int) ($payload['unread_count'] ?? 0) . ' unread of '
            . (int) ($payload['total_count'] ?? 0) . ' total';
    }
}
