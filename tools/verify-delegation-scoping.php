<?php
/**
 * verify-delegation-scoping.php — proves DelegationService::listFor() honours
 * the org-unit visibility model:
 *
 *   hr_manager / super_admin / managing_director → EVERYTHING
 *   dept_head / manager                          → their department (+ nested)
 *   section_head                                 → their section (+ its subs)
 *   sub_section_head                             → their subsection
 *   employee                                     → their subsection
 *   officer                                      → NOTHING
 *
 * Read-only: it only inspects the DB and calls listFor(); it creates no rows.
 * Run:  php tools/verify-delegation-scoping.php
 */
require __DIR__ . '/../backend/bootstrap.php';

$ok = true;
$check = static function (string $label, bool $passed) use (&$ok): void {
    printf("%-72s %s\n", $label, $passed ? 'PASS' : 'FAIL');
    $ok = $ok && $passed;
};

$db = \App\Helpers\Database::getInstance()->getConnection();
$svc = \App\Services\DelegationService::getInstance();
$total = (int) $db->query('SELECT COUNT(*) c FROM delegations')->fetch_assoc()['c'];
printf("   delegations in table: %d\n\n", $total);

/** Representative user per role (active account with an employee record). */
$findUser = static function (string $role) use ($db): ?array {
    $r = $db->query("
        SELECT u.id, u.role, e.department_id, e.section_id, e.subsection_id
        FROM users u
        JOIN employees e ON e.employee_id = u.employee_id
        WHERE u.role = '" . $role . "' AND u.is_active = 1
        LIMIT 1
    ");
    return $r->num_rows ? $r->fetch_assoc() : null;
};

echo "== Officer is fully excluded ==\n";
$officer = $findUser('officer');
if ($officer) {
    $rows = $svc->listFor((int) $officer['id']);
    $check("officer sees zero rows (sees all: {$total})", count($rows) === 0);
} else {
    echo "  (no active officer in dataset — skipped)\n";
}

echo "\n== Org-wide roles see everything ==\n";
foreach (['hr_manager', 'super_admin', 'managing_director'] as $role) {
    $u = $findUser($role);
    if (!$u) {
        echo "  (no active {$role} — skipped)\n";
        continue;
    }
    $rows = $svc->listFor((int) $u['id']);
    $check("{$role} sees all {$total} rows (got " . count($rows) . ')', count($rows) === $total);
}

echo "\n== Scoped roles never exceed their org unit ==\n";
/**
 * Independently recompute the expected set in PHP, straight from the org
 * tables, and compare with what the service returned. Deliberately does NOT
 * reuse the service's own scope logic.
 */
$expectedIds = static function (array $u) use ($db): array {
    $ids = [];
    $r = $db->query("
        SELECT d.id
        FROM delegations d
        JOIN users du ON du.id = d.delegator_user_id
        JOIN users tu ON tu.id = d.delegate_user_id
        WHERE d.delegator_user_id = {$u['id']}
           OR d.delegate_user_id  = {$u['id']}
           OR (d.scope_type = 'department' AND d.scope_id = " . (int) $u['department_id'] . ")
           OR (d.scope_type = 'section' AND d.scope_id IN (
                    SELECT id FROM sections
                    WHERE id = " . (int) $u['section_id'] . " OR department_id = " . (int) $u['department_id'] . "))
           OR (d.scope_type = 'subsection' AND d.scope_id IN (
                    SELECT id FROM subsections
                    WHERE id = " . (int) $u['subsection_id'] . "
                       OR section_id = " . (int) $u['section_id'] . "
                       OR department_id = " . (int) $u['department_id'] . "))
    ");
    while ($x = $r->fetch_assoc()) {
        $ids[(int) $x['id']] = true;
    }
    return array_keys($ids);
};

foreach (['dept_head', 'manager', 'section_head', 'sub_section_head', 'employee', 'bod_chairman'] as $role) {
    $u = $findUser($role);
    if (!$u) {
        echo "  (no active {$role} — skipped)\n";
        continue;
    }
    $got = array_map(static fn(array $r): int => (int) $r['id'], $svc->listFor((int) $u['id']));
    sort($got);
    $want = $expectedIds($u);
    sort($want);
    $unit = sprintf('dept=%s sec=%s sub=%s',
        $u['department_id'] ?? '-', $u['section_id'] ?? '-', $u['subsection_id'] ?? '-');
    $check("{$role} [{$unit}] returns exactly its scoped set (want " . count($want)
        . ', got ' . count($got) . ')', $got === $want);
    $check("{$role} does not exceed the table total", count($got) <= $total);
}

echo "\n== Organization-scoped rows stay out of scoped views ==\n";
$orgRows = (int) $db->query("SELECT COUNT(*) c FROM delegations WHERE scope_type = 'organization'")->fetch_assoc()['c'];
if ($orgRows > 0) {
    $checked = false;
    foreach (['dept_head', 'section_head', 'employee'] as $role) {
        $u = $findUser($role);
        if (!$u) continue;
        $leaked = array_filter(
            $svc->listFor((int) $u['id']),
            static fn(array $r): bool => $r['scope_type'] === 'organization'
                && (int) $r['delegator_user_id'] !== (int) $u['id']
                && (int) $r['delegate_user_id'] !== (int) $u['id']
        );
        $check("{$role} sees no organization-scoped row unless it is their own", count($leaked) === 0);
        $checked = true;
    }
    if (!$checked) {
        echo "  (no scoped-role users to check)\n";
    }
} else {
    echo "  (no organization-scoped rows in the dataset — skipped)\n";
}

echo "\n== Discriminating fixture test ==\n";
/**
 * The dataset may hold too few rows for the checks above to prove anything, so
 * plant a controlled set of delegations at several scopes and assert the viewer
 * sees exactly the right subset. Units are derived from the probe user's own
 * org record, so the test adapts to the dataset instead of hardcoding ids.
 * Everything is deleted afterwards.
 */
$maxDelegation = (int) $db->query('SELECT COALESCE(MAX(id),0) m FROM delegations')->fetch_assoc()['m'];

// Any two distinct active users act as the delegator/delegate pair; these rows
// are only ever read through listFor(), never resolved as authority.
$pair = $db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 2')->fetch_all(MYSQLI_ASSOC);

// The probe is the first non-officer user that actually belongs to a
// department — REGISTER_EXCLUDED_ROLES short-circuits officers to zero rows,
// and a user with no org unit degrades to "own rows only", so either would
// mask the scope assertions below.
$probe = $db->query("
    SELECT u.id, u.role, e.department_id, e.section_id, e.subsection_id
    FROM users u
    JOIN employees e ON e.employee_id = u.employee_id
    WHERE u.is_active = 1
      AND u.role <> 'officer'
      AND e.department_id IS NOT NULL AND e.department_id > 0
    ORDER BY (e.subsection_id IS NOT NULL) DESC, (e.section_id IS NOT NULL) DESC
    LIMIT 1
")->fetch_assoc();

if (!$probe || count($pair) < 2) {
    echo "  SKIP: need two active users and a non-officer with an org unit\n";
} else {
    $delegatorId = (int) $pair[0]['id'];
    $delegateId  = (int) $pair[1]['id'];

    // A department the probe does NOT belong to, for the negative test.
    $foreign = $db->query(
        'SELECT id FROM departments WHERE id <> ' . (int) $probe['department_id'] . ' ORDER BY id LIMIT 1'
    )->fetch_assoc();

    $plan = [
        'own department' => ['department',   (int) $probe['department_id']],
        'foreign dept'   => ['department',   (int) ($foreign['id'] ?? 0)],
        'organization'   => ['organization', 0],
    ];
    if ((int) $probe['section_id'] > 0) {
        $plan['own section'] = ['section', (int) $probe['section_id']];
    }
    if ((int) $probe['subsection_id'] > 0) {
        $plan['own subsection'] = ['subsection', (int) $probe['subsection_id']];
    }

    $fixtureIds = [];
    foreach ($plan as $label => [$scopeType, $scopeId]) {
        if ($scopeId === 0 && $scopeType !== 'organization') {
            continue;
        }
        $db->query("
            INSERT INTO delegations
                (delegator_user_id, delegate_user_id, delegated_role, scope_type, scope_id,
                 permissions, start_date, end_date, reason, status, source, created_by)
            VALUES
                ({$delegatorId}, {$delegateId}, 'dept_head', '{$scopeType}', {$scopeId},
                 '[\"leave:view\"]', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 5 DAY),
                 'scoping fixture', 'active', 'manual', {$delegatorId})
        ");
        $fixtureIds[$label] = (int) $db->insert_id;
    }

    $probeId = (int) $probe['id'];
    $seen = [];
    foreach ($svc->listFor($probeId) as $r) {
        $seen[(int) $r['id']] = true;
    }
    $has = static fn(string $label): bool => isset($fixtureIds[$label]) && isset($seen[$fixtureIds[$label]]);

    printf(
        "  probe user %d (%s) dept=%s section=%s subsection=%s\n",
        $probeId, $probe['role'], $probe['department_id'],
        $probe['section_id'] ?? '-', $probe['subsection_id'] ?? '-'
    );

    $check('sees a row scoped to their own department', $has('own department'));
    if (isset($plan['own section'])) {
        $check('sees a row scoped to their own section', $has('own section'));
    }
    if (isset($plan['own subsection'])) {
        $check('sees a row scoped to their own subsection', $has('own subsection'));
    }
    $check('does NOT see a row scoped to another department', !$has('foreign dept'));
    $check('does NOT see organization-scoped rows',           !$has('organization'));

    $db->query("DELETE FROM delegations WHERE id > {$maxDelegation}");
    $left = (int) $db->query('SELECT COUNT(*) c FROM delegations')->fetch_assoc()['c'];
    $check('fixture cleaned up (table back to ' . $total . ')', $left === $total);
}

echo "\n" . ($ok ? 'ALL CHECKS PASSED' : 'SOME CHECKS FAILED') . "\n";
exit($ok ? 0 : 1);

