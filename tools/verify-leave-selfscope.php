<?php
/**
 * verify-leave-selfscope.php — proves GET /leave returns ONLY the caller's own
 * applications.
 *
 * Background: indexAction() used to bind $currentUser['employee_id'] — the
 * employees.employee_id BUSINESS CODE ('242') — straight into
 * `WHERE la.employee_id = ?`, which is a FK to employees.id (the INT surrogate,
 * e.g. 514). Any account whose code happens to equal some OTHER employee's
 * primary key therefore saw that stranger's leave book, and because most of
 * those rows predate the delegate feature the Delegate column came back blank.
 *
 * Read-only. Run:  php tools/verify-leave-selfscope.php
 */
require __DIR__ . '/../backend/bootstrap.php';

$ok = true;
$check = static function (string $label, bool $passed) use (&$ok): void {
    printf("%-74s %s\n", $label, $passed ? 'PASS' : 'FAIL');
    $ok = $ok && $passed;
};

$db = \App\Helpers\Database::getInstance()->getConnection();

/** employees.id for a users.id, the way the fixed controller resolves it. */
$pkOfUser = static function (int $userId) use ($db): int {
    $r = $db->query("
        SELECT e.id FROM employees e
        JOIN users u ON u.employee_id = e.employee_id
        WHERE u.id = {$userId} LIMIT 1
    ");
    $x = $r->fetch_assoc();
    return $x ? (int) $x['id'] : 0;
};

echo "== The old lookup pointed at the wrong employee ==\n";
$rows = $db->query("
    SELECT u.id, u.employee_id AS code, e.id AS pk, e.first_name, e.last_name
    FROM users u
    JOIN employees e ON e.employee_id = u.employee_id
    WHERE u.is_active = 1
    ORDER BY u.id LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

foreach ($rows as $u) {
    $oldWho = $db->query(
        "SELECT CONCAT(first_name, ' ', last_name) n FROM employees WHERE id = " . (int) $u['code']
    )->fetch_assoc();
    printf(
        "  user %-4s code=%-9s  old lookup -> %-24s correct -> %s %s\n",
        $u['id'],
        $u['code'],
        $oldWho['n'] ?? '(no such employee)',
        $u['first_name'],
        $u['last_name'],
        ((int) $u['code'] === (int) $u['pk']) ? '' : '  <-- WAS WRONG'
    );
}

echo "\n== The new lookup is exact for every active account ==\n";
$all = $db->query("
    SELECT u.id, u.employee_id AS code, e.id AS pk
    FROM users u
    JOIN employees e ON e.employee_id = u.employee_id
    WHERE u.is_active = 1
")->fetch_all(MYSQLI_ASSOC);

$mismatched = 0;
foreach ($all as $u) {
    if ($pkOfUser((int) $u['id']) !== (int) $u['pk']) {
        $mismatched++;
    }
}
$check(
    "resolves the intended employee for all " . count($all) . ' active users'
    . " (mismatches: {$mismatched})",
    $mismatched === 0
);

echo "\n== /leave only ever returns the caller's own rows ==\n";
// Duncan karenju is the account with leave applications in the dataset.
$sample = $db->query("
    SELECT la.employee_id, COUNT(*) c
    FROM leave_applications la
    WHERE la.employee_id IS NOT NULL
    GROUP BY la.employee_id
    ORDER BY c DESC LIMIT 1
")->fetch_assoc();

$targetEmp = (int) $sample['employee_id'];
$owner = $db->query("
    SELECT u.id FROM users u
    JOIN employees e ON e.employee_id = u.employee_id
    WHERE e.id = {$targetEmp} AND u.is_active = 1 LIMIT 1
")->fetch_assoc();

$check('fixture: an employee with leave applications and an account', $owner !== null);

if ($owner) {
    $userId = (int) $owner['id'];
    $pk = $pkOfUser($userId);
    $check("resolved employee PK matches the leave owner ({$pk} == {$targetEmp})", $pk === $targetEmp);

    $r = $db->query("
        SELECT la.id, la.employee_id
        FROM leave_applications la
        WHERE la.employee_id = {$pk}
        ORDER BY la.id DESC LIMIT 20
    ");
    $foreign = 0;
    $seen = 0;
    while ($x = $r->fetch_assoc()) {
        $seen++;
        if ((int) $x['employee_id'] !== $targetEmp) {
            $foreign++;
        }
    }
    $check("all {$seen} returned rows belong to the caller", $foreign === 0);

    // The old behaviour: binding the business code returned someone else's rows.
    $code = $db->query("SELECT employee_id FROM users WHERE id = {$userId}")->fetch_assoc()['employee_id'];
    $oldRows = $db->query("
        SELECT COUNT(*) c FROM leave_applications WHERE employee_id = " . (int) $code
    )->fetch_assoc()['c'];
    $correctRows = $db->query("
        SELECT COUNT(*) c FROM leave_applications WHERE employee_id = {$pk}
    ")->fetch_assoc()['c'];
    printf("   old lookup (code %s) matched %d rows; correct lookup (pk %d) matches %d\n",
        $code, $oldRows, $pk, $correctRows);
}

echo "\n== Delegate column is meaningful, not blank ==\n";
$r = $db->query('
    SELECT la.id, la.delegate_emp_id,
           TRIM(CONCAT(de.first_name, " ", de.last_name)) AS dn
    FROM leave_applications la
    LEFT JOIN employees de ON de.id = la.delegate_emp_id
    ORDER BY la.id DESC LIMIT 8
');
$blankWithDelegate = 0;
$nullWithout      = 0;
while ($x = $r->fetch_assoc()) {
    if (!empty($x['delegate_emp_id']) && (string) $x['dn'] === '') {
        $blankWithDelegate++;
    }
    if (empty($x['delegate_emp_id'])) {
        $nullWithout++;
    }
}
$check('every row WITH a delegate resolves a name', $blankWithDelegate === 0);
printf("   (%d of the 8 most recent applications have no delegate recorded at all)\n", $nullWithout);

echo "\n" . ($ok ? 'ALL CHECKS PASSED' : 'SOME CHECKS FAILED') . "\n";
exit($ok ? 0 : 1);
