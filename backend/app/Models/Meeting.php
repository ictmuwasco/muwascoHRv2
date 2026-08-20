<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Meeting Model
 *
 * Represents a meeting/schedule record created by HR users.
 *
 * Table: meetings
 * Primary Key: id
 */
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
        'notification_sent_at',
    ];
    protected static array $guarded = ['id', 'created_at', 'updated_at'];
    protected static bool $timestamps = true;

    /**
     * Allowed meeting statuses with their display names.
     */
    public const STATUSES = [
        'scheduled'  => 'Scheduled',
        'ongoing'    => 'Ongoing',
        'completed'  => 'Completed',
        'cancelled'  => 'Cancelled',
    ];
}
