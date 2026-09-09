<?php

declare(strict_types=1);

namespace App\Services\Attendance;

/**
 * The device GPS fix is outside the selected office's geofence radius.
 * Maps to HTTP 403 with the measured distance and allowed radius so the
 * employee can see how far off they are (contract: code=OUTSIDE_RADIUS).
 */
class OutsideGeofenceException extends AttendanceException
{
    /**
     * @return array{distance:float|null, allowed_radius:float}
     */
    public function radiusContext(): array
    {
        return [
            'distance' => $this->context['distance'] ?? null,
            'allowed_radius' => (float) ($this->context['allowed_radius'] ?? 0),
        ];
    }
}
