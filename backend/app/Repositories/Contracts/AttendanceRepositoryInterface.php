<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

/**
 * Attendance Repository Interface
 *
 * Defines the contract for attendance data access operations.
 */
interface AttendanceRepositoryInterface extends RepositoryInterface
{
    /**
     * Find attendance by employee ID and date.
     */
    public function findByEmployeeAndDate(int $employeeId, string $date): ?array;

    /**
     * Get attendance records for an employee within a date range.
     */
    public function getByEmployeeAndDateRange(int $employeeId, string $startDate, string $endDate): array;

    /**
     * Get today's attendance for all employees.
     */
    public function getTodayAttendance(): array;

    /**
     * Get attendance report for a date range.
     */
    public function getReport(string $startDate, string $endDate, array $filters = []): array;

    /**
     * Clock in an employee.
     */
    public function clockIn(int $employeeId, string $date, string $time, ?string $notes = null): int;

    /**
     * Clock out an employee.
     */
    public function updateClockOut(int $id, string $time): bool;

    /**
     * Get attendance statistics for an employee.
     */
    public function getStatistics(int $employeeId, int $year, int $month): array;

    /**
     * Check if employee has clocked in today.
     */
    public function hasClockedInToday(int $employeeId): bool;

    /**
     * Get late arrivals for a date range.
     */
    public function getLateArrivals(string $startDate, string $endDate): array;

    /**
     * Get attendance by department.
     */
    public function getByDepartment(int $departmentId, string $date): array;
}