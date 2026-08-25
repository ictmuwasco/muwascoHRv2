<?php

declare(strict_types=1);

/**
 * Attendance Dashboard Status Resolution Tests
 *
 * Pure unit tests for AttendanceDashboardService::resolveEmployeeStatus()
 * - the central business-rule calculator. No database connection required;
 * only the service class file is loaded directly.
 *
 * Run: php backend/tests/Unit/Services/AttendanceStatusResolutionTest.php
 */

require_once __DIR__ . '/../../../app/Services/AttendanceDashboardService.php';

use App\Services\AttendanceDashboardService;

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

echo "=== Attendance Status Resolution Tests ===\n\n";

$service = new AttendanceDashboardService();

// Helper to build context quickly.
$ctx = fn (array $overrides = []) => array_merge([
    'is_holiday'         => false,
    'is_non_working_day' => false,
    'leave_type'         => null,
    'record'             => null,
    'is_today'           => true,
], $overrides);

// --- Test group 1: Employee who clocked in ---
echo "--- Clocked-in records ---\n";
check('In-progress session today is PRESENT',
    $service->resolveEmployeeStatus($ctx([
        'record' => ['status' => 'clocked_in', 'is_late' => 0, 'clock_out' => null],
    ])) === AttendanceDashboardService::STATUS_PRESENT);

check('Late arrival is LATE even while session open',
    $service->resolveEmployeeStatus($ctx([
        'record' => ['status' => 'late', 'is_late' => 1, 'clock_out' => null],
    ])) === AttendanceDashboardService::STATUS_LATE);

check('Completed session is CLOCKED_OUT',
    $service->resolveEmployeeStatus($ctx([
        'record' => ['status' => 'clocked_out', 'is_late' => 0, 'clock_out' => '2026-08-21 17:05:00'],
    ])) === AttendanceDashboardService::STATUS_CLOCKED_OUT);

check('Late arrival keeps LATE after clock-out',
    $service->resolveEmployeeStatus($ctx([
        'record' => ['status' => 'clocked_out', 'is_late' => 1, 'clock_out' => '2026-08-21 17:05:00'],
    ])) === AttendanceDashboardService::STATUS_LATE);

// --- Test group 2: Edge session states ---
echo "\n--- Session edge cases ---\n";
check('Midnight auto-close flag yields AUTO_CLOCKED_OUT',
    $service->resolveEmployeeStatus($ctx([
        'record' => ['status' => 'clocked_in', 'is_late' => 0, 'clock_out' => null, 'auto_clocked_out' => 1],
    ])) === AttendanceDashboardService::STATUS_AUTO_CLOCKED_OUT);

check('Legacy auto_clocked_out status value also recognised',
    $service->resolveEmployeeStatus($ctx([
        'is_today' => false,
        'record'   => ['status' => 'auto_clocked_out', 'is_late' => 0, 'clock_out' => '2026-08-19 23:59:59'],
    ])) === AttendanceDashboardService::STATUS_AUTO_CLOCKED_OUT);

check('Past-day open session (defensive) is MISSING_CLOCK_OUT',
    $service->resolveEmployeeStatus($ctx([
        'is_today' => false,
        'record'   => ['status' => 'clocked_in', 'is_late' => 0, 'clock_out' => null],
    ])) === AttendanceDashboardService::STATUS_MISSING_CLOCK_OUT);

// --- Test group 3: Employee on approved leave ---
echo "\n--- Approved leave ---\n";
check('Approved leave with no clock-in is ON_LEAVE (never absent)',
    $service->resolveEmployeeStatus($ctx(['leave_type' => 'Annual Leave'])) === AttendanceDashboardService::STATUS_ON_LEAVE);

check('Approved leave wins even if a stray record exists',
    $service->resolveEmployeeStatus($ctx([
        'leave_type' => 'Sick Leave',
        'record'     => ['status' => 'clocked_in', 'is_late' => 0, 'clock_out' => null],
    ])) === AttendanceDashboardService::STATUS_ON_LEAVE);

// --- Test group 4: Holidays and weekends ---
echo "\n--- Calendar rules ---\n";
check('Public holiday is HOLIDAY',
    $service->resolveEmployeeStatus($ctx(['is_holiday' => true])) === AttendanceDashboardService::STATUS_HOLIDAY);

check('Holiday outranks approved leave',
    $service->resolveEmployeeStatus($ctx(['is_holiday' => true, 'leave_type' => 'Annual Leave'])) === AttendanceDashboardService::STATUS_HOLIDAY);

check('Holiday outranks an existing attendance record',
    $service->resolveEmployeeStatus($ctx([
        'is_holiday' => true,
        'record'     => ['status' => 'clocked_in', 'is_late' => 0, 'clock_out' => null],
    ])) === AttendanceDashboardService::STATUS_HOLIDAY);

check('Saturday/Sunday is NON_WORKING_DAY (no absence)',
    $service->resolveEmployeeStatus($ctx(['is_non_working_day' => true])) === AttendanceDashboardService::STATUS_NON_WORKING_DAY);

// --- Test group 5: Missing clock-in (business decision: immediate ABSENT) ---
echo "\n--- Missing clock-in ---\n";
check('Expected employee with no record is ABSENT immediately (today)',
    $service->resolveEmployeeStatus($ctx()) === AttendanceDashboardService::STATUS_ABSENT);

check('Expected employee with no record is ABSENT on past dates',
    $service->resolveEmployeeStatus($ctx(['is_today' => false])) === AttendanceDashboardService::STATUS_ABSENT);

check('NOT_CLOCKED_IN never occurs while grace policy disabled',
    $service->resolveEmployeeStatus($ctx()) !== AttendanceDashboardService::STATUS_NOT_CLOCKED_IN);

// --- Summary ---
echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);



