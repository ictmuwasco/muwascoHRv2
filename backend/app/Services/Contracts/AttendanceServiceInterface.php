<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Repositories\Contracts\AttendanceRepositoryInterface;
use App\Repositories\Contracts\EmployeeRepositoryInterface;

/**
 * Attendance Service Interface
 *
 * Defines the contract for attendance business logic operations.
 */
interface AttendanceServiceInterface extends ServiceInterface
{
    public function setAttendanceRepository(AttendanceRepositoryInterface $repository): void;
    public function setEmployeeRepository(EmployeeRepositoryInterface $repository): void;

    /**
     * Clock in an employee.
     */
    public function clockIn(int $employeeId, ?string $notes = null): int;

    /**
     * Clock out an employee.
     */
    public function clockOut(int $attendanceId): bool;

    /**
     * Get attendance by employee and date range.
     */
    public function getAttendanceByEmployee(int $employeeId, string $startDate, string $endDate): array;

    /**
     * Get today's attendance.
     */
    public function getTodayAttendance(): array;

    /**
     * Get attendance report.
     */
    public function getAttendanceReport(string $startDate, string $endDate, array $filters = []): array;

    /**
     * Get attendance statistics.
     */
    public function getAttendanceStatistics(int $employeeId, int $year, int $month): array;

    /**
     * Get late arrivals.
     */
    public function getLateArrivals(string $startDate, string $endDate): array;

    /**
     * Check if employee has clocked in today.
     */
    public function hasClockedInToday(int $employeeId): bool;

    /**
     * Validate attendance data.
     */
    public function validateAttendanceData(array $data): array;
}