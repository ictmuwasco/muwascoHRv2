<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Department extends Model
{
    protected $fillable = [
        'name',
        'description',
        'head_of_department_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_of_department_id');
    }
}