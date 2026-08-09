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

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);