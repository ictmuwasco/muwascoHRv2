<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appraisal extends Model
{
    protected $fillable = [
        'employee_id', 'appraisal_period', 'rating',
        'comments', 'status', 'created_by',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}