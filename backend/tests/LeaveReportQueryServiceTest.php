<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Services\LeaveReportQueryService;

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

echo "=== LeaveReportQueryService Tests ===\n\n";

$query = new LeaveReportQueryService();

// --- scopeClause (pure, no DB) ---
echo "--- Scope clause ---\n";

// HR / super admin see everything.
[$sql, $p] = LeaveReportQueryService::scopeClause(['is_hr' => true, 'is_super_admin' => false, 'is_pme_or_audit' => false]);
check('HR sees everything', $sql === '1=1' && $p === []);

[$sql, $p] = LeaveReportQueryService::scopeClause(['is_hr' => false, 'is_super_admin' => true, 'is_pme_or_audit' => false]);
check('Super admin sees everything', $sql === '1=1' && $p === []);

// Unresolved unit -> deny (1=0).
[$sql, $p] = LeaveReportQueryService::scopeClause(['is_hr' => false, 'is_super_admin' => false, 'is_pme_or_audit' => false, 'department_id' => null]);
check('Unresolved unit denies access', $sql === '1=0');

// Department head scoped to department.
[$sql, $p] = LeaveReportQueryService::scopeClause(['is_hr' => false, 'is_super_admin' => false, 'is_pme_or_audit' => false, 'department_id' => 3, 'section_id' => null, 'subsection_id' => null]);
check('Dept head pinned to department', $sql === 'e.department_id = ?' && $p === [3]);

// Section head narrowed to section within department.
[$sql, $p] = LeaveReportQueryService::scopeClause(['is_hr' => false, 'is_super_admin' => false, 'is_pme_or_audit' => false, 'is_section_head' => true, 'department_id' => 3, 'section_id' => 9, 'subsection_id' => 4]);
check('Section head pinned to dept+section+subsection', $sql === 'e.department_id = ? AND e.section_id = ? AND e.subsection_id = ?' && $p === [3, 9, 4]);

// Officer (plain staff) sees own department only.
[$sql, $p] = LeaveReportQueryService::scopeClause(['is_hr' => false, 'is_super_admin' => false, 'is_pme_or_audit' => false, 'department_id' => 2]);
check('Officer pinned to department', $sql === 'e.department_id = ?' && $p === [2]);

// --- normalizeFilters (no DB) ---
echo "\n--- Filter normalization ---\n";

$f = $query->normalizeFilters([]);
check('Default date basis is start_date', $f['date_basis'] === 'start_date');
check('Empty statuses becomes empty array', $f['statuses'] === []);
check('Empty search is empty string', $f['search'] === '');

$f = $query->normalizeFilters(['date_basis' => 'end_date', 'from' => '2026-01-01', 'to' => '2026-12-31', 'status' => 'approved']);
check('Valid date basis accepted', $f['date_basis'] === 'end_date');
check('Valid dates kept', $f['from'] === '2026-01-01' && $f['to'] === '2026-12-31');
check('Status sanitized to approved', $f['statuses'] === ['approved']);

$f = $query->normalizeFilters(['date_basis' => 'bogus']);
check('Invalid date basis falls back to start_date', $f['date_basis'] === 'start_date');

$f = $query->normalizeFilters(['from' => 'not-a-date', 'to' => '2026-01-01']);
check('Invalid from date dropped', $f['from'] === null && $f['to'] === '2026-01-01');

$f = $query->normalizeFilters(['status' => 'pending']);
check('pending status grouped', $f['statuses'] === ['pending']);

$f = $query->normalizeFilters(['status' => 'approved,pending,evil']);
check('Mixed + invalid statuses sanitized', $f['statuses'] === ['approved', 'pending']);

$f = $query->normalizeFilters(['department_id' => '5', 'leave_type_id' => '2', 'employee_id' => '9']);
check('IDs cast to ints', $f['department_id'] === 5 && $f['leave_type_id'] === 2 && $f['employee_id'] === 9);

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";

if ($failed > 0) {
    echo "\nSome tests failed. Please review the implementation.\n";
    exit(1);
} else {
    echo "\nAll tests passed successfully!\n";
    exit(0);
}