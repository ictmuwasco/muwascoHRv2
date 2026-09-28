<?php
/** Quick smoke test: run every slice of the appraisal report as each role. */
$root = dirname(__DIR__);
require_once $root . '/backend/bootstrap.php';
if (session_status() === PHP_SESSION_NONE) { @session_start(); }

$db = \App\Helpers\Database::getInstance()->getConnection();
$find = static function (string $role) use ($db) {
    $s = $db->prepare('SELECT id, email, role, employee_id FROM users WHERE is_active=1 AND role=? LIMIT 1');
    $s->bind_param('s', $role); $s->execute();    $r = $s->get_result()->fetch_assoc(); $s->close();
    return $r ?: null;
};
$sim = static function (array $u) use ($db) {
    $emp = null;
    if (!empty($u['employee_id'])) {
        // bind_param takes its arguments BY REFERENCE, so a cast expression like
        // (int) $x is not a variable and mysqli rejects it outright.
        $empId = (int) $u['employee_id'];
        $s = $db->prepare('SELECT * FROM employees WHERE id=? LIMIT 1');
        $s->bind_param('i', $empId); $s->execute();
        $emp = $s->get_result()->fetch_assoc(); $s->close();
    }
    $_SESSION['user_id'] = (int) $u['id'];
    $_SESSION['user_role'] = $u['role'];
    $_SESSION['user_email'] = $u['email'];
    if ($emp) { foreach (['employee_id','department_id','section_id','subsection_id'] as $k) { $_SESSION[$k] = $emp[$k] ?? null; } }
    $_SESSION['session_valid'] = true; $_SESSION['last_activity'] = time();
};

foreach (['managing_director','hr_manager','section_head','officer'] as $role) {
    $u = $find($role);
    if (!$u) { echo "$role: no account\n"; continue; }
    $sim($u);
    try {
        $s = new \App\Services\Reports\AppraisalPerformanceReportService();
        $sum = $s->summary([]);
        $dep = $s->byUnit('department', []);
        $sec = $s->byUnit('section', []);
        $sub = $s->byUnit('subsection', []);
        $tr  = $s->trends([]);
        $st  = $s->byStatus([]);
        $pf  = $s->performers([], 5);
        $in  = $s->insights([]);
        $em  = $s->employees([], 1, 10, 'percentage', 'desc');
        $op  = $s->options();
        $reg = $s->appraisals([], 1, 5, 'submitted', 'desc');
        $csv = $s->exportRegister([]);
        printf(
            "%-18s scored=%-3d avg=%-6s depts=%-2d secs=%-2d subs=%-2d cycles=%-2d emps=%-3d register=%-3d csvLines=%d\n",
            $role, $sum['scored_appraisals'],
            $sum['average_percentage'] ?? 'n/a',
            count($dep), count($sec), count($sub), count($tr), $em['total'],
            $reg['total'],
            count(array_filter(explode("\n", $csv)))
        );
        if ($role === 'managing_director') {
            printf("  top    : %s (%s) %s%%\n", $pf['top'][0]['employee_name'] ?? '-', $pf['top'][0]['employee_code'] ?? '-', $pf['top'][0]['percentage'] ?? '-');
            printf("  bottom : %s (%s) %s%%\n", $pf['bottom'][0]['employee_name'] ?? '-', $pf['bottom'][0]['employee_code'] ?? '-', $pf['bottom'][0]['percentage'] ?? '-');
            foreach ($in as $line) { echo "  - $line\n"; }
            echo "  depts: " . implode(', ', array_map(fn($d) => "{$d['name']}={$d['percentage']}%", array_slice($dep,0,5))) . "\n";
            echo "  cycles: " . implode(', ', array_map(fn($t) => "{$t['label']}={$t['percentage']}%", $tr)) . "\n";
            // The register MUST agree with the headline: every scored appraisal
            // in scope, one row each. If these differ, the table and the charts
            // are describing different populations.
            echo "  register rows match summary scored count: "
                . ($reg['total'] === $sum['scored_appraisals'] ? 'YES' : "NO ({$reg['total']} vs {$sum['scored_appraisals']})") . "\n";
            echo "  csv lines match register total: "
                . ((count(array_filter(explode("\n", $csv))) - 1) === $reg['total'] ? 'YES' : 'NO') . "\n";
            echo "  first register row: " . json_encode($reg['items'][0] ?? null) . "\n";
        }
    } catch (\Throwable $e) {
        printf("%-18s ERROR %s: %s @ %s:%d\n", $role, get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine());
        foreach (array_slice($e->getTrace(), 0, 3) as $t) {
            printf("      at %s:%s %s%s%s\n", basename($t['file'] ?? '?'), $t['line'] ?? '?', $t['class'] ?? '', $t['type'] ?? '', $t['function'] ?? '');
        }
    }
}
