<?php

declare(strict_types=1);

namespace App\Services\Leave;

/**
 * A roster planned month outside the July→June financial-year calendar.
 * Maps to HTTP 422 with code INVALID_ROSTER_MONTH.
 */
class InvalidRosterMonthException extends LeaveException
{
}
