<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Repositories\Contracts\LeaveRepositoryInterface;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\LeaveRepositoryInterface as LeaveRepoInterface;

/**
 * Leave Service Interface
 *
 * Defines the contract for leave business logic operations.
 */
interface LeaveServiceInterface extends ServiceInterface
{
    public function setLeaveRepository(LeaveRepositoryInterface $repository): void;
    public function setEmployeeRepository(EmployeeRepositoryInterface $repository): void;

    /**
     * Apply for leave.
     */
    public function applyLeave(int $employeeId, array $data): int;

    /**
     * Get leave by ID.
     */
    public function getLeaveById(int $id): ?array;

    /**
     * Get leaves by employee.
     */
    public function getLeavesByEmployee(int $employeeId, int $year = 0): array;

    /**
     * Search leaves.
     */
    public function searchLeaves(array $filters = [], int $page = 1, int $limit = 30): array;

    /**
     * Get pending approvals for manager.
     */
    public function getPendingApprovals(int $managerId): array;

    /**
     * Approve leave.
     */
    public function approveLeave(int $leaveId, int $approvedBy): bool;

    /**
     * Reject leave.
     */
    public function rejectLeave(int $leaveId, int $rejectedBy, ?string $reason = null): bool;

    /**
     * Cancel leave.
     */
    public function cancelLeave(int $leaveId): bool;

    /**
     * Get leave types.
     */
    public function getLeaveTypes(): array;

    /**
     * Get leave balance.
     */
    public function getLeaveBalance(int $employeeId, int $leaveTypeId): ?array;

    /**
     * Get leave statistics.
     */
    public function getLeaveStatistics(int $year, int $month = 0): array;

    /**
     * Validate leave application.
     */
    public function validateLeaveApplication(array $data, ?int $excludeId = null): array;
}