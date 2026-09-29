<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Services\Notification\NotificationInboxService;

/**
 * Notification Controller - REST API for the in-app inbox.
 *
 * The response envelope keeps its original shape - `notifications` and
 * `unread_count` - so existing clients keep working. What changed is the
 * content: the previous implementation returned only the 10 newest UNREAD
 * rows, so a user who had read everything saw an empty list instead of their
 * history, and there was no way to page.
 */
class NotificationController extends BaseController
{
    private NotificationInboxService $inbox;

    public function __construct()
    {
        // BaseController declares no constructor, so there is nothing to chain.
        $this->inbox = new NotificationInboxService();
    }

    /**
     * GET /api/notifications
     *
     * Query: page, per_page, filter=all|unread|read, category
     */
    public function indexAction(): void
    {
        $userId = $this->getUserId();

        $filter = (string) ($_GET['filter'] ?? NotificationInboxService::FILTER_ALL);
        if (!in_array($filter, [
            NotificationInboxService::FILTER_ALL,
            NotificationInboxService::FILTER_UNREAD,
            NotificationInboxService::FILTER_READ,
        ], true)) {
            $filter = NotificationInboxService::FILTER_ALL;
        }

        $category = trim((string) ($_GET['category'] ?? ''));
        $category = $category !== '' ? $category : null;

        [$page, $perPage] = $this->getPaginationParams();

        $result = $this->inbox->listForUser($userId, [
            'filter'   => $filter,
            'category' => $category,
            'page'     => $page,
            'per_page' => $perPage,
        ]);

        $this->success([
            'notifications' => $result['items'],
            'unread_count'  => $result['unread'],
            'total'         => $result['total'],
            'page'          => $result['page'],
            'per_page'      => $result['per_page'],
            'pages'         => $result['pages'],
            'filter'        => $filter,
            'category'      => $category,
            'categories'    => $this->inbox->categoriesFor($userId),
        ]);
    }

    /**
     * GET /api/notifications/unread - the bell dropdown payload.
     *
     * Kept separate from indexAction so the bell can poll a small, cheap,
     * always-unread-only response without dragging the whole inbox.
     */
    public function unreadAction(): void
    {
        $userId = $this->getUserId();
        $limit = min(
            NotificationInboxService::MAX_PER_PAGE,
            max(1, (int) ($_GET['limit'] ?? 5))
        );

        $this->success([
            'notifications' => $this->inbox->recentUnread($userId, $limit),
            'unread_count'  => $this->inbox->unreadCount($userId),
        ]);
    }

    /**
     * POST /api/notifications/{id}/read - Mark notification as read.
     */
    public function markAsReadAction(int $id): void
    {
        $userId = $this->getUserId();

        // False means the row does not exist OR belongs to somebody else. Both
        // are reported as "not found": saying which would reveal whether
        // another user's notification id exists.
        if (!$this->inbox->markAsRead($id, $userId)) {
            $this->notFound('Notification not found');
            return;
        }

        $this->success([
            'id'           => $id,
            'unread_count' => $this->inbox->unreadCount($userId),
        ]);
    }

    /**
     * POST /api/notifications/read-all - Mark all notifications as read.
     *
     * Named markAllAsReadAction to match the route registered in api.php
     * ('markAllAsRead'). It was previously called markAllReadAction, which no
     * route resolved to, so this endpoint had always returned
     * CONTROLLER_METHOD_NOT_FOUND.
     */
    public function markAllAsReadAction(): void
    {
        $userId = $this->getUserId();
        $affected = $this->inbox->markAllAsRead($userId);

        $this->success([
            'marked_read'  => $affected,
            // Read back from the database rather than asserted as 0: a
            // concurrent write would otherwise make the client's badge wrong.
            'unread_count' => $this->inbox->unreadCount($userId),
        ]);
    }
}