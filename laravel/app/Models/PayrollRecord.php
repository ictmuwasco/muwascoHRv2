<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRecord extends Model
{
    protected $fillable = [
        'payroll_period_id', 'employee_id', 'basic_salary',
        'gross_salary', 'net_salary', 'status',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function period()
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }
}