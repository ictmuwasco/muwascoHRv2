<?php

declare(strict_types=1);

namespace App\Services\Contracts;

/**
 * Calendar Context Service Interface
 *
 * Single source of truth for "what kind of day is this?" questions:
 * weekends, holidays and approved leave. Mirrors the business rules
 * already used by AttendanceDashboardService and
 * LeaveCalculationService (Sat/Sun are non-working days; recurring
 * holidays match month+day regardless of year).
 */
interface CalendarContextServiceInterface
{
    /** True when the date falls on a Saturday or Sunday. */
    public function isWeekend(string $date): bool;

    /**
     * Holiday name covering the date (exact date match, or a
     * recurring holiday matching month+day), or null.
     */
    public function getHolidayName(string $date): ?string;

    /** True when any applicable holiday covers the date. */
    public function isHoliday(string $date): bool;

    /**
     * Approved leaves covering the date, keyed by employee_id.
     * One BETWEEN query for the whole population - no N+1 lookups.
     *
     * @return array<int,string> employee_id => leave type name
     */
    public function getApprovedLeavesOnDate(string $date): array;

    /**
     * Employee IDs that have clocked in on the given date.
     *
     * @return array<int,bool> employee_id => true
     */
    public function getClockedInEmployeeIds(string $date): array;

    /** True when the given employee has a clock-in recorded on $date. */
    public function hasClockedInOn(int $employeeId, string $date): bool;
}
