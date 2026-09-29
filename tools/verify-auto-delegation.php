<?php
/**
 * verify-auto-delegation.php — proves the "approved leave mints a duty-cover
 * delegation" flow end to end against the REAL services.
 *
 * Creates nothing permanently: the delegation it mints is removed afterwards,
 * bounded by the pre-test maximum delegation id, exactly like
 * verify-leave-e2e.php does for leave rows.
 *
 * Run:  php tools/verify-auto-delegation.php
 */
require __DIR__ . '/../backend/bootstrap.php';

$ok = true;
$check = static function (string $label, bool $passed) use (&$ok): void {
    printf("%-68s %s\n", $label, $passed ? 'PASS' : 'FAIL');
    $ok = $ok && $passed;
};

$db = \App\Helpers\Database::getInstance()->getConnection();
$svc = \App\Services\DelegationService::getInstance();

$maxDelegation = (int) $db->query('SELECT COALESCE(MAX(id),0) m FROM delegations')->fetch_assoc()['m'];
$maxLeave      = (int) $db->query('SELECT COALESCE(MAX(id),0) m FROM leave_applications')->fetch_assoc()['m'];
printf("   snapshot: delegations<=%d leave_applications<=%d\n\n", $maxDelegation, $maxLeave);

// Fingerprint every PRE-EXISTING delegation so we can prove afterwards that this
// script mutated none of them. An earlier version of this test reused a real
// approved leave as its fixture and its "withdrawal" step silently cancelled
// that leave's genuine delegation — real data destroyed by a test. The guard
// below both detects that and repairs it, so a test can never quietly damage
// production rows again.
$preExisting = [];
$r = $db->query("SELECT id, status FROM delegations WHERE id <= {$maxDelegation}");
while ($x = $r->fetch_assoc()) {
    $preExisting[(int) $x['id']] = (string) $x['status'];
}

$cleanup = static function () use ($db, $maxDelegation, $maxLeave, $preExisting): void {
    $db->query("DELETE FROM leave_history WHERE leave_application_id > $maxLeave");
    $db->query("DELETE FROM leave_applications WHERE id > $maxLeave");
    $db->query("DELETE FROM delegations WHERE id > $maxDelegation");

    // Repair anything this run changed on a pre-existing row.
    foreach ($preExisting as $id => $status) {
        $db->query("UPDATE delegations SET status = '{$status}' WHERE id = {$id}");
    }
};

/** Confirm (and if needed restore) that no pre-existing row was disturbed. */
$assertPreExistingIntact = static function () use ($db, $preExisting, $check): void {
    $drifted = [];
    foreach ($preExisting as $id => $status) {
        $now = $db->query("SELECT status FROM delegations WHERE id = {$id}")->fetch_assoc();
        if ($now === null) {
            $drifted[] = "#{$id} DELETED";
            continue;
        }
        if ($now['status'] !== $status) {
            $drifted[] = "#{$id} {$status} -> {$now['status']}";
        }
    }
    $check(
        'no pre-existing delegation was altered'
        . ($drifted === [] ? '' : ' (' . implode('; ', $drifted) . ' — restored)'),
        $drifted === []
    );
};

/**
 * Build our OWN approved leave so the test never depends on which real
 * applications happen to be un-backfilled. The backfill command
 * (tools/backfill-leave-delegations.php) legitimately consumes real approved
 * leaves, which previously made this script start failing as soon as it was
 * run — a fixture that silently stops proving anything is worse than no test.
 */
$users = $db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 3')->fetch_all(MYSQLI_ASSOC);
$empIdFor = static function (int $userId) use ($db): int {
    $r = $db->query("SELECT e.id FROM employees e JOIN users u ON u.employee_id=e.employee_id WHERE u.id={$userId} LIMIT 1");
    $x = $r->fetch_assoc();
    return $x ? (int) $x['id'] : 0;
};

$applicantUserId = (int) ($users[0]['id'] ?? 0);
$delegateUserId  = (int) ($users[1]['id'] ?? 0);
$applicantEmpId  = $empIdFor($applicantUserId);
$delegateEmpId   = $empIdFor($delegateUserId);

if ($applicantUserId <= 0 || $delegateUserId <= 0 || $delegateUserId === $applicantUserId
    || $applicantEmpId <= 0 || $delegateEmpId <= 0) {
    echo "SKIP: need two distinct active users with employee records.\n";
    exit(0);
}

$applicantRole = (string) $db->query("SELECT role FROM users WHERE id={$applicantUserId}")->fetch_assoc()['role'];

// Window covers today, so the minted delegation is immediately resolvable
// through AuthorizationService Priority 6.
$winStart = date('Y-m-d');
$winEnd   = date('Y-m-d', strtotime('+4 days'));

