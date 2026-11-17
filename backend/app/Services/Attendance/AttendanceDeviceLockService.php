<?php

declare(strict_types=1);

namespace App\Services\Attendance;

/**
 * Prevents multiple employees from clocking in from the same device inside a
 * configurable rolling window (24 hours by default).
 *
 * The device fingerprint is hashed before storage. Raw device IDs are never
 * persisted.
 *
 * assertDeviceAvailable() must run inside the caller's database transaction.
 */
class AttendanceDeviceLockService
{
    /** Default rolling window in hours. */
    public const DEFAULT_WINDOW_HOURS = 24;

    /**
     * Hours a device stays locked to its owner.
     * Setting this to 0 disables the lock.
     */
    public function windowHours(): int
    {
        $hours = $this->rawWindowHours();

        if ($hours === null) {
            return self::DEFAULT_WINDOW_HOURS;
        }

        if ($hours < 0) {
            return 0;
        }

        if ($hours > 24 * 30) {
            return 24 * 30;
        }

        return $hours;
    }

    /**
     * Read ATTENDANCE_DEVICE_LOCK_HOURS while preserving the value "0".
     *
     * @return int|null
     */
    private function rawWindowHours(): ?int
    {
        foreach ([$_ENV, $_SERVER] as $bag) {
            if (isset($bag['ATTENDANCE_DEVICE_LOCK_HOURS'])) {
                $value = $bag['ATTENDANCE_DEVICE_LOCK_HOURS'];

                return is_numeric($value) ? (int) $value : null;
            }
        }

        $fromEnv = getenv('ATTENDANCE_DEVICE_LOCK_HOURS');

        if ($fromEnv !== false) {
            return is_numeric($fromEnv) ? (int) $fromEnv : null;
        }

        return null;
    }

    public function isEnabled(): bool
    {
        return $this->windowHours() > 0;
    }

    /**
     * Convert a raw device ID into a privacy-safe fingerprint.
     *
     * @return string|null
     */
    public function fingerprint(?string $rawDeviceId): ?string
    {
        $raw = is_string($rawDeviceId) ? trim($rawDeviceId) : '';

        if ($raw === '' || strlen($raw) > 255) {
            return null;
        }

        $key = (string) (env('APP_KEY', '') ?: '');

        return $key !== ''
            ? hash_hmac('sha256', $raw, $key)
            : hash('sha256', $raw);
    }

    /**
     * Check whether another employee has recently used this device.
     *
     * MUST be called inside the caller's database transaction.
     *
     * @param string $fingerprint Hashed device identifier.
     * @param int $employeeDbId Employee attempting to clock in.
     *
     * @return array<string,mixed>
     *
     * @throws DeviceLockedException
     */
    public function assertDeviceAvailable(
        string $fingerprint,
        int $employeeDbId
    ): array {
        $hours = $this->windowHours();

        if ($hours === 0 || $fingerprint === '') {
            return ['checked' => false];
        }

        // Calculate the rolling cutoff from the current time.
        // A clock-in is considered active while it is within this window.
        $cutoff = date(
            'Y-m-d H:i:s',
            strtotime("-{$hours} hours")
        );

        // Lock the matching row while checking device ownership.
        $row = \db()->fetchOne(
            "SELECT
                a.employee_id AS owner_id,
                a.clock_in AS locked_since,
                e.first_name AS owner_first_name,
                e.last_name AS owner_last_name
             FROM attendance a
             LEFT JOIN employees e ON e.id = a.employee_id
             WHERE a.device_fingerprint = ?
               AND a.clock_in IS NOT NULL
               AND a.clock_in > ?
             ORDER BY a.clock_in DESC
             LIMIT 1
             FOR UPDATE",
            'ss',
            [$fingerprint, $cutoff]
        );

        if (!$row) {
            return [
                'checked' => true,
                'owner' => null,
            ];
        }

        // The employee who originally used the device may reuse it.
        if ((int) $row['owner_id'] === $employeeDbId) {
            return [
                'checked' => true,
                'owner' => (int) $row['owner_id'],
            ];
        }

        $ownerName = trim(
            (string) ($row['owner_first_name'] ?? '') . ' ' .
            (string) ($row['owner_last_name'] ?? '')
        );

        if ($ownerName === '') {
            $ownerName = 'another employee';
        }

        // Calculate the exact expiry time from the original clock-in.
        $unlocksAt = date(
            'Y-m-d H:i:s',
            strtotime($row['locked_since'] . " +{$hours} hours")
        );

        throw new DeviceLockedException(
            sprintf(
                'This device is already registered to %s. It becomes available again at %s. Please use your own device to clock in.',
                $ownerName,
                $unlocksAt
            ),
            [
                'code' => 'DEVICE_LOCKED',
                'window_hours' => $hours,
                'unlocks_at' => $unlocksAt,
            ]
        );
    }
}
