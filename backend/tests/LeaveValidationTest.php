<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Services\LeaveCalculationService;
use App\Services\LeaveDocumentService;
use App\Services\LeaveApplicationService;
use App\Services\Leave\LeaveTypePolicy;
use App\Services\Leave\LeaveWorkflowRules;

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

echo "=== Leave Validation Tests ===\n\n";

// Test 1: Weekend-only range should yield zero eligible days when weekends excluded
$calc = new LeaveCalculationService();
$leaveType = ['counts_weekends' => 0, 'count_holidays' => 0];
$eligible = $calc->calculateEligibleDays('2025-12-06', '2025-12-07', $leaveType);
check('Weekend-only range returns 0 eligible days when weekends excluded', $eligible === 0);

// Test 2: Mixed range with one working day should be valid
$leaveType = ['counts_weekends' => 0, 'count_holidays' => 0];
$eligible = $calc->calculateEligibleDays('2025-12-05', '2025-12-08', $leaveType);
check('Mixed range Fri-Mon returns 2 eligible days', $eligible === 2);

// Test 3: Document requirement check
$docService = new LeaveDocumentService();
check('Sick Leave requires document', $docService->requiresDocument(2));
check('Study Leave requires document', $docService->requiresDocument(5));
check('Annual Leave does not require document', !$docService->requiresDocument(1));

// ── LeaveTypePolicy: single source of truth for leave-type business rules ──
check('Policy: Annual Leave forbids backdating', !LeaveTypePolicy::allowsBackdate(1));
check('Policy: Sick Leave allows backdating', LeaveTypePolicy::allowsBackdate(2));
check('Policy: Study Leave allows backdating', LeaveTypePolicy::allowsBackdate(5));
check('Policy: Claim a Day allows backdating', LeaveTypePolicy::allowsBackdate(9));
check('Policy: Maternity Leave forbids backdating', !LeaveTypePolicy::allowsBackdate(3));
check('Policy: Sick Leave exempt from pending/on-leave block', LeaveTypePolicy::exemptFromOverlapBlock(2));
check('Policy: Annual Leave NOT exempt from pending/on-leave block', !LeaveTypePolicy::exemptFromOverlapBlock(1));
check('Policy: Sick Leave requires a medical certificate', LeaveTypePolicy::documentType(2) === 'medical_certificate');
check('Policy: Study Leave requires a study document', LeaveTypePolicy::documentType(5) === 'study_document');
check('Policy: Annual Leave requires no document', LeaveTypePolicy::documentType(1) === null);
check('Policy: flags payload exposes all four rules', LeaveTypePolicy::flags(2) === [
    'allows_backdate' => true,
    'exempt_from_overlap_block' => true,
    'requires_document' => true,
    'required_document_type' => 'medical_certificate',
]);

// ── LeaveWorkflowRules: ONE pending-status list consumed everywhere ──
$expectedPending = [
    'pending', 'pending_subsection_head', 'pending_section_head', 'pending_dept_head',
    'pending_managing_director', 'pending_hr', 'pending_hr_manager', 'pending_bod_chair',
    'pending_manager',
];
check('Workflow: pending-status list is complete (9 stages incl. column default)', LeaveWorkflowRules::PENDING_STATUSES === $expectedPending);
foreach ($expectedPending as $pendingStatus) {
    check("Workflow: '{$pendingStatus}' recognised as pending", LeaveWorkflowRules::isPending($pendingStatus));
}
check('Workflow: approved is not pending', !LeaveWorkflowRules::isPending('approved'));
check('Workflow: rejected is not pending', !LeaveWorkflowRules::isPending('rejected'));
check('Workflow: cancelled is not pending', !LeaveWorkflowRules::isPending('cancelled'));
check('Workflow: invalidated is not pending', !LeaveWorkflowRules::isPending('invalidated'));

// ── Live schema: the status ENUM must accept every status the code writes ──
// (Regression for the silent-'' corruption under the server's non-strict mode.)
$mysqli = \App\Helpers\Database::getInstance()->getConnection();
$statusRow = $mysqli->query("SHOW COLUMNS FROM leave_applications LIKE 'status'")->fetch_assoc();
foreach (['pending_hr', 'cancelled', 'invalidated'] as $needed) {
    check("Schema: leave_applications.status ENUM contains '{$needed}'", strpos($statusRow['Type'], "'{$needed}'") !== false);
}
foreach (LeaveWorkflowRules::PENDING_STATUSES as $pendingStatus) {
    check("Schema: status ENUM can store '{$pendingStatus}'", strpos($statusRow['Type'], "'{$pendingStatus}'") !== false);
}
// Half-day (Short Leave) deductions require decimal precision.
$balRow = $mysqli->query("SHOW COLUMNS FROM employee_leave_balances LIKE 'remaining_days'")->fetch_assoc();
check('Schema: employee_leave_balances.remaining_days is DECIMAL(5,2)', strpos(strtolower($balRow['Type']), 'decimal(5,2)') !== false);

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);