<?php

declare(strict_types=1);

/**
 * verify-leave-pending-authority.php
 *
 * Regression harness for the /leave/manage PENDING queue (Manage Leave →
 * Pending tab). Proves the contract rules the tab depends on:
 *   1. A row leaves an approver's queue as soon as it advances to a stage they
 *      cannot decide (section head approves → pending_dept_head → gone).
 *   2. An approver never sees their OWN leave in the approvals queue.
 *   3. Every visible pending row is accepted by isAuthorisedApprover() — the
 *      same oracle the Approve button hits — for ANY approver account, so no
 *      "You are not authorised to approve this application" dead ends.
 *   4. counts.pending equals the rendered row count, and stage-matched
 *      delegations route only the stage their delegated_role owns.
 *
 * Sections 1/2/4 write inside a transaction that is ALWAYS rolled back;
 * section 3 is read-only. Run: php tools/verify-leave-pending-authority.php
 */

require __DIR__ . '/../backend/bootstrap.php';

use App\Helpers\Database;
use App\Services\DelegationService;
use App\Services\LeaveApprovalService;

$db = Database::getInstance()->getConnection();
$failures = 0;

$check = function (string $label, bool $ok) use (&$failures): void {
    if (!$ok) { $failures++; }
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
};

/** Pending-tab row ids for a user, impersonated through the session. */
$pendingIds = static function (LeaveApprovalService $svc, int $userId, string $role): array {
    $_SESSION = [
        'user_id' => $userId, 'session_valid' => true, 'user_role' => $role,
        'user_name' => 'verify', 'last_activity' => time(),
    ];
    $res = $svc->listForApprover($userId, ['limit' => 500]);
    $ids = array_map(static fn ($r) => (int) $r['id'], $res['data']['pending'] ?? []);
    sort($ids);
    return $ids;
};

$svc = new LeaveApprovalService();

const KANGARA_USER   = 284;  // section_head, employee pk 418, section 13
const LILIAN_USER    = 330;  // dept_head,    employee pk 464, department 7
const PETER_USER     = 276;  // officer,      employee pk 410
const ROW_AT_SECTION = 738;  // pending_section_head, applicant emp 410
const ROW_OWN_LEAVE  = 717;  // kangara's own pending_dept_head application

