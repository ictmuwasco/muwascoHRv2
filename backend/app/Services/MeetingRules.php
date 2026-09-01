<?php

declare(strict_types=1);

namespace App\Services;

/**
 * MeetingRules — the authoritative meeting lifecycle rules (Phase 5 §14).
 *
 *   scheduled → ongoing → completed
 *        └──────────────→ cancelled
 *
 * RSVP windows: employees may accept/decline only while a meeting is
 * 'scheduled' or 'ongoing'. Responses on completed/cancelled meetings are
 * closed forever (declined/pending attendees can never retroactively gain
 * published-minutes access via a late RSVP).
 */
final class MeetingRules
{
    /** meetings.status ENUM (migration 016). */
    public const STATUSES = ['scheduled', 'ongoing', 'completed', 'cancelled'];

    /** Statuses during which invitation responses are accepted. */
    public const RSVP_OPEN_STATUSES = ['scheduled', 'ongoing'];

    /** meeting_invitations.attendance_status ENUM (migration 017). */
    public const ATTENDANCE_STATUSES = ['present', 'absent', 'excused', 'not_marked'];

    /** Invitation response ENUM (migration 017). */
    public const RESPONSE_STATUSES = ['pending', 'accepted', 'declined', 'tentative'];

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    public static function canRsvp(string $meetingStatus): bool
    {
        return in_array($meetingStatus, self::RSVP_OPEN_STATUSES, true);
    }

    public static function isValidAttendanceStatus(string $status): bool
    {
        return in_array($status, self::ATTENDANCE_STATUSES, true);
    }

    public static function isValidResponseStatus(string $status): bool
    {
        return in_array($status, self::RESPONSE_STATUSES, true);
    }
}
