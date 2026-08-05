<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialYear extends Model
{
    protected $table = 'financial_years';

    protected $fillable = [
        'name',
        'year_name',
        'start_date',
        'end_date',
        'total_days',
        'is_active',
        'is_current',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'is_current' => 'boolean',
    ];

    public function getNameAttribute(): ?string
    {
        return $this->attributes['name'] ?? $this->attributes['year_name'] ?? null;
    }

    public function setNameAttribute($value): void
    {
        if (array_key_exists('name', $this->attributes)) {
            $this->attributes['name'] = $value;
        } else {
            $this->attributes['year_name'] = $value;
        }
    }
}
