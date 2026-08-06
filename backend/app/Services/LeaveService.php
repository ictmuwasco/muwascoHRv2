<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Contracts\LeaveServiceInterface;
use App\Repositories\Contracts\LeaveRepositoryInterface;
use App\Repositories\Contracts\EmployeeRepositoryInterface;

/**
 * Leave Service
 *
 * Contains business logic for leave management.
 * Orchestrates repository operations and enforces business rules.
 */
class LeaveService implements LeaveServiceInterface
{
    private ?LeaveRepositoryInterface $leaveRepository = null;
    private ?EmployeeRepositoryInterface $employeeRepository = null;
    private array $dependencies = [];

    public function setLeaveRepository(LeaveRepositoryInterface $repository): void
    {
        $this->leaveRepository = $repository;
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

    public function applyLeave(int $employeeId, array $data): int
    {
        // Business rule: Check if employee exists
        $employee = $this->employeeRepository->findById($employeeId);
        if (!$employee) {
            throw new \InvalidArgumentException('Employee not found');
        }

        // Business rule: Validate leave data
        $errors = $this->validateLeaveApplication($data);
        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(', ', $errors));
        }

        // Business rule: Check for leave conflicts
        if ($this->leaveRepository->hasConflict($employeeId, $data['start_date'], $data['end_date'])) {
            throw new \InvalidArgumentException('Leave dates conflict with existing leave application');
        }

        // Business rule: Validate leave type exists
        $leaveTypes = $this->leaveRepository->getLeaveTypes();
        $leaveTypeExists = false;
        foreach ($leaveTypes as $leaveType) {
            if ($leaveType['id'] == $data['leave_type_id']) {
                $leaveTypeExists = true;
                break;
            }
        }
        if (!$leaveTypeExists) {
            throw new \InvalidArgumentException('Invalid leave type selected');
        }

        // Business rule: Calculate days requested
        $startDate = new \DateTime($data['start_date']);
        $endDate = new \DateTime($data['end_date']);
        $daysRequested = (int)$startDate->diff($endDate)->format('%a') + 1;

        // Business rule: Check leave balance
        $balance = $this->leaveRepository->getLeaveBalance($employeeId, (int)$data['leave_type_id']);
        if ($balance && $daysRequested > $balance['remaining_days']) {
            throw new \InvalidArgumentException('Insufficient leave balance');
        }

        // Business rule: Normalize data
        $leaveData = [
            'employee_id' => $employeeId,
            'leave_type_id' => (int)$data['leave_type_id'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'days_requested' => $daysRequested,
            'reason' => trim($data['reason'] ?? ''),
            'status' => 'pending',
            'applied_at' => date('Y-m-d H:i:s'),
        ];

        return $this->leaveRepository->create($leaveData);
    }

    public function getLeaveById(int $id): ?array
    {
        return $this->leaveRepository->findById($id);
    }

    public function getLeavesByEmployee(int $employeeId, int $year = 0): array
    {
        // Business rule: Validate employee exists
        $employee = $this->employeeRepository->findById($employeeId);
        if (!$employee) {
            throw new \InvalidArgumentException('Employee not found');
        }

        return $this->leaveRepository->getByEmployee($employeeId, $year);
    }

    public function searchLeaves(array $filters = [], int $page = 1, int $limit = 30): array
    {
        return $this->leaveRepository->search($filters, $page, $limit);
    }

    public function getPendingApprovals(int $managerId): array
    {
        // Business rule: Validate manager exists
        $manager = $this->employeeRepository->findById($managerId);
        if (!$manager) {
            throw new \InvalidArgumentException('Manager not found');
        }

        return $this->leaveRepository->getPendingApprovals($managerId);
    }

    public function approveLeave(int $leaveId, int $approvedBy): bool
    {
        // Business rule: Check if leave exists
        $leave = $this->leaveRepository->findById($leaveId);
        if (!$leave) {
            throw new \InvalidArgumentException('Leave application not found');
        }

        // Business rule: Check if leave is pending
        if ($leave['status'] !== 'pending') {
            throw new \InvalidArgumentException('Only pending leave applications can be approved');
        }

        // Business rule: Update leave balance
        // This would be implemented with a leave balance repository
        // For now, we'll just update the status

        return $this->leaveRepository->updateStatus($leaveId, 'approved', $approvedBy);
    }

    public function rejectLeave(int $leaveId, int $rejectedBy, ?string $reason = null): bool
    {
        // Business rule: Check if leave exists
        $leave = $this->leaveRepository->findById($leaveId);
        if (!$leave) {
            throw new \InvalidArgumentException('Leave application not found');
        }

        // Business rule: Check if leave is pending
        if ($leave['status'] !== 'pending') {
            throw new \InvalidArgumentException('Only pending leave applications can be rejected');
        }

        // Business rule: Reason is required for rejection
        if (empty($reason)) {
            throw new \InvalidArgumentException('Rejection reason is required');
        }

        // Update leave with rejection reason
        $updateData = [
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ];

        $this->leaveRepository->update($leaveId, $updateData);
        return $this->leaveRepository->updateStatus($leaveId, 'rejected', $rejectedBy);
    }

    public function cancelLeave(int $leaveId): bool
    {
        // Business rule: Check if leave exists
        $leave = $this->leaveRepository->findById($leaveId);
        if (!$leave) {
            throw new \InvalidArgumentException('Leave application not found');
        }

        // Business rule: Check if leave is pending
        if ($leave['status'] !== 'pending') {
            throw new \InvalidArgumentException('Only pending leave applications can be cancelled');
        }

        return $this->leaveRepository->updateStatus($leaveId, 'cancelled');
    }

    public function getLeaveTypes(): array
    {
        return $this->leaveRepository->getLeaveTypes();
    }

    public function getLeaveBalance(int $employeeId, int $leaveTypeId): ?array
    {
        // Business rule: Validate employee exists
        $employee = $this->employeeRepository->findById($employeeId);
        if (!$employee) {
            throw new \InvalidArgumentException('Employee not found');
        }

        return $this->leaveRepository->getLeaveBalance($employeeId, $leaveTypeId);
    }

    public function getLeaveStatistics(int $year, int $month = 0): array
    {
        // Business rule: Validate year
        if ($year < 2000 || $year > (int)date('Y') + 1) {
            throw new \InvalidArgumentException('Invalid year');
        }

        // Business rule: Validate month if provided
        if ($month > 0 && ($month < 1 || $month > 12)) {
            throw new \InvalidArgumentException('Invalid month');
        }

        return $this->leaveRepository->getStatistics($year, $month);
    }

    public function validateLeaveApplication(array $data, ?int $excludeId = null): array
    {
        $errors = [];

        // Business rule: Leave type is required
        if (empty($data['leave_type_id'])) {
            $errors[] = 'Leave type is required';
        }

        // Business rule: Start date is required
        if (empty($data['start_date'])) {
            $errors[] = 'Start date is required';
        }

        // Business rule: End date is required
        if (empty($data['end_date'])) {
            $errors[] = 'End date is required';
        }

        // Business rule: Start date must be before or equal to end date
        if (!empty($data['start_date']) && !empty($data['end_date']) && $data['start_date'] > $data['end_date']) {
            $errors[] = 'Start date cannot be after end date';
        }

        // Business rule: Reason is required
        if (empty($data['reason'])) {
            $errors[] = 'Reason for leave is required';
        }

        // Business rule: Reason must be at least 10 characters
        if (!empty($data['reason']) && strlen($data['reason']) < 10) {
            $errors[] = 'Reason must be at least 10 characters';
        }

        return $errors;
    }
}