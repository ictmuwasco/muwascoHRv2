<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'profile_token',
        'employee_id',
        'first_name',
        'last_name',
        'surname',
        'gender',
        'national_id',
        'email',
        'designation',
        'phone',
        'date_of_birth',
        'address',
        'department_id',
        'section_id',
        'position',
        'salary',
        'hire_date',
        'employment_type',
        'employee_type',
        'profile_image_url',
        'employee_status',
        'scale_id',
        'next_of_kin',
        'subsection_id',
        'office_id',
    ];

    protected $casts = [
        'salary' => 'decimal:2',
        'next_of_kin' => 'json',
        'date_of_birth' => 'date',
        'hire_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function leaveApplications(): HasMany
    {
        return $this->hasMany(LeaveApplication::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(EmployeeLeaveBalance::class);
    }
}