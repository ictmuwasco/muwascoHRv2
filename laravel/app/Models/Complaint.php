<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Complaint extends Model
{
    protected $fillable = [
        'employee_id', 'subject', 'description', 'category',
        'status', 'submitted_by', 'resolution_notes',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}