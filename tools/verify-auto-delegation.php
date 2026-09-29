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
printf("   snapshot: delegations<=%d\n\n", $maxDelegation);

$cleanup = static function () use ($db, $maxDelegation): void {
    $db->query("DELETE FROM delegations WHERE id > $maxDelegation");
};

// Pick a real APPROVED leave that carries a delegate and whose applicant and
// delegate are different people with active accounts.
$app = $db->query("
    SELECT la.id, la.start_date, la.end_date,
           applicant.id AS applicant_user_id, applicant.role AS applicant_role,
           delegate.id AS delegate_user_id
    FROM leave_applications la
    JOIN employees e  ON e.id = la.employee_id
    JOIN users applicant ON applicant.employee_id = e.employee_id AND applicant.is_active = 1
    JOIN employees de ON de.id = la.delegate_emp_id
    JOIN users delegate ON delegate.employee_id = de.employee_id AND delegate.is_active = 1
    WHERE la.status = 'approved'
      AND la.delegate_emp_id IS NOT NULL
      AND applicant.id <> delegate.id
    ORDER BY la.id DESC LIMIT 1
")->fetch_assoc();

if (!$app) {
    echo "SKIP: no approved leave with a distinct, active delegate in the dataset.\n";
    exit(0);
}
printf("   using leave #%d (%s .. %s) applicant_user=%d(%s) delegate_user=%d\n\n",
    $app['id'], $app['start_date'], $app['end_date'],
    $app['applicant_user_id'], $app['applicant_role'], $app['delegate_user_id']);

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
// filters on CURDATE BETWEEN start_date AND end_date), so a delegation for a
// future leave is correctly not yet resolvable. Exercise it with an
// application whose window covers today to prove Priority 6 really engages.
$todayWindow = $db->query("
    SELECT la.id, applicant.id AS applicant_user_id, delegate.id AS delegate_user_id
    FROM leave_applications la
    JOIN employees e ON e.id = la.employee_id
    JOIN users applicant ON applicant.employee_id = e.employee_id AND applicant.is_active = 1
    JOIN employees de ON de.id = la.delegate_emp_id
    JOIN users delegate ON delegate.employee_id = de.employee_id AND delegate.is_active = 1
    WHERE la.status = 'approved'
      AND la.start_date <= CURDATE() AND la.end_date >= CURDATE()
      AND applicant.id <> delegate.id
    ORDER BY la.id DESC LIMIT 1
")->fetch_assoc();

if ($todayWindow) {
    $r2 = $svc->createFromApprovedLeave((int) $todayWindow['id'], (int) $todayWindow['applicant_user_id']);
    $p2 = $r2['data']['permissions'] ?? [];
    [$mod, $act] = array_pad(explode(':', $p2[0] ?? 'x:y', 2), 2, '');
    $check("in-window delegation grants '{$mod}:{$act}' to the delegate",
        $svc->permissionAllowedByDelegation((int) $todayWindow['delegate_user_id'], $mod, $act));
    $check('in-window delegation is resolvable via activeForUser()',
        count($svc->activeForUser((int) $todayWindow['delegate_user_id'])) > 0);
    $svc->cancelForLeaveApplication((int) $todayWindow['id'], 'verification cleanup');
} else {
    $check('in-window leave available to test Priority 6', false);
}

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

echo "\n" . ($ok ? "ALL CHECKS PASSED" : "SOME CHECKS FAILED") . "\n";

$cleanup();
echo "Cleaned up test delegation(s).\n";
exit($ok ? 0 : 1);

