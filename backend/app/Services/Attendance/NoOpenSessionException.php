<?php

declare(strict_types=1);

namespace App\Services\Attendance;

/**
 * No open clock-in session exists for today, so a clock-out cannot be
 * recorded. Maps to HTTP 400 (contract: code=NOT_CLOCKED_IN).
 */
class NoOpenSessionException extends AttendanceException
{
}
