<?php

declare(strict_types=1);

use App\Services\AttendanceReport\AttendanceReportQueryService;

$path = __DIR__ . '/../bootstrap.php';
if (!file_exists($path)) {
    // PHPUnit-style runs bootstrap via tests/bootstrap.php already.
    $path = __DIR__ . '/bootstrap.php';
}
require_once $path;

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        echo "✓ {$label}\n";
        $passed++;
    } else {
        echo "✗ {$label}\n";
        $failed++;
    }
}

echo "=== AttendanceReportQueryService Tests ===\n\n";

$query = new AttendanceReportQueryService();

// --- normalizeFilters (no DB) ---
echo "--- Filter normalization ---\n";

$f = $query->normalizeFilters([]);
check('Default from is first of month', $f['from'] === date('Y-m-01'));
check('Default to is today', $f['to'] === date('Y-m-d'));
check('No department by default', $f['department_id'] === null);
check('No statuses by default', $f['statuses'] === null);

$f = $query->normalizeFilters(['from' => '2026-08-01', 'to' => '2026-08-31']);
check('Valid dates kept', $f['from'] === '2026-08-01' && $f['to'] === '2026-08-31');

$f = $query->normalizeFilters(['from' => 'garbage']);
check('Invalid from date falls back to default', $f['from'] === date('Y-m-01'));

$f = $query->normalizeFilters(['department_id' => '5', 'office_id' => '2', 'employee_id' => '9']);
check('IDs cast to ints', $f['department_id'] === 5 && $f['office_id'] === 2 && $f['employee_id'] === 9);

$f = $query->normalizeFilters(['department_id' => '0']);
check('Zero ID treated as absent', $f['department_id'] === null);

$f = $query->normalizeFilters(['status' => 'late']);
check('Status sanitised to list', $f['statuses'] === ['late']);

$f = $query->normalizeFilters(['status' => ['late', 'missing']]);
check('Status list kept', $f['statuses'] === ['late', 'missing']);

// --- scopeDepartmentId (pure) ---
echo "\n--- Scope pinning ---\n";

check('HR sees everything (null dept pin)', $query->scopeDepartmentId(['is_hr' => true, 'department_id' => 3]) === null);
check('Super admin sees everything', $query->scopeDepartmentId(['is_super_admin' => true, 'department_id' => 3]) === null);
check('Others pinned to own department', $query->scopeDepartmentId(['is_hr' => false, 'is_super_admin' => false, 'department_id' => 3]) === 3);
check('Unresolved unit -> no pin', $query->scopeDepartmentId(['is_hr' => false, 'is_super_admin' => false, 'department_id' => null]) === null);

// --- buildWhere (pure SQL-fragment building) ---
echo "\n--- WHERE building ---\n";

$hrScope = ['is_hr' => true, 'is_super_admin' => false];
[$where, $types, $params] = $query->buildWhere($query->normalizeFilters(['from' => '2026-08-01', 'to' => '2026-08-07']), $hrScope);
check('Date range always present', str_contains($where, 'a.attendance_date BETWEEN ? AND ?'));
check('Date bind types are ss', str_starts_with($types, 'ss'));
check('Date params first', $params[0] === '2026-08-01' && $params[1] === '2026-08-07');

[$where, $types, $params] = $query->buildWhere($query->normalizeFilters(['from' => '2026-08-01', 'to' => '2026-08-07', 'department_id' => 4]), $hrScope);
check('Department filter applied', str_contains($where, 'e.department_id = ?') && in_array(4, $params, true));

$deptScope = ['is_hr' => false, 'is_super_admin' => false, 'department_id' => 7];
[$where, , $params] = $query->buildWhere($query->normalizeFilters(['from' => '2026-08-01', 'to' => '2026-08-07']), $deptScope);
check('Scoped user pinned to department', str_contains($where, 'e.department_id = ?') && end($params) === 7);

[$where, , $params] = $query->buildWhere($query->normalizeFilters(['from' => '2026-08-01', 'to' => '2026-08-07', 'department_id' => 2]), $deptScope);
check('Explicit filter wins over scope pin', count(array_keys($params, 2)) === 1 && !in_array(7, $params, true));

// --- recordStatusCondition (pure) ---
echo "\n--- Record-status condition ---\n";

[$sql, $types, $params] = $query->recordStatusCondition(['present']);
check('present -> closed clean session fragment', str_contains($sql, 'a.is_late = 0') && str_contains($sql, 'a.clock_out IS NOT NULL'));

[$sql] = $query->recordStatusCondition(['late']);
check('late -> is_late flag', $sql === '(a.is_late = 1)');

[$sql] = $query->recordStatusCondition(['missing']);
check('missing -> open session without auto close', str_contains($sql, 'a.auto_clocked_out = 0') && str_contains($sql, "a.clock_out IS NULL"));

[$sql] = $query->recordStatusCondition(['auto']);
check('auto -> auto_clocked_out flag', $sql === '(a.auto_clocked_out = 1)');

[$sql] = $query->recordStatusCondition(['late', 'missing']);
check('statuses OR-combined', str_contains($sql, ' OR '));

[$sql, $types, $params] = $query->recordStatusCondition(['absent', 'on_leave']);
check('Calendar statuses yield no record condition', $sql === '' && $types === '' && $params === []);

[$sql] = $query->recordStatusCondition([]);
check('Empty statuses yield no condition', $sql === '');

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
