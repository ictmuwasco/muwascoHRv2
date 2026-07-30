<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

/**
 * Leave Repository Interface
 *
 * Defines the contract for leave data access operations.
 */
interface LeaveRepositoryInterface extends RepositoryInterface
{
    /**
     * Find leave application by ID.
     */
    public function findById(int $id): ?array;

    /**
     * Get leave applications for an employee.
     */
    public function getByEmployee(int $employeeId, int $year = 0): array;

    /**
     * Get leave applications with filters.
     */
    public function search(array $filters, int $page = 1, int $limit = 30): array;

    /**
     * Get pending leave applications for manager approval.
     */
    public function getPendingApprovals(int $managerId): array;

    /**
     * Get leave types.
     */
    public function getLeaveTypes(): array;

    /**
     * Get leave balance for an employee.
     */
    public function getLeaveBalance(int $employeeId, int $leaveTypeId): ?array;

    /**
     * Update leave application status.
     */
    public function updateStatus(int $id, string $status, ?int $approvedBy = null): bool;

    /**
     * Get leave statistics.
     */
    public function getStatistics(int $year, int $month = 0): array;

    /**
     * Get leave history for an employee.
     */
    public function getHistory(int $employeeId, int $year): array;

    /**
     * Check if employee has leave conflict.
     */
    public function hasConflict(int $employeeId, string $startDate, string $endDate, ?int $excludeId = null): bool;
}