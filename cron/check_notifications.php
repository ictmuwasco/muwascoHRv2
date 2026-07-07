<?php
require_once '../config.php';
require_once '../notifications/NotificationService.php';

// Only allow access via command line
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_key'])) {
    die('Access denied');
}

$notificationService = new NotificationService($pdo);

// Check and trigger scheduled notifications
$notificationService->checkScheduledNotifications();

// Clean up expired notifications
$sql = "DELETE FROM notifications WHERE expires_at < NOW()";
$pdo->exec($sql);

echo "Scheduled notifications checked and expired notifications cleaned.\n";
?>