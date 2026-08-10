<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;

/**
 * LeaveCalculationService
 *
 * Authoritative backend service for leave day calculations.
 * Considers weekends, public holidays, and leave type configuration.
 */
class LeaveCalculationService
{
    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Calculate eligible leave days for a date range.
     *
     * @param string $startDate
     * @param string $endDate
     * @param array $leaveType
     * @return int
     */
    public function calculateEligibleDays(string $startDate, string $endDate, array $leaveType): int
    {
        $holidays = $this->getHolidaysInRange($startDate, $endDate);
        $includeWeekends = !empty($leaveType['counts_weekends']);
        $includeHolidays = !empty($leaveType['count_holidays']);

        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $eligibleDays = 0;

        $current = clone $start;
        while ($current <= $end) {
            $dayOfWeek = (int) $current->format('N');
            $isWeekend = $dayOfWeek >= 6;
            $dateStr = $current->format('Y-m-d');
            $isHoliday = in_array($dateStr, $holidays, true);

            $countDay = true;
            if (!$includeWeekends && $isWeekend) {
                $countDay = false;
            }
            if ($countDay && !$includeHolidays && $isHoliday) {
                $countDay = false;
            }
            if ($countDay) {
                $eligibleDays++;
            }

            $current->modify('+1 day');
        }

        return $eligibleDays;
    }

    /**
     * Get public holidays in a date range.
     *
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    private function getHolidaysInRange(string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare("SELECT date FROM holidays WHERE date BETWEEN ? AND ?");
        $stmt->bind_param('ss', $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();
        $holidays = [];
        while ($row = $result->fetch_assoc()) {
            $holidays[] = $row['date'];
        }
        return $holidays;
    }

    /**
     * Calculate deduction plan based on employee balances and eligible days.
     *
     * @param int $employeeId
     * @param int $leaveTypeId
     * @param int $eligibleDays
     * @return array
     */
    public function calculateDeductionFromBalances(int $employeeId, int $leaveTypeId, int $eligibleDays): array
    {
        $deductionPlan = [
            'primary_deduction' => 0,
            'annual_deduction' => 0,
            'unpaid_days' => 0,
            'warnings' => [],
            'is_valid' => true,
        ];

        if ($eligibleDays <= 0) {
            $deductionPlan['is_valid'] = false;
            $deductionPlan['warnings'][] = 'No eligible leave days calculated.';
            return $deductionPlan;
        }

        // Claim a Day
        if ($leaveTypeId === 9) {
            $deductionPlan['warnings'][] = "This will add {$eligibleDays} days to annual leave upon approval.";
            $deductionPlan['add_to_annual'] = $eligibleDays;
            return $deductionPlan;
        }

        // Leave of Absence
        if ($leaveTypeId === 8) {
            $deductionPlan['unpaid_days'] = $eligibleDays;
            $deductionPlan['warnings'][] = "Leave of Absence — {$eligibleDays} days unpaid.";
            return $deductionPlan;
        }

        $leaveType = $this->getLeaveType($leaveTypeId);
        if (!$leaveType) {
            $deductionPlan['is_valid'] = false;
            $deductionPlan['warnings'][] = 'Invalid leave type.';
            return $deductionPlan;
        }

        $balance = $this->getLeaveBalance($employeeId, $leaveTypeId);
        $allocated = (float) ($balance['allocated_days'] ?? 0);

        if ($allocated === 0.0) {
            $deductionPlan['warnings'][] = 'Unlimited leave — no balance deduction required.';
            return $deductionPlan;
        }

        $remainingPrimary = (float) ($balance['remaining_days'] ?? 0);

        if ($eligibleDays <= $remainingPrimary) {
            $deductionPlan['primary_deduction'] = $eligibleDays;
            $deductionPlan['warnings'][] = "Deducted from {$leaveType['name']}. Remaining: " . ($remainingPrimary - $eligibleDays) . " days";
        } else {
            $deductionPlan['primary_deduction'] = $remainingPrimary;
            $remaining = $eligibleDays - $remainingPrimary;

            if ($remainingPrimary > 0) {
                $deductionPlan['warnings'][] = "{$remainingPrimary} days from {$leaveType['name']}. (Remaining: 0)";
            }

            if (!empty($leaveType['deducted_from_annual']) && $remaining > 0) {
                $annualBalance = $this->getAnnualLeaveBalance($employeeId);
                $availableAnnual = (float) ($annualBalance['remaining_days'] ?? 0);
                $annualUsed = min($availableAnnual, $remaining);
                $deductionPlan['annual_deduction'] = $annualUsed;
                $remaining -= $annualUsed;

                if ($annualUsed > 0) {
                    $deductionPlan['warnings'][] = "{$annualUsed} days from Annual Leave. Remaining: " . ($availableAnnual - $annualUsed) . " days";
                }
            }

            if ($remaining > 0) {
                $deductionPlan['unpaid_days'] = $remaining;
                $deductionPlan['warnings'][] = "{$remaining} days unpaid due to insufficient balance.";
            }
        }

        return $deductionPlan;
    }

    /**
     * Get leave type details.
     */
    private function getLeaveType(int $leaveTypeId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM leave_types WHERE id = ? AND is_active = 1");
        $stmt->bind_param('i', $leaveTypeId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ?: null;
    }

    /**
     * Get leave balance for employee/type.
     */
    private function getLeaveBalance(int $employeeId, int $leaveTypeId): array
    {
        $fyId = $this->getCurrentFinancialYearId();
        $stmt = $this->db->prepare("SELECT allocated_days, remaining_days FROM employee_leave_balances WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?");
        $stmt->bind_param('iii', $employeeId, $leaveTypeId, $fyId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ?: ['allocated_days' => 0, 'remaining_days' => 0];
    }

    /**
     * Get annual leave balance.
     */
    private function getAnnualLeaveBalance(int $employeeId): array
    {
        $annualTypeId = $this->getAnnualLeaveTypeId();
        return $this->getLeaveBalance($employeeId, $annualTypeId);
    }

    /**
     * Get current financial year ID.
     */
    private function getCurrentFinancialYearId(): int
    {
        $stmt = $this->db->prepare("SELECT id FROM financial_years WHERE end_date >= CURDATE() ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if ($result) {
            return (int) $result['id'];
        }
        $stmt = $this->db->prepare("SELECT id FROM financial_years ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ? (int) $result['id'] : 0;
    }

    /**
     * Get annual leave type ID.
     */
    private function getAnnualLeaveTypeId(): int
    {
        $stmt = $this->db->prepare("SELECT id FROM leave_types WHERE name LIKE '%annual%' LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ? (int) $result['id'] : 1;
    }
}