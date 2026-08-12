<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Services\LeaveCalculationService;
use App\Services\LeaveDocumentService;
use App\Services\LeaveApplicationService;

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

echo "=== Leave Application Date Calculation Tests ===\n\n";

$calc = new LeaveCalculationService();
$docService = new LeaveDocumentService();

// Test 1: Normal working days (Monday to Friday)
echo "--- Test 1: Normal Working Days (Mon-Fri) ---\n";
$leaveType = ['counts_weekends' => 0, 'count_holidays' => 0];
$eligible = $calc->calculateEligibleDays('2025-12-01', '2025-12-05', $leaveType);
check('Mon-Fri returns 5 eligible days', $eligible === 5);

// Test 2: Weekend only (Saturday to Sunday)
echo "\n--- Test 2: Weekend Only (Sat-Sun) ---\n";
$eligible = $calc->calculateEligibleDays('2025-12-06', '2025-12-07', $leaveType);
check('Weekend-only returns 0 eligible days', $eligible === 0);

// Test 3: Friday through Sunday
echo "\n--- Test 3: Friday through Sunday ---\n";
$eligible = $calc->calculateEligibleDays('2025-12-05', '2025-12-07', $leaveType);
check('Fri-Sun returns 1 eligible day', $eligible === 1);

// Test 4: Saturday through Monday
echo "\n--- Test 4: Saturday through Monday ---\n";
$eligible = $calc->calculateEligibleDays('2025-12-06', '2025-12-08', $leaveType);
check('Sat-Mon returns 1 eligible day', $eligible === 1);

// Test 5: Friday through Monday
echo "\n--- Test 5: Friday through Monday ---\n";
$eligible = $calc->calculateEligibleDays('2025-12-05', '2025-12-08', $leaveType);
check('Fri-Mon returns 2 eligible days', $eligible === 2);

// Test 6: Thursday through Tuesday
echo "\n--- Test 6: Thursday through Tuesday ---\n";
$eligible = $calc->calculateEligibleDays('2025-12-04', '2025-12-09', $leaveType);
check('Thu-Tue returns 4 eligible days', $eligible === 4);

// Test 7: Leave type that counts weekends
echo "\n--- Test 7: Leave Type That Counts Weekends ---\n";
$leaveTypeWithWeekends = ['counts_weekends' => 1, 'count_holidays' => 0];
$eligible = $calc->calculateEligibleDays('2025-12-05', '2025-12-07', $leaveTypeWithWeekends);
check('Fri-Sun with weekend counting returns 3 eligible days', $eligible === 3);

// Test 8: Document requirements
echo "\n--- Test 8: Document Requirements ---\n";
check('Sick Leave (id 2) requires document', $docService->requiresDocument(2));
check('Study Leave (id 5) requires document', $docService->requiresDocument(5));
check('Annual Leave (id 1) does not require document', !$docService->requiresDocument(1));
check('Short Leave (id 6) does not require document', !$docService->requiresDocument(6));

// Test 9: Zero-day validation in LeaveApplicationService
echo "\n--- Test 9: Zero-Day Application Rejection ---\n";
$appService = new LeaveApplicationService();
$result = $appService->submitApplication([
    'employee_id' => 1,
    'leave_type_id' => 6, // Short Leave
    'start_date' => '2025-12-06',
    'end_date' => '2025-12-07',
    'delegate_emp_id' => 2,
    'reason' => 'Weekend leave test',
    'user_id' => 1,
]);
check('Weekend-only application is rejected', !$result['success']);
check('Error message mentions no eligible days', 
    strpos($result['message'], 'No eligible leave days') !== false ||
    strpos($result['message'], 'no eligible leave days') !== false
);

// Test 10: Valid mixed date range should be accepted
echo "\n--- Test 10: Valid Mixed Date Range ---\n";
$result = $appService->submitApplication([
    'employee_id' => 1,
    'leave_type_id' => 6, // Short Leave
    'start_date' => '2025-12-05',
    'end_date' => '2025-12-08',
    'delegate_emp_id' => 2,
    'reason' => 'Long weekend leave test with valid days',
    'user_id' => 1,
]);
check('Fri-Mon range is accepted (has eligible days)', $result['success']);

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";

if ($failed > 0) {
    echo "\nSome tests failed. Please review the implementation.\n";
    exit(1);
} else {
    echo "\nAll tests passed successfully!\n";
    exit(0);
}