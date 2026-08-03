<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveApplication extends Model
{
    protected $fillable = [
        'financial_year_id',
        'employee_id',
        'leave_type_id',
        'start_date',
        'end_date',
        'days_requested',
        'reason',
        'deduction_details',
        'primary_days',
        'annual_days',
        'unpaid_days',
        'applied_by_user_id',
        'status',
        'applied_at',
        'section_head_approval',
        'section_head_approved_by',
        'section_head_approved_at',
        'dept_head_approval',
        'dept_head_approved_by',
        'dept_head_approved_at',
        'hr_processed_by',
        'hr_processed_at',
        'hr_comments',
        'approver_id',
        'section_head_emp_id',
        'dept_head_emp_id',
        'manager_emp_id',
        'days_deducted',
        'days_from_annual',
        'managing_director_approved_by',
        'hr_approved_by',
        'hr_approved_at',
        'managing_director_approved_at',
        'md_emp_id',
        'subsection_head_emp_id',
        'subsection_head_approval',
        'subsection_head_approved_by',
        'subsection_head_approved_at',
        'rejection_reason',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'applied_at' => 'datetime',
        'section_head_approved_at' => 'datetime',
        'dept_head_approved_at' => 'datetime',
        'hr_processed_at' => 'datetime',
        'hr_approved_at' => 'datetime',
        'managing_director_approved_at' => 'datetime',
        'subsection_head_approved_at' => 'datetime',
        'deduction_details' => 'json',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}