<?php

declare(strict_types=1);

namespace App\Services\Attendance;

/**
 * Clock-in was attempted while the employee has an approved leave
 * application covering today (Phase 5 business rule, §10). Enabled via
 * ATTENDANCE_BLOCK_CLOCKIN_ON_LEAVE (default: enabled). Maps to HTTP 409
 * (contract: code=ON_APPROVED_LEAVE).
 */
class OnApprovedLeaveException extends AttendanceException
{
}
