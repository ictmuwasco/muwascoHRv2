<?php

declare(strict_types=1);

namespace App\Models;

/**
 * EmployeeLeaveBalance Model
 * 
 * Represents an employee's leave balance for a specific leave type and financial year.
 * 
 * Table: employee_leave_balances
 * Primary Key: id
 */
class EmployeeLeaveBalance extends BaseModel
{
    protected static string $table = 'employee_leave_balances';
    protected static string $primaryKey = 'id';
    protected static array $fillable = [
        'employee_id',
        'leave_type_id',
        'financial_year_id',
        'allocated_days',
        'brought_forward_days',
        'used_days',
        'accumulated_days',
        'remaining_days',
        'created_at',
        'updated_at',
    ];

    /**
     * Get the employee for this balance.
     */
    public function employee(): ?array
    {
        return Employee::findById($this->employee_id);
    }

    /**
     * Get the leave type for this balance.
     */
    public function leaveType(): ?array
    {
        return LeaveType::findById($this->leave_type_id);
    }

    /**
     * Get the financial year for this balance.
     */
    public function financialYear(): ?array
    {
        return FinancialYear::findById($this->financial_year_id);
    }

    /**
     * Check if the balance has remaining days.
     */
    public function hasRemaining(): bool
    {
        return $this->remaining_days > 0;
    }

    /**
     * Check if the balance is exhausted.
     */
    public function isExhausted(): bool
    {
        return $this->remaining_days <= 0;
    }
}