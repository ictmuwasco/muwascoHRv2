<?php

declare(strict_types=1);

namespace App\Services\Attendance;

/**
 * Prevents multiple employees from clocking in from the same device inside a
 * configurable rolling window (24 hours by default).
 *
 * WHY A SERVER-SIDE LOCK
 *   Client-side checks are advisory only - anyone can bypass the browser. The
 *   authoritative decision therefore happens inside AttendanceClockService's
 *   clock-in transaction, immediately before the row is inserted.
 *
 * PRIVACY: THE FINGERPRINT IS NOT STORED RAW
 *   The client sends an opaque random ID it generates once and keeps in
 *   localStorage. It is NEVER persisted. We store only
 *   hash_hmac('sha256', raw, APP_KEY): a keyed digest, so a database dump
 *   cannot be reversed into device identifiers, and two installs with
 *   different APP_KEYs cannot correlate the same device. The raw value never
 *   leaves the request.
 *
 * WHY A WINDOW INSTEAD OF A PERMANENT BINDING
 *   Kiosk/shared devices must still work across shifts. The lock is measured
 *   from the owner's most recent clock-in and expires automatically, so no
 *   manual unlock step is ever needed.
 *
 * CONCURRENCY
 *   assertDeviceAvailable() must be called INSIDE the caller's transaction,
 *   which is what AttendanceClockService::clockIn() already opens. The SELECT
 *   ... FOR UPDATE serialises competing clock-ins on the same device so two
 *   simultaneous requests cannot both pass the check.
 */
class AttendanceDeviceLockService
{
    /** Default rolling window in hours. */
    public const DEFAULT_WINDOW_HOURS = 24;

    /**
     * Hours a device stays locked to its owner. 0 disables the lock entirely,
     * which is the emergency escape hatch if the rule misfires.
     */
    public function windowHours(): int
    {
        $hours = $this->rawWindowHours();
        if ($hours === null) {
            return self::DEFAULT_WINDOW_HOURS;
        }

        // Guard against a typo'd or hostile value: negative windows would
        // lock every device forever, absurd values would disable the control.
        if ($hours < 0) {
            return 0;
        }
        if ($hours > 24 * 30) {
            return 24 * 30;
        }

        return $hours;
    }

    /**
     * Read ATTENDANCE_DEVICE_LOCK_HOURS without the `?:` trap.
     *
     * The shared env() helper ends in `?: $default`, so the string "0" is
     * falsy and would be replaced by the default. That would make
     * ATTENDANCE_DEVICE_LOCK_HOURS=0 silently fail to disable the lock - the
     * opposite of what an operator disabling a misfiring control expects.
     * Reading the sources directly keeps "0" meaningful.
     *
     * @return int|null null when unset or non-numeric.
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
     * Reduce a client-supplied device ID to the privacy-safe stored form.
     *
     * Returns null for absent/blank input so callers can store NULL and skip
     * the lock entirely - an unidentifiable device cannot be locked, and we
     * must not hash empty input into one shared bucket that would lock every
     * such device to the first employee who used it.
     */
    public function fingerprint(?string $rawDeviceId): ?string
    {
        $raw = is_string($rawDeviceId) ? trim($rawDeviceId) : '';
        if ($raw === '') {
            return null;
        }

        // Bound the input before hashing. A multi-megabyte "device id" is
        // not a real client and would otherwise be hashed on every request.
        if (strlen($raw) > 255) {
            return null;
        }

        $key = (string) (env('APP_KEY', '') ?: '');

        return $key !== ''
            ? hash_hmac('sha256', $raw, $key)
            : hash('sha256', $raw);
    }

    /**
     * Authoritative check. MUST run inside the caller's transaction.
     *
     * @return array<string,mixed> Context for auditing when access is denied.
     * @throws DeviceLockedException when another employee owns the device.
     */
    public function assertDeviceAvailable(string $fingerprint, int $employeeDbId): array
    {
        $hours = $this->windowHours();
        if ($hours === 0 || $fingerprint === '') {
            return ['checked' => false];
        }

        // Window boundaries are computed in PHP, matching how clock_in
        // timestamps are generated in AttendanceClockService. This also keeps
        // the fingerprint the ONLY bound parameter, which makes the bind_param
        // type string unambiguous.
        //
        // (Binding the fingerprint as part of an "iii" list silently cast it to
        // the integer 0, so MySQL compared device_fingerprint = 0 and matched
        // unrelated rows - the lock would then have fired for the wrong people.)
        $cutoff = date('Y-m-d H:i:s', time() - ($hours * 3600));
        $unlocksAt = date('Y-m-d H:i:s', time() + ($hours * 3600));

        // FOR UPDATE takes a row lock so two simultaneous clock-ins from the
        // same device serialise here instead of both reading "no owner" and
        // both inserting. Under REPEATABLE READ a matching-index miss also gap
        // locks the range, which is what closes the insert race.
        $row = \db()->fetchOne(
            "SELECT a.employee_id AS owner_id, a.clock_in AS locked_since,
                    e.first_name AS owner_first_name, e.last_name AS owner_last_name
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
            return ['checked' => true, 'owner' => null];
        }

        // Same employee: their own device, no conflict.
        if ((int) $row['owner_id'] === $employeeDbId) {
            return ['checked' => true, 'owner' => (int) $row['owner_id']];
        }

        $ownerName = trim(
            (string) ($row['owner_first_name'] ?? '') . ' ' . (string) ($row['owner_last_name'] ?? '')
        );
        if ($ownerName === '') {
            $ownerName = 'another employee';
        }

        throw new DeviceLockedException(
            sprintf(
                'This device is already registered to %s. It becomes available again after the lock expires (%sh window). '
                    . 'Please use your own device to clock in.',
                $ownerName,
                $hours
            ),
            [
                'code'         => 'DEVICE_LOCKED',
                'window_hours' => $hours,
                'unlocks_at'   => $unlocksAt,
            ]
        );
    }
}
