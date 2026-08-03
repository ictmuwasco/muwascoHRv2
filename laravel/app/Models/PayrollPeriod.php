<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollPeriod extends Model
{
    protected $fillable = [
        'name', 'start_date', 'end_date', 'payment_date', 'status',
    ];

    public function records()
    {
        return $this->hasMany(PayrollRecord::class);
    }
}