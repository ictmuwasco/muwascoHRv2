<?php

declare(strict_types=1);

namespace App\Services\Attendance;

/**
 * Clock-in was attempted from a device that is currently locked to a
 * different employee, inside the configured rolling window.
 *
 * POLICY (time-windowed, 24h by default): a device stays locked to the
 * employee who last clocked in from it for ATTENDANCE_DEVICE_LOCK_HOURS
 * hours. This keeps shared kiosk devices working between shifts while
 * preventing two people from clocking in on one device within a shift.
 *
 * Maps to HTTP 409 (contract: code=DEVICE_LOCKED). The context carries the
 * owning employee's display name and the release time so the UI can explain
 * when the device frees up. It deliberately never exposes the device
 * fingerprint or the owning employee's full record.
 */
class DeviceLockedException extends AttendanceException
{
}
