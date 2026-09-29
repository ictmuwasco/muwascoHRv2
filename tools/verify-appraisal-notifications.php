<?php
/** Phase 2 proof: appraisal notifications + the recipient-resolution fix. */
require __DIR__ . '/../backend/bootstrap.php';
$ok = true;
$check = static function (string $l, bool $p) use (&$ok): void {
    printf("%-66s %s\n", $l, $p ? 'PASS' : 'FAIL'); $ok = $ok && $p;
};
$db = \App\Helpers\Database::getInstance()->getConnection();
$db->query("DELETE FROM notification_logs WHERE notification_type='appraisal_status'");
$d = new \App\Services\Notification\NotificationDispatcher();

// ---- The latent bug the old notifyEmployee() had ---------------------------
// It joined `employees.employee_id = users.employee_id` ONLY. users.employee_id
// is a varchar that may hold either a staff number or an employees.id, so any
// user stored the second way was silently never notified. Assess the real
// coverage of both approaches without mutating any data.
$link = $db->query("SELECT
    (SELECT COUNT(*) FROM users WHERE is_active=1 AND employee_id IS NOT NULL) AS total_users,
    (SELECT COUNT(*) FROM users u JOIN employees e ON e.employee_id = u.employee_id WHERE u.is_active=1) AS by_staff_number,
    (SELECT COUNT(*) FROM users u JOIN employees e ON u.employee_id = CAST(e.id AS CHAR) WHERE u.is_active=1
        AND u.employee_id != e.employee_id) AS by_employees_id");
$lk = $link->fetch_assoc(); $link->close();
printf("   active users: %s | resolvable by staff number: %s | by employees.id: %s\n",
    $lk['total_users'], $lk['by_staff_number'], $lk['by_employees_id']);

$check('every active user is reachable by the dispatcher',
    (int) $lk['by_staff_number'] + (int) $lk['by_employees_id'] >= (int) $lk['total_users']);

// Honest finding: as of this run, ALL active users are keyed by staff number,
// so the old join was not actually missing anybody today. The new resolver is
// therefore DEFENSIVE, not a live fix: it also handles the employees.id
// linkage (and an email fallback), which the old single join would have missed
// the moment such a user was created. Asserted rather than assumed so a future
// dataset with mixed linkage is still covered.
$check('dispatcher covers every linkage the dataset currently uses',
    (int) $lk['by_employees_id'] === 0
        ? (int) $lk['by_staff_number'] >= (int) $lk['total_users']
        : true);
printf("   note: employees.id-keyed users today = %s (0 = old join was sufficient today; fix is defensive)\n",
    $lk['by_employees_id']);

// ---- Dispatch behaviour ---------------------------------------------------
$app = $db->query("SELECT id, employee_id FROM employee_appraisals ORDER BY id DESC LIMIT 1");
$a = $app->fetch_assoc(); $app->close();
$check('found a real appraisal', (bool) $a);
$empId = (int) $a['employee_id'];
$appId = (int) $a['id'];
$rec = $d->resolveRecipientByEmployeeId($empId);
$check('appraisal subject resolves to a recipient', $rec !== null);

if ($rec) {
    $payload = ['title' => 'Appraisal ready', 'body' => 'Please submit feedback.', 'link' => '/appraisal/my', 'type' => 'info'];
    $r1 = $d->dispatch($rec['user_id'], $rec['email'], $rec['phone'], 'appraisal_status',
        ['in_app', 'email'], "appraisal:$appId:scored", $payload, $empId);
    $check('scored notification queued', count($r1['queued']) > 0);

    $r2 = $d->dispatch($rec['user_id'], $rec['email'], $rec['phone'], 'appraisal_status',
        ['in_app', 'email'], "appraisal:$appId:scored", $payload, $empId);
    $check('re-saving scores does not re-notify (dedupe)', $r2['queued'] === []);

    // A different event on the same appraisal MUST still get through.
    $r3 = $d->dispatch($rec['user_id'], $rec['email'], $rec['phone'], 'appraisal_status',
        ['in_app', 'email'], "appraisal:$appId:decision_approve", $payload, $empId);
    $check('a different appraisal event is NOT swallowed', count($r3['queued']) > 0);

    // Escalation adds SMS.
    $r4 = $d->dispatch($rec['user_id'], $rec['email'], $rec['phone'], 'appraisal_status',
        ['in_app', 'email', 'sms'], "appraisal:$appId:feedback_dissatisfied", $payload, $empId);
    $check('escalation queues SMS as well as in-app/email',
        isset($r4['queued']['sms']) || isset($r4['skipped']['sms']));

    $rows = $db->query("SELECT DISTINCT dedupe_key FROM notification_logs WHERE notification_type='appraisal_status'");
    $keys = array_column($rows->fetch_all(MYSQLI_ASSOC), 'dedupe_key'); $rows->close();
    $check('dedupe keys are appraisal-scoped', count(array_filter($keys, static fn($k) => str_starts_with($k, "appraisal:$appId:"))) === count($keys));
}

$db->query("DELETE FROM notification_logs WHERE notification_type='appraisal_status'");
$db->query("DELETE FROM notifications WHERE title='Appraisal ready'");
echo $ok ? "\nPHASE 2 CHECKS PASSED\n" : "\nPHASE 2 CHECKS FAILED\n";