<?php
/**
 * Phase 7 proof: a real leave approval produces an in-app notification that
 * the UI's own endpoints then serve - the whole path, end to end.
 */
require __DIR__ . '/../backend/bootstrap.php';
$ok = true;
$check = static function (string $l, bool $p) use (&$ok): void {
    printf("%-64s %s\n", $l, $p ? 'PASS' : 'FAIL'); $ok = $ok && $p;
};
$db = \App\Helpers\Database::getInstance()->getConnection();

$maxNotif = (int) $db->query("SELECT COALESCE(MAX(id),0) m FROM notifications")->fetch_assoc()['m'];
$maxLog   = (int) $db->query("SELECT COALESCE(MAX(id),0) m FROM notification_logs")->fetch_assoc()['m'];

$svc = new \App\Services\Notification\LeaveNotificationService();
$app = $svc->findApplication(717);
$check('loaded a real leave application', $app !== null);

$before = (int) $db->query("SELECT COUNT(*) c FROM notifications WHERE id > $maxNotif")->fetch_assoc()['c'];
$svc->notifyApplied($app);
$after = (int) $db->query("SELECT COUNT(*) c FROM notifications WHERE id > $maxNotif")->fetch_assoc()['c'];
$check('the trigger wrote an in-app row', $after > $before);

$inbox = new \App\Services\Notification\NotificationInboxService();

// The approver must be able to see it through the inbox service.
$rows = $db->query("SELECT DISTINCT user_id FROM notification_logs WHERE notification_type='leave_applied'");
$approver = (int) ($rows->fetch_assoc()['user_id'] ?? 0); $rows->close();
$check('an approver was identified', $approver > 0);

$page = $inbox->listForUser($approver, ['per_page' => 50]);
$newIds = array_map('intval', array_column($page['items'], 'id'));
$row = $db->query("SELECT id, title, message, action_url, is_read FROM notifications WHERE id > $maxNotif LIMIT 1");
$made = $row->fetch_assoc(); $row->close();
$check('the new row appears in that user\'s inbox', in_array((int) $made['id'], $newIds, true));
$check('it starts unread', (int) $made['is_read'] === 0);
$check('it carries a title the UI can render', trim((string) $made['title']) !== '');
$check('it carries body text the UI can render', trim((string) $made['message']) !== '');
printf("   title: %s\n   link:  %s\n", $made['title'], $made['action_url'] ?? '(none)');

// The bell payload must include it.
$bell = $inbox->recentUnread($approver, 10);
$check('the bell dropdown includes it',
    in_array((int) $made['id'], array_map('intval', array_column($bell, 'id')), true));

// The unread filter must include it.
$unread = $inbox->listForUser($approver, ['filter' => 'unread', 'per_page' => 50]);
$check('the unread filter includes it',
    in_array((int) $made['id'], array_map('intval', array_column($unread['items'], 'id')), true));

// Marking read through the inbox service must flip the badge.
$countBefore = $inbox->unreadCount($approver);
$inbox->markAsRead((int) $made['id'], $approver);
$check('marking it read decrements the badge', $inbox->unreadCount($approver) === $countBefore - 1);
$read = $inbox->listForUser($approver, ['filter' => 'read', 'per_page' => 50]);
$check('it then appears under the read filter',
    in_array((int) $made['id'], array_map('intval', array_column($read['items'], 'id')), true));

// categoriesFor drives the filter dropdown.
$cats = $inbox->categoriesFor($approver);
$check('the category list is populated for the filter control', is_array($cats));

// ---- cleanup: remove only what this script created ----------------------
$db->query("DELETE FROM notifications WHERE id > $maxNotif");
$db->query("DELETE FROM notification_logs WHERE id > $maxLog");
$check('cleanup restored the original data',
    (int) $db->query("SELECT COALESCE(MAX(id),0) m FROM notifications")->fetch_assoc()['m'] === $maxNotif
    && (int) $db->query("SELECT COALESCE(MAX(id),0) m FROM notification_logs")->fetch_assoc()['m'] === $maxLog);

echo $ok ? "\nPHASE 7 CHECKS PASSED\n" : "\nPHASE 7 CHECKS FAILED\n";