<?php

declare(strict_types=1);

namespace App\Models;

/**
 * LeaveType Model
 * 
 * Represents a type of leave (e.g., Annual, Maternity, Paternity).
 * 
 * Table: leave_types
 * Primary Key: id
 */
class LeaveType extends BaseModel
{
    protected static string $table = 'leave_types';
    protected static string $primaryKey = 'id';
    protected static array $fillable = [
        'name',
        'description',
        'is_active',
        'created_at',
        'updated_at',
    ];

    /**
     * Get leave balances for this leave type.
     */
    public function balances(): array
    {
        return EmployeeLeaveBalance::where(['leave_type_id' => $this->id]);
    }
}