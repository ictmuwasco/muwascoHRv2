<?php

declare(strict_types=1);

namespace App\Models;

class Meeting extends BaseModel
{
    protected static string $table = 'meetings';
    protected static string $primaryKey = 'id';
    protected static array $fillable = [
        'title',
        'description',
        'agenda',
        'meeting_date',
        'start_time',
        'end_time',
        'location',
        'status',
        'created_by',
        'attendance_token',
    ];

    public function organizer()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invitations()
    {
        return $this->hasMany(MeetingInvitation::class, 'meeting_id');
    }
}