<?php

declare(strict_types=1);

namespace App\Models;

/**
 * FinancialYear Model
 * 
 * Represents a financial year period (July 1 - June 30).
 * 
 * Table: financial_years
 * Primary Key: id
 */
class FinancialYear extends BaseModel
{
    protected static string $table = 'financial_years';
    protected static string $primaryKey = 'id';
    protected static array $fillable = [
        'year_name',
        'start_date',
        'end_date',
        'total_days',
        'is_active',
        'created_at',
        'updated_at',
    ];

    /**
     * Get leave balances for this financial year.
     */
    public function leaveBalances(): array
    {
        $id = $this->id ?? ($this->attributes['id'] ?? null);
        return EmployeeLeaveBalance::where(['financial_year_id' => $id]);
    }

    /**
     * Get the period status (current, future, past).
     */
    public function getPeriodStatusAttribute(): string
    {
        $today = date('Y-m-d');
        $startDate = $this->start_date ?? ($this->attributes['start_date'] ?? '');
        $endDate = $this->end_date ?? ($this->attributes['end_date'] ?? '');
        
        if ($today < $startDate) {
            return 'future';
        } elseif ($today <= $endDate) {
            return 'current';
        }
        return 'past';
    }

    /**
     * Check if this financial year can be used for leave allocation.
     */
    public function canAllocateLeave(): bool
    {
        $isActive = $this->is_active ?? ($this->attributes['is_active'] ?? false);
        return (bool)$isActive && $this->getPeriodStatusAttribute() === 'current';
    }
}