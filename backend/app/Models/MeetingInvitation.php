<?php

declare(strict_types=1);

namespace App\Models;

class MeetingInvitation extends BaseModel
{
    protected static string $table = 'meeting_invitations';
    protected static string $primaryKey = 'id';
    protected static array $fillable = [
        'meeting_id',
        'employee_id',
        'invited_by',
        'invited_at',
        'invitation_type',
        'response_status',
        'responded_at',
        'attendance_status',
        'attendance_marked_at',
        'attendance_marked_by',
        'notes',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class, 'meeting_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function attendanceMarker()
    {
        return $this->belongsTo(User::class, 'attendance_marked_by');
    }
}