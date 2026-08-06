<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Contracts\AttendanceServiceInterface;
use App\Repositories\Contracts\AttendanceRepositoryInterface;
use App\Repositories\Contracts\EmployeeRepositoryInterface;

/**
 * Attendance Service
 *
 * Contains business logic for attendance management.
 * Orchestrates repository operations and enforces business rules.
 */
class AttendanceService implements AttendanceServiceInterface
{
    private ?AttendanceRepositoryInterface $attendanceRepository = null;
    private ?EmployeeRepositoryInterface $employeeRepository = null;
    private array $dependencies = [];

    public function setAttendanceRepository(AttendanceRepositoryInterface $repository): void
    {
        $this->attendanceRepository = $repository;
    }

    public function setEmployeeRepository(EmployeeRepositoryInterface $repository): void
    {
        $this->employeeRepository = $repository;
    }

    public function setDependency(string $name, mixed $dependency): void
    {
        $this->dependencies[$name] = $dependency;
    }

    public function getDependency(string $name): mixed
    {
        return $this->dependencies[$name] ?? null;
    }

    public function clockIn(int $employeeId, ?string $notes = null): int
    {
        // Business rule: Check if employee exists
        $employee = $this->employeeRepository->findById($employeeId);
        if (!$employee) {
            throw new \InvalidArgumentException('Employee not found');
        }

        // Business rule: Check if employee is active
        if (isset($employee['employee_status']) && $employee['employee_status'] !== 'active') {
            throw new \InvalidArgumentException('Cannot clock in: Employee is not active');
        }

        // Business rule: Check if already clocked in today
        if ($this->attendanceRepository->hasClockedInToday($employeeId)) {
            throw new \InvalidArgumentException('Employee has already clocked in today');
        }

        // Business rule: Get current date and time
        $date = date('Y-m-d');
        $time = date('H:i:s');

        // Business rule: Determine status based on time
        $status = 'present';
        $expectedArrivalTime = '09:00:00'; // Configurable
        if ($time > $expectedArrivalTime) {
            $status = 'late';
        }

        // Create attendance record
        $attendanceData = [
            'employee_id' => $employeeId,
            'date' => $date,
            'clock_in_time' => $time,
            'status' => $status,
            'notes' => $notes ?? '',
        ];

        return $this->attendanceRepository->create($attendanceData);
    }

    public function clockOut(int $attendanceId): bool
    {
        // Business rule: Check if attendance record exists
        $attendance = $this->attendanceRepository->findById($attendanceId);
        if (!$attendance) {
            throw new \InvalidArgumentException('Attendance record not found');
        }

        // Business rule: Check if already clocked out
        if (!empty($attendance['clock_out_time'])) {
            throw new \InvalidArgumentException('Employee has already clocked out');
        }

        // Business rule: Get current time
        $time = date('H:i:s');

        // Calculate overtime if applicable
        $overtimeHours = 0;
        $expectedDepartureTime = '17:00:00'; // Configurable
        if ($time > $expectedDepartureTime) {
            $clockIn = new \DateTime($attendance['clock_in_time']);
            $clockOut = new \DateTime($time);
            $expectedOut = new \DateTime($expectedDepartureTime);
            $interval = $clockOut->diff($expectedOut);
            $overtimeHours = (float)$interval->format('%H') + ((float)$interval->format('%I') / 60);
        }

        // Update attendance record
        $updateData = [
            'clock_out_time' => $time,
            'overtime_hours' => $overtimeHours > 0 ? $overtimeHours : null,
        ];

        return $this->attendanceRepository->update($attendanceId, $updateData);
    }

    public function getAttendanceByEmployee(int $employeeId, string $startDate, string $endDate): array
    {
        // Business rule: Validate employee exists
        $employee = $this->employeeRepository->findById($employeeId);
        if (!$employee) {
            throw new \InvalidArgumentException('Employee not found');
        }

        return $this->attendanceRepository->getByEmployeeAndDateRange($employeeId, $startDate, $endDate);
    }

    public function getTodayAttendance(): array
    {
        return $this->attendanceRepository->getTodayAttendance();
    }

    public function getAttendanceReport(string $startDate, string $endDate, array $filters = []): array
    {
        // Business rule: Validate date range
        if ($startDate > $endDate) {
            throw new \InvalidArgumentException('Start date cannot be after end date');
        }

        return $this->attendanceRepository->getReport($startDate, $endDate, $filters);
    }

    public function getAttendanceStatistics(int $employeeId, int $year, int $month): array
    {
        // Business rule: Validate employee exists
        $employee = $this->employeeRepository->findById($employeeId);
        if (!$employee) {
            throw new \InvalidArgumentException('Employee not found');
        }

        // Business rule: Validate month
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Invalid month');
        }

        return $this->attendanceRepository->getStatistics($employeeId, $year, $month);
    }

    public function getLateArrivals(string $startDate, string $endDate): array
    {
        // Business rule: Validate date range
        if ($startDate > $endDate) {
            throw new \InvalidArgumentException('Start date cannot be after end date');
        }

        return $this->attendanceRepository->getLateArrivals($startDate, $endDate);
    }

    public function hasClockedInToday(int $employeeId): bool
    {
        return $this->attendanceRepository->hasClockedInToday($employeeId);
    }

    public function validateAttendanceData(array $data): array
    {
        $errors = [];

        // Business rule: Employee ID is required
        if (empty($data['employee_id'])) {
            $errors[] = 'Employee ID is required';
        }

        // Business rule: Date is required
        if (empty($data['date'])) {
            $errors[] = 'Date is required';
        }

        // Business rule: Clock in time is required for clock in
        if (empty($data['clock_in_time'])) {
            $errors[] = 'Clock in time is required';
        }

        return $errors;
    }
}