<?php

declare(strict_types=1);

namespace App\Services\Attendance;

/**
 * The clock-in/clock-out request shape or location data is unusable
 * (missing office, missing coordinates, invalid GPS, accuracy cap exceeded).
 * Maps to HTTP 400.
 */
class InvalidClockRequestException extends AttendanceException
{
}
