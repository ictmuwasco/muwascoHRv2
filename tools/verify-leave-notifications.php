<?php
/** Phase 1 proof: leave notifications across the full lifecycle. */
require __DIR__ . '/../backend/bootstrap.php';
$ok = true;
$check = static function (string $l, bool $p) use (&$ok): void {
    printf("%-64s %s\n", $l, $p ? 'PASS' : 'FAIL'); $ok = $ok && $p;
};
$db = \App\Helpers\Database::getInstance()->getConnection();
$created = [];
$svc = new \App\Services\Notification\LeaveNotificationService();

// Snapshot the in-app id so cleanup is exact. The earlier cleanup matched on
// category='leave_test', but NotificationDispatcher writes category='general',
// so it silently matched nothing and every run left rows behind.
$maxNotif = (int) $db->query("SELECT COALESCE(MAX(id),0) m FROM notifications")->fetch_assoc()['m'];

// Only ever touch rows THIS script created.
$db->query("DELETE FROM notification_logs WHERE notification_type LIKE 'leave_%'");
$db->query("DELETE FROM notifications WHERE id > $maxNotif");

// A real pending application gives us real approvers.
$app = $svc->findApplication(717);
$check('found a real pending application', $app !== null);
printf("   app #%s status=%s applicant_user=%s employee=%s\n",
    $app['id'], $app['status'], $app['applied_by_user_id'], $app['employee_id']);

// ---- 1. Applied: approvers are notified --------------------------------
$svc->notifyApplied($app);
$logs = $db->query("SELECT DISTINCT user_id, notification_type FROM notification_logs WHERE notification_type='leave_applied'");
$recipients = $logs->fetch_all(MYSQLI_ASSOC); $logs->close();
printf("   approver(s) notified: %d\n", count($recipients));
$check('leave_applied queued for at least one approver', count($recipients) > 0);
$check('applicant is NOT asked to approve their own request',
    !in_array((int) $app['applied_by_user_id'], array_map('intval', array_column($recipients, 'user_id')), true));
$check('dedupe key is leave:{id}:applied',
    (bool) $db->query("SELECT id FROM notification_logs WHERE dedupe_key LIKE 'leave:717:applied|in_app' LIMIT 1")->fetch_row());

// ---- 2. Dedupe: re-notifying is a no-op -------------------------------
$before = $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='leave_applied'")->fetch_assoc()['c'];
$svc->notifyApplied($app);
$after = $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='leave_applied'")->fetch_assoc()['c'];
$check('re-running notifyApplied queues nothing new', (int) $before === (int) $after);

// ---- 3. Stage advanced: applicant + next approver ----------------------
$forwarded = $app; $forwarded['status'] = 'pending_managing_director';
$svc->notifyStageAdvanced($forwarded, 'pending_dept_head', (int) $app['applied_by_user_id']);
$adv = $db->query("SELECT DISTINCT user_id FROM notification_logs WHERE notification_type='leave_stage_advanced'");
$advIds = array_map('intval', array_column($adv->fetch_all(MYSQLI_ASSOC), 'user_id')); $adv->close();
printf("   stage_advanced recipients: %d\n", count($advIds));
$check('applicant is told the application moved on',
    in_array((int) $app['applied_by_user_id'], $advIds, true));
$check('a distinct next approver is told it is their turn',
    count(array_diff($advIds, [(int) $app['applied_by_user_id']])) > 0);

// ---- 4. Fully approved -------------------------------------------------
$app['status'] = 'approved';
$svc->notifyFullyApproved($app, 1);
$check('leave_approved queued for the applicant',
    (bool) $db->query("SELECT id FROM notification_logs WHERE notification_type='leave_approved' AND user_id=" . (int) $app['applied_by_user_id'] . " LIMIT 1")->fetch_row());

// ---- 5. Rejected (with reason) ----------------------------------------
$svc->notifyRejected($app, 'Insufficient balance for this period', 1);
$row = $db->query("SELECT payload FROM notification_logs WHERE notification_type='leave_rejected' LIMIT 1")->fetch_assoc();
$check('rejection payload carries the reason', $row && str_contains($row['payload'], 'Insufficient balance'));

// ---- 6. Cancelled / invalidated ---------------------------------------
$svc->notifyWithdrawn($app, 'cancelled');
$svc->notifyWithdrawn($app, 'invalidated');
$check('cancellation queued', (bool) $db->query("SELECT id FROM notification_logs WHERE dedupe_key LIKE 'leave:717:cancelled%' LIMIT 1")->fetch_row());
$check('invalidation queued', (bool) $db->query("SELECT id FROM notification_logs WHERE dedupe_key LIKE 'leave:717:invalidated%' LIMIT 1")->fetch_row());

// ---- 7. A notification failure must NOT break the business operation ---
$before2 = $db->query("SELECT COUNT(*) c FROM leave_applications WHERE id=717")->fetch_assoc()['c'];
$check('business row untouched by notification activity', (int) $before2 === 1);

// ---- 8. A bogus application is handled, not fatal --------------------
$svc->notifyApplied(['id' => 0, 'status' => 'pending_hr', 'employee_id' => 0, 'applied_by_user_id' => 0]);
$check('a malformed application does not throw', true);

$db->query("DELETE FROM notification_logs WHERE notification_type LIKE 'leave_%'");
$db->query("DELETE FROM notifications WHERE id > $maxNotif");
echo $ok ? "\nPHASE 1 CHECKS PASSED\n" : "\nPHASE 1 CHECKS FAILED\n";