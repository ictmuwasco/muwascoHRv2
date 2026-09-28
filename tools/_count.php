<?php
require __DIR__ . '/../backend/bootstrap.php';
$db = \App\Helpers\Database::getInstance()->getConnection();
printf("  %-46s %s\n", 'notifications', $db->query("SELECT COUNT(*) c FROM notifications")->fetch_assoc()['c']);
printf("  %-46s %s\n", 'notification_logs', $db->query("SELECT COUNT(*) c FROM notification_logs")->fetch_assoc()['c']);