echo "== 1 & 2: own leave hidden + stage advance removes the row ==\n";
$db->begin_transaction();
try {
    $check(
        'kangara (section head) does NOT see her own leave #' . ROW_OWN_LEAVE,
        !in_array(ROW_OWN_LEAVE, $pendingIds($svc, KANGARA_USER, 'section_head'), true)
    );

    // Simulate the transition approve() performs for a section head.
    $rowId = ROW_AT_SECTION;
    $st = $db->prepare("UPDATE leave_applications SET status='pending_dept_head'
                         WHERE id=? AND status='pending_section_head'");
    $st->bind_param('i', $rowId);
    $st->execute();
    $advanced = $st->affected_rows === 1;
    $st->close();
    $check('#' . ROW_AT_SECTION . ' advanced pending_section_head -> pending_dept_head', $advanced);

    $after = $pendingIds($svc, KANGARA_USER, 'section_head');
    $check('#' . ROW_AT_SECTION . ' disappeared from the section head pending tab', !in_array(ROW_AT_SECTION, $after, true));
    $check(
        '#' . ROW_AT_SECTION . ' now appears in the dept head pending tab',
        in_array(ROW_AT_SECTION, $pendingIds($svc, LILIAN_USER, 'dept_head'), true)
    );
} finally {
    $db->rollback();
}
$restored = (string) ($db->query('SELECT status FROM leave_applications WHERE id=' . ROW_AT_SECTION)->fetch_assoc()['status'] ?? '');
$check('fixture restored after rollback (' . $restored . ')', $restored === 'pending_section_head');

echo "\n== 4: stage-matched delegation routing ==\n";
$db->begin_transaction();
try {
    $delegatorId = KANGARA_USER;
    $delegateId  = PETER_USER;
    $st = $db->prepare(
        "INSERT INTO delegations
            (delegator_user_id, delegate_user_id, delegated_role, scope_type, scope_id,
             permissions, start_date, end_date, status)
         VALUES (?, ?, 'section_head', 'section', 13, '[]', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'active')"
    );
    $st->bind_param('ii', $delegatorId, $delegateId);
    $st->execute();
    $st->close();

    $stageSql   = implode(' ', DelegationService::getInstance()->delegatedVisibilityFragments(PETER_USER, true));
    $scopeOnly  = DelegationService::getInstance()->delegatedVisibilityFragments(PETER_USER, false);
    $check('pending delegation fragment restricted to pending_section_head', strpos($stageSql, "'pending_section_head'") !== false);
    $check('pending delegation fragment excludes pending_dept_head', strpos($stageSql, "'pending_dept_head'") === false);
    $check('history (scope-only) fragments unchanged', $scopeOnly === ['(e.section_id = 13)']);

    $queue = $pendingIds($svc, PETER_USER, 'officer');
    $check('delegate sees the delegated section-stage row #739', in_array(739, $queue, true));
    $check('delegate does NOT see dept-stage row #' . ROW_OWN_LEAVE, !in_array(ROW_OWN_LEAVE, $queue, true));
    $check('delegate does NOT see his own application #' . ROW_AT_SECTION, !in_array(ROW_AT_SECTION, $queue, true));
} finally {
    $db->rollback();
}

echo "\n== 3: every approver's pending rows are authorised (no dead ends) ==\n";
$ref = new ReflectionClass($svc);
$authM = $ref->getMethod('isAuthorisedApprover');
$authM->setAccessible(true);
$getUserM = $ref->getMethod('getUserById');
$getUserM->setAccessible(true);

$approvers = $db->query(
    "SELECT u.id, u.role,
            (SELECT e.id FROM employees e WHERE e.employee_id = u.employee_id LIMIT 1) AS emp_pk
       FROM users u
      WHERE u.role IN ('super_admin','hr_manager','managing_director','bod_chairman',
                       'dept_head','section_head','sub_section_head')
        AND EXISTS (SELECT 1 FROM employees e
                     WHERE e.employee_id = u.employee_id AND e.employee_status = 'active')"
)->fetch_all(MYSQLI_ASSOC);

$rowsExamined = 0;
$ownLeaks = 0;
$countDrift = 0;
$unauthorised = 0;
$nonEmpty = 0;

foreach ($approvers as $u) {
    $uid   = (int) $u['id'];
    $role  = (string) $u['role'];
    $empPk = (int) ($u['emp_pk'] ?? 0);

    $_SESSION = [
        'user_id' => $uid, 'session_valid' => true, 'user_role' => $role,
        'user_name' => 'verify', 'last_activity' => time(),
    ];

    $res = $svc->listForApprover($uid, ['limit' => 500]);
    $rows = $res['data']['pending'] ?? [];
    $cnt = (int) ($res['data']['counts']['pending'] ?? -1);
    $dbRole = (string) ($getUserM->invoke($svc, $uid)['role'] ?? $role);

    if ($rows) { $nonEmpty++; }
    if ($cnt !== count($rows)) { $countDrift++; }

    foreach ($rows as $row) {
        $rowsExamined++;
        if ($empPk > 0 && (int) $row['employee_id'] === $empPk) { $ownLeaks++; }
        if (!$authM->invoke($svc, $uid, $row, $dbRole)) { $unauthorised++; }
    }
}

printf("  approvers: %d (%d with a non-empty queue) | rows examined: %d\n", count($approvers), $nonEmpty, $rowsExamined);
$check('no approver sees their own leave (' . $ownLeaks . ' leaks)', $ownLeaks === 0);
$check('every visible row is authorisable (' . $unauthorised . ' dead ends)', $unauthorised === 0);
$check('counts.pending equals rendered rows (' . $countDrift . ' mismatches)', $countDrift === 0);

echo "\n" . ($failures === 0 ? "ALL CHECKS PASSED" : "{$failures} CHECK(S) FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