$db->query("
    INSERT INTO leave_applications
        (employee_id, leave_type_id, financial_year_id, start_date, end_date,
         days_requested, reason, status, applied_at, delegate_emp_id, applied_by_user_id)
    VALUES
        ({$applicantEmpId}, 1, NULL, '{$winStart}', '{$winEnd}', 1, 'verify fixture',
         'approved', NOW(), {$delegateEmpId}, {$applicantUserId})
");
$app = [
    'id'                => (int) $db->insert_id,
    'start_date'        => $winStart,
    'end_date'          => $winEnd,
    'applicant_user_id' => $applicantUserId,
    'applicant_role'    => $applicantRole,
    'delegate_user_id'  => $delegateUserId,
];
printf("   fixture leave #%d (%s .. %s) applicant_user=%d(%s) delegate_user=%d\n\n",
    $app['id'], $winStart, $winEnd, $applicantUserId, $applicantRole, $delegateUserId);

echo "== 1. Delegation is created ==\n";
$result = $svc->createFromApprovedLeave((int) $app['id'], (int) $app['applicant_user_id']);
$check('createFromApprovedLeave() succeeds', !empty($result['success']));
$check('reports a newly created row', ($result['data']['created'] ?? false) === true);

$delegationId = (int) ($result['data']['id'] ?? 0);
$row = $db->query("SELECT * FROM delegations WHERE id = {$delegationId}")->fetch_assoc();
$check('row exists in delegations', $row !== null);

echo "\n== 2. Provenance & window ==\n";
$check("source = leave_application", ($row['source'] ?? '') === 'leave_application');
$check('linked back to the leave application', (int) ($row['leave_application_id'] ?? 0) === (int) $app['id']);
$check('delegator is the applicant (person away)', (int) $row['delegator_user_id'] === (int) $app['applicant_user_id']);
$check('delegate is the appointed delegate', (int) $row['delegate_user_id'] === (int) $app['delegate_user_id']);
$check('window matches the leave start', $row['start_date'] === $app['start_date']);
$check('window matches the leave end', $row['end_date'] === $app['end_date']);
$check('minted already effective (no second HR queue)', in_array($row['status'], ['approved', 'active'], true));

echo "\n== 3. Permissions actually granted ==\n";
$perms = json_decode((string) $row['permissions'], true) ?: [];
$check('snapshot is non-empty', count($perms) > 0);
$check('contains no non-delegatable module', array_reduce(
    $perms,
    static fn(bool $carry, string $p): bool => $carry
        && !in_array(explode(':', $p, 2)[0], \App\Services\DelegationService::NON_DELEGATABLE_MODULES, true),
    true
));
// Permission resolution is DATE-driven (DelegationService::activeForUser
// filters on CURDATE BETWEEN start_date AND end_date). The synthetic leave's
// window covers today, so Priority 6 must resolve the grant right now — this is
// the check that proves the delegate really GAINS authority, not just that a
// row was written.
[$mod, $act] = array_pad(explode(':', $perms[0] ?? 'x:y', 2), 2, '');
$check("in-window delegation grants '{$mod}:{$act}' to the delegate",
    $svc->permissionAllowedByDelegation($delegateUserId, $mod, $act));
$check('in-window delegation is resolvable via activeForUser()',
    count($svc->activeForUser($delegateUserId)) > 0);
$check('the delegate does not gain the non-delegatable modules',
    !$svc->permissionAllowedByDelegation($delegateUserId, 'settings', 'view'));

echo "\n== 4. Never exceeds the absent person's own authority ==\n";
$applicantGrants = [];
$g = $db->query("SELECT module, action FROM role_permissions WHERE role = '{$app['applicant_role']}' AND is_granted = 1");
while ($r = $g->fetch_assoc()) {
    $applicantGrants["{$r['module']}:{$r['action']}"] = true;
}
$check('every granted permission was held by the applicant', array_reduce(
    $perms,
    static fn(bool $carry, string $p): bool => $carry && isset($applicantGrants[$p]),
    true
));

echo "\n== 5. Idempotency ==\n";
$again = $svc->createFromApprovedLeave((int) $app['id'], (int) $app['applicant_user_id']);
$check('second call does not create a duplicate', ($again['data']['created'] ?? true) === false);
$count = (int) $db->query("SELECT COUNT(*) c FROM delegations WHERE leave_application_id = " . (int) $app['id'])->fetch_assoc()['c'];
$check('exactly one delegation for this leave', $count === 1);

echo "\n== 6. Withdrawal when the leave is cancelled ==\n";
$svc->cancelForLeaveApplication((int) $app['id'], 'verification cleanup');
$after = $db->query("SELECT status FROM delegations WHERE id = {$delegationId}")->fetch_assoc();
$check('status becomes cancelled', ($after['status'] ?? '') === 'cancelled');
$check('is no longer treated as active authority',
    !$svc->permissionAllowedByDelegation((int) $app['delegate_user_id'], 'leave', 'view'));

echo "\n== 7. A delegate cannot serve two people at once ==\n";
// A SECOND, independent leave that nominates the same delegate, so we can aim
// the same person at two overlapping assignments.
$thirdUserId = (int) ($users[2]['id'] ?? 0);
$thirdEmpId  = $empIdFor($thirdUserId);

if ($thirdUserId <= 0 || $thirdEmpId <= 0 || $thirdUserId === $delegateUserId) {
    $check('fixture: a third active user to nominate as a second applicant', false);
} else {
    $s = date('Y-m-d');
    $e = date('Y-m-d', strtotime('+4 days'));

    $db->query("
        INSERT INTO leave_applications
            (employee_id, leave_type_id, financial_year_id, start_date, end_date,
             days_requested, reason, status, applied_at, delegate_emp_id, applied_by_user_id)
        VALUES
            ({$thirdEmpId}, 1, NULL, '{$s}', '{$e}', 1, 'conflict fixture',
             'approved', NOW(), {$delegateEmpId}, {$thirdUserId})
    ");
    $leaveId = (int) $db->insert_id;

    $check('fixture: a second approved leave nominating the same delegate', $leaveId > 0);

    // The delegate already holds the window from section 1 — but that row was
    // CANCELLED in section 6, so re-seed an active assignment to clash with.
    $countBefore = (int) $db->query('SELECT COUNT(*) c FROM delegations')->fetch_assoc()['c'];
    $maxD = (int) $db->query('SELECT COALESCE(MAX(id),0) m FROM delegations')->fetch_assoc()['m'];
    $db->query("
        INSERT INTO delegations
            (delegator_user_id, delegate_user_id, delegated_role, scope_type, scope_id,
             permissions, start_date, end_date, reason, status, source, created_by)
        VALUES
            ({$applicantUserId}, {$delegateUserId}, 'dept_head', 'organization', 0,
             '[\"leave:view\"]', '{$s}', '{$e}', 'first assignment', 'active', 'manual', 1)
    ");
    $firstId = (int) $db->insert_id;

    $conflict = $svc->delegateConflict($delegateUserId, $s, $e);
    $check('delegate is reported busy for an OVERLAPPING window', $conflict !== null);
    $check('the blocking row is the existing assignment', ($conflict['id'] ?? 0) === $firstId);

    // Non-overlapping window: the assignment has ended, so they are free again.
    $nextStart = date('Y-m-d', strtotime($e . ' +1 day'));
    $nextEnd   = date('Y-m-d', strtotime($e . ' +3 day'));
    $check('delegate is FREE once the previous leave has ended',
        $svc->delegateConflict($delegateUserId, $nextStart, $nextEnd) === null);

    // A cancelled/expired assignment releases the slot immediately.
    $db->query("UPDATE delegations SET status = 'cancelled' WHERE id = {$firstId}");
    $check('a cancelled assignment releases the slot',
        $svc->delegateConflict($delegateUserId, $s, $e) === null);

    // Restore to 'active' and prove the auto-mint path refuses the double-booking.
    $db->query("UPDATE delegations SET status = 'active' WHERE id = {$firstId}");
    $result = $svc->createFromApprovedLeave($leaveId, $thirdUserId);
    $check('createFromApprovedLeave() REFUSES a double-booked delegate',
        empty($result['success']));
    $check('the refusal names the conflicting assignment',
        str_contains((string) ($result['message'] ?? ''), 'already covering'));
    $count = (int) $db->query(
        "SELECT COUNT(*) c FROM delegations WHERE leave_application_id = {$leaveId}"
    )->fetch_assoc()['c'];
    $check('no delegation row was written for the refused leave', $count === 0);

    $db->query("DELETE FROM delegations WHERE id > {$maxD}");
    $check('conflict fixture cleaned up',
        (int) $db->query('SELECT COUNT(*) c FROM delegations')->fetch_assoc()['c'] === $countBefore);
}

echo "\n" . ($ok ? 'ALL CHECKS PASSED' : 'SOME CHECKS FAILED') . "\n";

/** Mirror of the SPA's filterRows so the preview cannot drift from the UI. */
$bucket = static function (array $r, string $tab, string $now): bool {
    switch ($tab) {
        case 'pending':  return $r['status'] === 'pending';
        case 'active':   return $r['status'] === 'active'
            || ($r['status'] === 'approved' && $r['start_date'] <= $now && $r['end_date'] >= $now);
        case 'upcoming': return $r['status'] === 'approved' && $r['start_date'] > $now;
        case 'history':  return in_array($r['status'], ['expired', 'cancelled', 'rejected'], true);
    }
    return false;
};

echo "\n== 9. What the register actually shows an org-wide viewer ==\n";
$orgWideUser = $db->query("
    SELECT u.id, CONCAT(e.first_name, ' ', e.last_name) AS nm, u.role
    FROM users u JOIN employees e ON e.employee_id = u.employee_id
    WHERE u.is_active = 1 AND u.role IN ('super_admin', 'hr_manager', 'managing_director')
    ORDER BY FIELD(u.role, 'super_admin', 'hr_manager', 'managing_director')
    LIMIT 1
")->fetch_assoc();

$now = date('Y-m-d');
$rows = $svc->listFor((int) $orgWideUser['id']);
printf("  viewer: %s (%s) — %d row(s) in scope\n", $orgWideUser['nm'], $orgWideUser['role'], count($rows));

foreach (['pending', 'active', 'upcoming', 'history'] as $t) {
    $n = count(array_filter($rows, static fn(array $r): bool => $bucket($r, $t, $now)));
    printf("    %-9s %d\n", $t, $n);
}

$total = count($rows);
$bucketed = count(array_filter(
    $rows,
    static fn(array $r): bool => $bucket($r, 'pending', $now)
        || $bucket($r, 'active', $now)
        || $bucket($r, 'upcoming', $now)
        || $bucket($r, 'history', $now)
));
$check(
    'every row lands in exactly one visible tab (no silent disappearance)',
    $total === $bucketed
);

echo "\n" . ($ok ? "ALL CHECKS PASSED" : "SOME CHECKS FAILED") . "\n";

// ---------------------------------------------------------------------------
// 8. The register self-corrects: a window that has already closed must not keep
//    reading as 'active'. sweep() used to be driven only by activeForUser()
//    (permission resolution), so listFor() could answer "who is covering whom
//    right now?" with a delegation whose window closed weeks ago.
// ---------------------------------------------------------------------------
echo "\n== 8. Register lifecycle is accurate ==\n";
$svc->resetInstance();
$svc = \App\Services\DelegationService::getInstance();

$countBeforeSweep = (int) $db->query('SELECT COUNT(*) c FROM delegations')->fetch_assoc()['c'];
$maxSweep = (int) $db->query('SELECT COALESCE(MAX(id),0) m FROM delegations')->fetch_assoc()['m'];

// Plant a delegation whose window closed a week ago but which is still marked
// 'active' — the exact stale state the sweep exists to repair.
$db->query("
    INSERT INTO delegations
        (delegator_user_id, delegate_user_id, delegated_role, scope_type, scope_id,
         permissions, start_date, end_date, reason, status, source, created_by)
    VALUES
        (1, 2, 'dept_head', 'organization', 0, '[\"leave:view\"]',
         DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_SUB(CURDATE(), INTERVAL 7 DAY),
         'stale fixture', 'active', 'manual', 1)
");
$staleId = (int) $db->insert_id;
$check('fixture: a stale delegation still marked active', $staleId > 0);

// Reading the register as an org-wide role must trigger the sweep.
$orgWide = $db->query("SELECT id FROM users WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_assoc();
$svc->resetInstance();
\App\Services\DelegationService::getInstance()->listFor((int) ($orgWide['id'] ?? 1));

$after = $db->query('SELECT status FROM delegations WHERE id = ' . $staleId)->fetch_assoc();
$check(
    "a delegation whose window closed is no longer 'active' (now '{$after['status']}')",
    in_array($after['status'] ?? '', ['expired', 'cancelled', 'rejected'], true)
);

$db->query("DELETE FROM delegations WHERE id > {$maxSweep}");
$check('sweep fixture cleaned up',
    (int) $db->query('SELECT COUNT(*) c FROM delegations')->fetch_assoc()['c'] === $countBeforeSweep);

// A delegation whose window has not opened yet must NOT be active.
$future = $db->query("
    SELECT id, start_date FROM delegations
    WHERE status = 'active' AND start_date > CURDATE() LIMIT 1
")->fetch_assoc();
$check(
    'no delegation is active before its window opens',
    $future === null
);

echo "\n" . ($ok ? "ALL CHECKS PASSED" : "SOME CHECKS FAILED") . "\n";

$cleanup();
$assertPreExistingIntact();
echo "Cleaned up test delegation(s).\n";
exit($ok ? 0 : 1);


