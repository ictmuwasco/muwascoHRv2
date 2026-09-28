<?php
/** Phase 6 proof: in-app inbox listing, filtering, paging and ownership. */
require __DIR__ . '/../backend/bootstrap.php';
$ok = true;
$check = static function (string $l, bool $p) use (&$ok): void {
    printf("%-64s %s\n", $l, $p ? 'PASS' : 'FAIL'); $ok = $ok && $p;
};
$db = \App\Helpers\Database::getInstance()->getConnection();
$svc = new \App\Services\Notification\NotificationInboxService();

// Two real users with notifications, so cross-user scoping can be tested.
$r = $db->query("SELECT user_id, COUNT(*) c FROM notifications GROUP BY user_id HAVING c > 3 ORDER BY c DESC LIMIT 2");
$users = $r->fetch_all(MYSQLI_ASSOC); $r->close();
$check('found users with notifications to test against', count($users) >= 2);
$a = (int) $users[0]['user_id']; $b = (int) $users[1]['user_id'];
printf("   user A=%d (%s rows), user B=%d (%s rows)\n", $a, $users[0]['c'], $b, $users[1]['c']);

// Snapshot the read/unread state of every row this test may touch, so the
// script is REPEATABLE. Without this, a first run leaves the fixture altered
// and the second run asserts against a mix that no longer exists.
$orig = $db->query("SELECT id, is_read FROM notifications WHERE user_id IN ($a, $b)");
$origState = [];
foreach ($orig->fetch_all(MYSQLI_ASSOC) as $row) {
    $origState[(int) $row['id']] = (int) $row['is_read'];
}
$orig->close();
$restore = static function () use ($db, $origState): void {
    foreach ($origState as $nid => $was) {
        $db->query("UPDATE notifications SET is_read=$was WHERE id=$nid");
    }
};

// ---- 1. Listing + paging -------------------------------------------------
$p1 = $svc->listForUser($a, ['per_page' => 5, 'page' => 1]);
$check('returns a page of items', count($p1['items']) === 5);
$check('total reflects all of the user\'s notifications', $p1['total'] === (int) $users[0]['c']);
$check('unread count is reported', $p1['unread'] === $svc->unreadCount($a));
$p2 = $svc->listForUser($a, ['per_page' => 5, 'page' => 2]);
$check('page 2 returns different rows', $p1['items'] !== $p2['items']);
$ids1 = array_column($p1['items'], 'id'); $ids2 = array_column($p2['items'], 'id');
$check('pages do not overlap', count(array_intersect($ids1, $ids2)) === 0);

// Newest first.
$times = array_column($p1['items'], 'created_at');
$sorted = $times; rsort($sorted);
$check('items are newest first', $times === $sorted);

// Page past the end is empty, not an error.
$far = $svc->listForUser($a, ['per_page' => 5, 'page' => 999]);
$check('a page past the end returns an empty list', $far['items'] === []);

// ---- 2. Ownership: a user NEVER sees another user's notifications ---------
$aItems = $svc->listForUser($a, ['per_page' => 50])['items'];
$bIds = array_column($svc->listForUser($b, ['per_page' => 50])['items'], 'id');
$check('no overlap between two users\' inboxes',
    count(array_intersect(array_column($aItems, 'id'), $bIds)) === 0);

// ---- 3. Mark-as-read is ownership scoped ---------------------------------
$target = (int) $aItems[0]['id'];
// Ensure the target starts UNREAD so the test is independent of prior runs.
$db->query("UPDATE notifications SET is_read=0 WHERE id=$target");
$check("a user cannot mark ANOTHER user's notification read",
    $svc->markAsRead($target, $b) === false);
$stillUnread = (int) $db->query("SELECT is_read FROM notifications WHERE id=$target")->fetch_assoc()['is_read'];
$check('the notification was left untouched', $stillUnread == 0);
$check('the owner CAN mark it read', $svc->markAsRead($target, $a) === true);
$check('a non-existent id reports not-found', $svc->markAsRead(99999999, $a) === false);

// ---- 4. Filters ----------------------------------------------------------
// Build a deterministic mix: the 3 newest rows unread, the rest read.
$db->query("UPDATE notifications SET is_read=1 WHERE user_id=$a");
$db->query("UPDATE notifications SET is_read=0 WHERE user_id=$a ORDER BY id DESC LIMIT 3");
$c2 = $db->query("SELECT SUM(is_read=0) u, SUM(is_read=1) r FROM notifications WHERE user_id=$a");
$counts = $c2->fetch_assoc(); $c2->close();
$all = $svc->listForUser($a, ['filter' => 'all', 'per_page' => 50]);
$unread = $svc->listForUser($a, ['filter' => 'unread', 'per_page' => 50]);
$read = $svc->listForUser($a, ['filter' => 'read', 'per_page' => 50]);
printf("   A: unread=%s read=%s | filter unread=%s read=%s all=%s\n",
    $counts['u'], $counts['r'], $unread['total'], $read['total'], $all['total']);
$check('unread filter matches the unread count', $unread['total'] === (int) $counts['u']);
$check('read filter matches the read count', $read['total'] === (int) $counts['r']);
$check('all = unread + read', $all['total'] === (int) $counts['u'] + (int) $counts['r']);
$check('read filter contains only read rows',
    count(array_filter($read['items'], static fn($x) => (int) $x['is_read'] !== 1)) === 0);

// ---- 5. Bell payload is bounded ------------------------------------------
$bell = $svc->recentUnread($a, 3);
$check('bell payload respects its limit', count($bell) <= 3);
// recentUnread() does not SELECT is_read - everything it returns is unread by
// construction - so membership is asserted against the unread id set rather
// than by reading a column the payload deliberately omits.
$unreadRows = $svc->listForUser($a, ['filter' => 'unread', 'per_page' => 50])['items'];
$unreadIds = array_map('intval', array_column($unreadRows, 'id'));
$check('bell payload contains only unread rows',
    count(array_diff(array_map('intval', array_column($bell, 'id')), $unreadIds)) === 0);

// ---- 6. Categories -------------------------------------------------------
$cats = $svc->categoriesFor($a);
$check('categories are returned for filtering', is_array($cats) && $cats !== []);

// ---- restore ------------------------------------------------------------
$restore();
echo $ok ? "\nPHASE 6 CHECKS PASSED\n" : "\nPHASE 6 CHECKS FAILED\n";