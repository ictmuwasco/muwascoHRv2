<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Contracts\CalendarContextServiceInterface;

/**
 * Calendar Context Service
 *
 * Answers "what kind of day is this?" using ONE shared implementation:
 *   - Sat/Sun are non-working days (mirrors LeaveCalculationService
 *     and AttendanceDashboardService).
 *   - Holidays come from the existing `holidays` table; recurring
 *     holidays match month+day regardless of year.
 *   - Approved leave comes from the existing `leave_applications`
 *     table with a BETWEEN check on start/end dates.
 *
 * Notification code must never re-implement these rules.
 */
class CalendarContextService implements CalendarContextServiceInterface
{
    /** @var array<string,?string> per-process holiday cache keyed by date */
    private array $holidayCache = [];

    /** @var array<string,array<int,string>> per-process leave cache keyed by date */
    private array $leaveCache = [];

    /** {@inheritDoc} */
    public function isWeekend(string $date): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($dt === false) {
            // Unparseable date - fail safe by treating it as non-working.
            return true;
        }
        return (int) $dt->format('N') >= 6;
    }

    /** {@inheritDoc} */
    public function getHolidayName(string $date): ?string
    {
        if (array_key_exists($date, $this->holidayCache)) {
            return $this->holidayCache[$date];
        }

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($dt === false) {
            return $this->holidayCache[$date] = null;
        }

        $row = \db()->fetchOne(
            "SELECT name FROM holidays
             WHERE date = ?
                OR (is_recurring = 1 AND MONTH(date) = ? AND DAY(date) = ?)
             LIMIT 1",
            'sii',
            [$date, (int) $dt->format('n'), (int) $dt->format('j')]
        );

        return $this->holidayCache[$date] = $row ? (string) $row['name'] : null;
    }

    /** {@inheritDoc} */
    public function isHoliday(string $date): bool
    {
        return $this->getHolidayName($date) !== null;
    }

    /** {@inheritDoc} */
    public function getApprovedLeavesOnDate(string $date): array
    {
        if (isset($this->leaveCache[$date])) {
            return $this->leaveCache[$date];
        }

        $rows = \db()->fetchAll(
            "SELECT la.employee_id, lt.name AS leave_type
             FROM leave_applications la
             LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
             WHERE la.status = 'approved'
               AND la.start_date <= ?
               AND la.end_date >= ?",
            'ss',
            [$date, $date]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['employee_id']] = (string) ($row['leave_type'] ?: 'Approved Leave');
        }

        return $this->leaveCache[$date] = $map;
    }

    /** {@inheritDoc} */
    public function getClockedInEmployeeIds(string $date): array
    {
        // DATE(clock_in) is the authoritative attendance day (MySQL
        // session runs at +03:00; see migration 020 attendance_date).
        $rows = \db()->fetchAll(
            "SELECT DISTINCT employee_id FROM attendance
             WHERE clock_in IS NOT NULL AND DATE(clock_in) = ?",
            's',
            [$date]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['employee_id']] = true;
        }
        return $map;
    }

    /** {@inheritDoc} */
    public function hasClockedInOn(int $employeeId, string $date): bool
    {
        $count = (int) \db()->fetchValue(
            "SELECT COUNT(*) FROM attendance
             WHERE employee_id = ? AND clock_in IS NOT NULL AND DATE(clock_in) = ?",
            'is',
            [$employeeId, $date]
        );
        return $count > 0;
    }
}
