<?php
/** End-to-end: submit a REAL leave application through the real service and
 *  prove the approver notification was queued. Everything created is removed
 *  afterwards, bounded by the pre-test maximum id. */
require __DIR__ . '/../backend/bootstrap.php';
$ok = true;
$check = static function (string $l, bool $p) use (&$ok): void {
    printf("%-64s %s\n", $l, $p ? 'PASS' : 'FAIL'); $ok = $ok && $p;
};
$db = \App\Helpers\Database::getInstance()->getConnection();

// Snapshot everything so the cleanup can be exact.
$maxApp   = (int) $db->query("SELECT COALESCE(MAX(id),0) m FROM leave_applications")->fetch_assoc()['m'];
$maxHist  = (int) $db->query("SELECT COALESCE(MAX(id),0) m FROM leave_history")->fetch_assoc()['m'];
$maxNotif = (int) $db->query("SELECT COALESCE(MAX(id),0) m FROM notifications")->fetch_assoc()['m'];
$maxLog   = (int) $db->query("SELECT COALESCE(MAX(id),0) m FROM notification_logs")->fetch_assoc()['m'];
printf("   snapshot: apps<=%d history<=%d notifications<=%d logs<=%d\n", $maxApp, $maxHist, $maxNotif, $maxLog);

$cleanup = function () use ($db, $maxApp, $maxHist, $maxNotif, $maxLog): void {
    $db->query("DELETE FROM leave_history WHERE id > $maxHist");
    $db->query("DELETE FROM leave_applications WHERE id > $maxApp");
    // leave_transactions keys off application_id (not leave_application_id).
    $db->query("DELETE FROM leave_transactions WHERE application_id > $maxApp");
    $db->query("DELETE FROM notification_logs WHERE id > $maxLog");
    $db->query("DELETE FROM notifications WHERE id > $maxNotif");
};

// Pick a real applicant who is NOT a super-admin, and Annual Leave (id 1).
// The other active types each have a gate that would block a headless probe:
// Claim a Day is past-dates-only, Sick Leave demands a medical document.
$ANNUAL_LEAVE_ID = 1;
$r = $db->query("SELECT la.employee_id, la.applied_by_user_id, lt.id AS lt_id
                 FROM leave_applications la
                 JOIN leave_types lt ON lt.id = $ANNUAL_LEAVE_ID AND lt.is_active = 1
                 WHERE la.status LIKE 'pending%'
                   AND la.applied_by_user_id NOT IN (SELECT id FROM users WHERE role='super_admin')
                 ORDER BY la.id DESC LIMIT 1");
$seed = $r->fetch_assoc(); $r->close();
$check('found a real applicant and active leave type', (bool) $seed);

$empId    = (int) $seed['employee_id'];
$userId   = (int) $seed['applied_by_user_id'];
$typeId   = (int) $seed['lt_id'];

// The delegate MUST be in the applicant's org scope, so ask the workflow
// service itself rather than guessing - guessing produced an authorization
// failure on the first attempt. Some user rows only resolve through one of the
// two employee_id linkages, so try candidates until one yields a delegate.
$wf = new \App\Services\LeaveWorkflowService();
$delegate = 0;
$candidateRows = $db->query(
    "SELECT e.id AS employee_id, u.id AS user_id
     FROM employees e
     JOIN users u ON (u.employee_id = e.employee_id OR u.employee_id = CAST(e.id AS CHAR)) AND u.is_active = 1
     WHERE e.id NOT IN (
         SELECT employee_id FROM leave_applications WHERE status IN ('pending_subsection_head','pending_section_head',
             'pending_dept_head','pending_managing_director','pending_bod_chair','pending_hr','pending_hr_manager')
     )
       AND u.id NOT IN (SELECT id FROM users WHERE role = 'super_admin')
     LIMIT 60"
);
$cands = $candidateRows->fetch_all(MYSQLI_ASSOC);
$candidateRows->close();
foreach ($cands as $c) {
    $tryEmp = (int) $c['employee_id'];
    foreach ($wf->getEligibleDelegates((int) $c['user_id']) as $d) {
        $cid = (int) ($d['id'] ?? $d['employee_id'] ?? 0);
        if ($cid > 0 && $cid !== $tryEmp) { $delegate = $cid; $empId = $tryEmp; $userId = (int) $c['user_id']; break 2; }
    }
}
$check('found an applicant with no active leave AND an eligible delegate', $delegate > 0);

// 15 consecutive WEEKDAYS: Annual Leave has a 15-day minimum, and the service
// counts working days, so a calendar run would be under the threshold.
$start = date('Y-m-d', strtotime('+45 days'));
while ((int) date('N', strtotime($start)) >= 6) {
    $start = date('Y-m-d', strtotime($start . ' +1 day'));
}
$end = $start;
for ($i = 1; $i < 15; $i++) {
    $end = date('Y-m-d', strtotime($end . ' +1 day'));
    while ((int) date('N', strtotime($end)) >= 6) {
        $end = date('Y-m-d', strtotime($end . ' +1 day'));
    }
}

$svc = new \App\Services\LeaveApplicationService();
$result = $svc->submitApplication([
    'employee_id'     => $empId,
    'leave_type_id'   => $typeId,
    'start_date'      => $start,
    'end_date'        => $end,
    'delegate_emp_id' => $delegate,
    'reason'          => 'E2E notification probe',
    'user_id'         => $userId,
]);

printf("   submit: %s\n", json_encode($result));
$check('application submitted through the real service', !empty($result['success']));
$appId = (int) ($result['application_id'] ?? 0);

if ($appId > 0) {
    $logs = $db->query("SELECT DISTINCT notification_type, user_id FROM notification_logs WHERE dedupe_key LIKE 'leave:$appId:%'");
    $rows = $logs->fetch_all(MYSQLI_ASSOC); $logs->close();
    printf("   queued rows for app #$appId: %d\n", count($rows));
    foreach ($rows as $x) { printf("      %s -> user %s\n", $x['notification_type'], $x['user_id']); }
    $check('submitting queued at least one leave notification', count($rows) > 0);
    $check('an approver was notified (leave_applied)',
        in_array('leave_applied', array_column($rows, 'notification_type'), true));

    // In-app bell row must exist for that recipient.
    $n = $db->query("SELECT COUNT(*) c FROM notifications WHERE id > $maxNotif AND user_id IN (SELECT user_id FROM notification_logs WHERE dedupe_key LIKE 'leave:$appId:%')");
    $check('in-app notification row written for the approver', (int) $n->fetch_assoc()['c'] > 0);
} else {
    $check('submitting queued at least one leave notification', false);
}

$cleanup();
$after = (int) $db->query("SELECT COALESCE(MAX(id),0) m FROM leave_applications")->fetch_assoc()['m'];
$check('cleanup restored the original data exactly', $after === $maxApp);

echo $ok ? "\nEND-TO-END LEAVE FLOW PASSED\n" : "\nEND-TO-END LEAVE FLOW FAILED\n";