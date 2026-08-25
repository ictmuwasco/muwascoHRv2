<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AppTime;
use App\Services\Contracts\CalendarContextServiceInterface;

/**
 * AttendanceReminderEligibilityService
 *
 * THE single authority for the question:
 *   "Does this employee need an attendance reminder right now?"
 *
 * Evaluation pipeline (short-circuits on first failure):
 *   active employee -> working day -> not a holiday ->
 *   not on approved leave -> has not already clocked in today.
 *
 * Attendance business rules are deliberately kept away from any
 * delivery channel code: push/SMS only ever consume EligibilityResult.
 */
class AttendanceReminderEligibilityService
{
    // Reason codes surfaced to logs / audit UI.
    public const REASON_ELIGIBLE           = 'ELIGIBLE';
    public const REASON_EMPLOYEE_INACTIVE  = 'EMPLOYEE_INACTIVE';
    public const REASON_NOT_WORKING_DAY    = 'NOT_WORKING_DAY';
    public const REASON_PUBLIC_HOLIDAY     = 'PUBLIC_HOLIDAY';
    public const REASON_ON_LEAVE           = 'ON_LEAVE';
    public const REASON_ALREADY_CLOCKED_IN = 'ALREADY_CLOCKED_IN';

    private CalendarContextServiceInterface $calendar;

    public function __construct(?CalendarContextServiceInterface $calendar = null)
    {
        $this->calendar = $calendar ?? new CalendarContextService();
    }

    /**
     * Fetch reminder candidates: every ACTIVE employee joined with
     * their login account, in one query (no N+1). Employees without a
     * user account cannot receive push or be logged against user_id,
     * so they are excluded here (documented limitation).
     *
     * @return array<int,array> list of candidate rows:
     *   employee_id (employees.id), employee_no, name, phone, user_id
     */
    public function getCandidates(string $date): array
    {
        return \db()->fetchAll(
            "SELECT e.id AS employee_id,
                    e.employee_id AS employee_no,
                    TRIM(CONCAT(e.first_name, ' ', COALESCE(e.last_name, ''))) AS name,
                    e.phone,
                    u.id AS user_id
             FROM employees e
             JOIN users u ON u.employee_id = e.employee_id
             WHERE (e.employee_status = 'active' OR e.employee_status IS NULL)
             ORDER BY e.id ASC"
        );
    }

    /**
     * Batch-evaluate candidates for $date with set-based queries
     * (one lookup for leaves + one for attendance + cached holiday),
     * then per-employee rule checks in PHP.
     *
     * @param array $candidates rows from getCandidates()
     * @return array<int,EligibilityResult> keyed by employee_id
     */
    public function evaluateBatch(array $candidates, string $date): array
    {
        if ($candidates === []) {
            return [];
        }

        $onLeave   = $this->calendar->getApprovedLeavesOnDate($date);
        $clockedIn = $this->calendar->getClockedInEmployeeIds($date);
        $isWeekend = $this->calendar->isWeekend($date);
        $holiday   = $this->calendar->getHolidayName($date);

        $results = [];
        foreach ($candidates as $candidate) {
            $employeeId = (int) ($candidate['employee_id'] ?? 0);
            if ($employeeId <= 0) {
                continue;
            }
            $results[$employeeId] = $this->evaluateRow($candidate, $date, $isWeekend, $holiday, $onLeave, $clockedIn);
        }

        return $results;
    }

    /** {@inheritDoc}-style single-row evaluation used by evaluateBatch(). */
    private function evaluateRow(array $row, string $date, bool $isWeekend, ?string $holiday, array $onLeave, array $clockedIn): EligibilityResult
    {
        $employeeId = (int) $row['employee_id'];

        // 1. Active employee? (NULL status is treated as active,
        //    mirroring AttendanceDashboardService::getRosterRows)
        $status = strtolower((string) ($row['employee_status'] ?? ''));
        if ($status !== '' && $status !== 'active' && $status !== 'null') {
            return new EligibilityResult(false, self::REASON_EMPLOYEE_INACTIVE, "Status: {$status}");
        }

        // 2. Scheduled working day? (Sat/Sun non-working, mirrors LeaveCalculationService)
        if ($isWeekend) {
            return new EligibilityResult(false, self::REASON_NOT_WORKING_DAY, 'Weekend');
        }

        // 3. Public/company holiday?
        if ($holiday !== null) {
            return new EligibilityResult(false, self::REASON_PUBLIC_HOLIDAY, "Holiday: {$holiday}");
        }

        // 4. On approved leave covering today?
        if (isset($onLeave[$employeeId])) {
            return new EligibilityResult(false, self::REASON_ON_LEAVE, "Leave: {$onLeave[$employeeId]}");
        }

        // 5. Already clocked in today? (clocked in == present)
        if (isset($clockedIn[$employeeId])) {
            return new EligibilityResult(false, self::REASON_ALREADY_CLOCKED_IN, 'Clock-in recorded');
        }

        return new EligibilityResult(true, self::REASON_ELIGIBLE);
    }

    /**
     * Fresh single-employee evaluation. Used right before each
     * delayed/fallback send so an employee who clocks in between the
     * batch check and the actual send never gets notified (spec §29).
     */
    public function evaluate(int $employeeId, string $date): EligibilityResult
    {
        $row = \db()->fetchOne(
            "SELECT id, employee_status FROM employees WHERE id = ? LIMIT 1",
            'i',
            [$employeeId]
        );

        if ($row === null) {
            return new EligibilityResult(false, self::REASON_EMPLOYEE_INACTIVE, 'Employee not found');
        }
        if ($this->calendar->isWeekend($date)) {
            return new EligibilityResult(false, self::REASON_NOT_WORKING_DAY, 'Weekend');
        }
        $holiday = $this->calendar->getHolidayName($date);
        if ($holiday !== null) {
            return new EligibilityResult(false, self::REASON_PUBLIC_HOLIDAY, "Holiday: {$holiday}");
        }
        $leaves = $this->calendar->getApprovedLeavesOnDate($date);
        if (isset($leaves[$employeeId])) {
            return new EligibilityResult(false, self::REASON_ON_LEAVE, "Leave: {$leaves[$employeeId]}");
        }
        if ($this->calendar->hasClockedInOn($employeeId, $date)) {
            return new EligibilityResult(false, self::REASON_ALREADY_CLOCKED_IN, 'Clock-in recorded');
        }

        // Status check last so a deactivation is still honoured.
        $status = strtolower((string) ($row['employee_status'] ?? ''));
        if ($status !== '' && $status !== 'active') {
            return new EligibilityResult(false, self::REASON_EMPLOYEE_INACTIVE, "Status: {$status}");
        }

        return new EligibilityResult(true, self::REASON_ELIGIBLE);
    }

    /** Convenience wrapper used by channels immediately before sending. */
    public function isClockedIn(int $employeeId, ?string $date = null): bool
    {
        return $this->calendar->hasClockedInOn($employeeId, $date ?? AppTime::today());
    }
}
