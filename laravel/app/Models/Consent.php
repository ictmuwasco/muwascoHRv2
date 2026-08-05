<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consent extends Model
{
    protected $table = 'user_consents';

    protected $fillable = [
        'user_id',
        'full_name',
        'national_id',
        'consent_given',
        'consent_date',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'consent_given' => 'boolean',
        'consent_date' => 'datetime',
    ];
}
