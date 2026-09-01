<?php

declare(strict_types=1);

namespace App\Services\Attendance;

/**
 * The selected office does not exist, or its coordinates are not configured
 * well enough to perform geofence validation. Maps to HTTP 400.
 */
class InvalidOfficeException extends AttendanceException
{
}
