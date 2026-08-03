<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveHistory extends Model
{
    protected $fillable = [
        'leave_application_id',
        'action',
        'performed_by',
        'comments',
        'performed_at',
    ];

    protected $casts = [
        'performed_at' => 'datetime',
    ];

    public function leaveApplication(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}