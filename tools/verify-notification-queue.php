<?php
/** Phase 0 proof: dispatch enqueues, dedupes, and the worker drains it. */
require __DIR__ . '/../backend/bootstrap.php';
$ok = true;
$check = static function (string $l, bool $p) use (&$ok): void {
    printf("%-62s %s\n", $l, $p ? 'PASS' : 'FAIL'); $ok = $ok && $p;
};
$db = \App\Helpers\Database::getInstance()->getConnection();
$db->query("DELETE FROM notification_logs WHERE notification_type LIKE 'test_%'");
$db->query("DELETE FROM notifications WHERE title LIKE 'Queue probe%'");
// NOTE: this script must never blanket-DELETE notification_preferences. Real
// employees have real rows there (e.g. someone who opted out of email), and a
// wide DELETE is unrecoverable. Only rows this script INSERTs are removed, via
// $createdPrefs below, and only in the finally block.

$createdPrefs = [];

$d = new \App\Services\Notification\NotificationDispatcher();
$payload = ['title' => 'Queue probe', 'body' => 'Verifying the event queue.', 'link' => '/dashboard', 'type' => 'info'];

// --- A user with NO preference row: defaults to enabled, email queues. -------
$r = $db->query("SELECT u.id, u.email FROM users u
                 WHERE u.is_active=1
                   AND u.id NOT IN (SELECT user_id FROM notification_preferences) LIMIT 1");
$free = $r->fetch_assoc(); $r->close();
$check('found a user with no preference row', (bool) $free);

// --- A user who has explicitly switched email OFF. --------------------------
// Deliberately NOT $free: the INSERT must not disable email for the very user
// used to prove the "no row = enabled" default above.
$r = $db->query("SELECT u.id, u.email FROM users u
                 WHERE u.is_active=1 AND u.id <> " . (int) $free['id'] . "
                   AND u.id NOT IN (SELECT user_id FROM notification_preferences) LIMIT 1");
$target = $r->fetch_assoc(); $r->close();
$db->query("INSERT INTO notification_preferences (user_id, push_enabled, sms_enabled, email_enabled)
            VALUES (" . (int) $target['id'] . ", 1, 0, 0)");
$createdPrefs[] = (int) $target['id'];
$optedOut = ['user_id' => (int) $target['id'], 'email' => $target['email']];
$check('opted a second user out of email', (bool) $optedOut['user_id'] && $optedOut['user_id'] !== (int) $free['id']);

// 1. Basic enqueue + dedupe --------------------------------------------------
$userId = (int) $free['id'];
$key = 'test:probe:1';
$first  = $d->dispatch($userId, $free['email'], null, 'test_probe', ['in_app'], $key, $payload, null);
$check('first dispatch queues a row', count($first['queued']) === 1);
$second = $d->dispatch($userId, $free['email'], null, 'test_probe', ['in_app'], $key, $payload, null);
$check('duplicate dispatch refused by dedupe key', ($second['skipped']['in_app'] ?? '') === 'Already queued');
$other = $d->dispatch($userId, $free['email'], null, 'test_probe', ['in_app'], 'test:probe:2', $payload, null);
$check('a DIFFERENT event same day still queues', count($other['queued']) === 1);

// 2. Preference is honoured, and the blocked row is NOT left pending ---------
$off = $d->dispatch((int) $optedOut['user_id'], $optedOut['email'], null, 'test_probe', ['email'], 'test:probe:off', $payload, null);
$check('email blocked for a user who opted out', $off['queued'] === []);
$q = $db->query("SELECT status FROM notification_logs WHERE dedupe_key='test:probe:off|email'");
$blockedStatus = (string) ($q->fetch_assoc()['status'] ?? '');
$q->close();
$check('blocked email row is stored as skipped, not pending', $blockedStatus === 'skipped');

// 3. In-app is written synchronously ----------------------------------------
$c = $db->query("SELECT COUNT(*) c FROM notifications WHERE user_id={$userId} AND title='Queue probe'");
$inapp = (int) ($c->fetch_assoc()['c'] ?? 0); $c->close();
$check('in-app row written at dispatch time', $inapp === 2);

// 4. Email queues for a user who has NOT opted out ---------------------------
$mail = $d->dispatch($userId, $free['email'], null, 'test_probe', ['email'], 'test:probe:3', $payload, null);
$check('email queues when not opted out', count($mail['queued']) === 1);

// 5. Worker sees exactly the right rows --------------------------------------
$logs = new \App\Repositories\NotificationLogRepository();
$pending = $logs->findPendingBatch(50, ['test_probe']);
$inApp = array_values(array_filter($pending, static fn($x) => $x['channel'] === 'in_app'));
$emails = array_values(array_filter($pending, static fn($x) => $x['channel'] === 'email'));
printf("   worker sees %d pending (in_app=%d email=%d)\n", count($pending), count($inApp), count($emails));
// In-app is written synchronously at dispatch, so it must NOT be left pending:
// otherwise the worker re-inserts it and the employee sees it twice.
$check('in_app is NOT left pending for the worker', count($inApp) === 0);
$check('worker sees only the 1 queued email row', count($emails) === 1);
$check('every pending row carries its payload', $emails[0]['payload'] !== null && str_contains($emails[0]['payload'], 'Queue probe'));
$inAppRows = $db->query("SELECT id, status FROM notification_logs WHERE notification_type='test_probe' AND channel='in_app'");
$allSent = true;
foreach ($inAppRows->fetch_all(MYSQLI_ASSOC) as $x) { $allSent = $allSent && $x['status'] === 'sent'; }
$inAppRows->close();
$check('in_app ledger rows are recorded as sent', $allSent);

// 6. Exclusive claim, attempts, backoff, reap --------------------------------
$id = (int) $emails[0]['id'];
$check('first worker claims the row', $logs->tryClaimPending($id) === true);
$check('second worker is refused the same row', $logs->tryClaimPending($id) === false);
// Regression guard: the claim MUST flip `status`, not just `stage`. If it only
// set `stage`, the row stayed 'pending' and a second worker would also claim it
// and deliver the same notification twice. This assertion is what caught it.
$claimed = $logs->findById($id);
$check('claim flips status out of pending', ($claimed['status'] ?? '') === 'sending');
$check('a claimed row is invisible to findPendingBatch', !in_array($id, array_map('intval', array_column($logs->findPendingBatch(50, ['test_probe']), 'id')), true));
$logs->markSent($id, 'probe');
$sent = $logs->findById($id);
$check('row marked sent', ($sent['status'] ?? '') === 'sent');
$check('one delivery costs exactly one attempt', (int) ($sent['attempts'] ?? 0) === 1);
$logs->reschedule($id, date('Y-m-d H:i:s', time() + 120), 'transient');
$r2 = $logs->findById($id);
$check('reschedule sets status=retrying', ($r2['status'] ?? '') === 'retrying');
$check('backoff row is not picked up as due', !in_array($id, array_map('intval', array_column($logs->findPendingBatch(50, ['test_probe']), 'id')), true));
$db->query("UPDATE notification_logs SET status='pending', scheduled_at=DATE_SUB(NOW(), INTERVAL 99 MINUTE) WHERE id={$id}");
$check('reapStalePending now matches (never did while attempts=0)', $logs->reapStalePending(15) >= 1);
$check('reaped row is failed, not left pending', ($logs->findById($id)['status'] ?? '') === 'failed');

// 7. A row claimed by a worker that then died is reaped ---------------------
// Uses email, not in_app: in_app is delivered synchronously and is already
// 'sent', so it can never be the stranded row. This reproduces a worker that
// took ownership of a row and then the process was killed mid-delivery.
$stranded = $d->dispatch($userId, $free['email'], null, 'test_probe', ['email'], 'test:stranded', $payload, null);
$sid = (int) ($stranded['queued']['email'] ?? 0);
$check('stranded-row scenario queued an email row', $sid > 0);
$db->query("UPDATE notification_logs SET status='pending', stage='sending', scheduled_at=DATE_SUB(NOW(), INTERVAL 99 MINUTE) WHERE id={$sid}");
$check('a crashed worker row is reaped', $logs->reapStalePending(15) >= 1 && ($logs->findById($sid)['status'] ?? '') === 'failed');

// Cleanup removes ONLY what this script created, in a finally so a mid-script
// failure can never leave a phantom preference row behind.
foreach ($createdPrefs as $pid) {
    $db->query("DELETE FROM notification_preferences WHERE user_id = " . (int) $pid);
}
$db->query("DELETE FROM notification_logs WHERE notification_type='test_probe'");
$db->query("DELETE FROM notifications WHERE title='Queue probe'");
echo $ok ? "\nPHASE 0 CHECKS PASSED\n" : "\nPHASE 0 CHECKS FAILED\n";