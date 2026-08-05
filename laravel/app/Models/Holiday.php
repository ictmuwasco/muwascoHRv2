<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $table = 'holidays';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'date',
        'description',
        'is_recurring',
        'created_at',
    ];

    protected $casts = [
        'date' => 'date',
        'is_recurring' => 'boolean',
    ];
}
