<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\NotificationService;

/**
 * Notification Controller - REST API for notifications.
 */
class NotificationController extends BaseController
{
    /**
     * GET /api/notifications - Get user's notifications.
     */
    public function indexAction(): void
    {
        $userId = $this->getUserId();
        $notificationService = new NotificationService();
        
        $notifications = $notificationService->getUnreadNotifications($userId, 10);
        $unreadCount = $notificationService->getUnreadCount($userId);

        $this->success([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * POST /api/notifications/{id}/read - Mark notification as read.
     */
    public function markAsReadAction(int $id): void
    {
        $userId = $this->getUserId();
        $notificationService = new NotificationService();
        
        $notificationService->markAsRead($id, $userId);
        
        $unreadCount = $notificationService->getUnreadCount($userId);
        
        $this->success([
            'id' => $id,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * POST /api/notifications/read-all - Mark all notifications as read.
     */
    public function markAllReadAction(): void
    {
        $userId = $this->getUserId();
        $notificationService = new NotificationService();
        
        $notificationService->markAllAsRead($userId);
        
        $this->success([
            'unread_count' => 0,
        ]);
    }
}