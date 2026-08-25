<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * AppTime - Organisation timezone clock.
 *
 * All notification scheduling and "today" decisions must use the
 * organisation's configured timezone (env APP_TIMEZONE, default
 * Africa/Nairobi) instead of blindly trusting the server timezone.
 * The MySQL session is already pinned to +03:00 by
 * Database::connect(); this helper keeps the PHP side consistent.
 */
final class AppTime
{
    public static function timezoneName(): string
    {
        return (string) env('APP_TIMEZONE', 'Africa/Nairobi');
    }

    public static function timezone(): \DateTimeZone
    {
        return new \DateTimeZone(self::timezoneName());
    }

    /** Current moment in the organisation timezone. */
    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::timezone());
    }

    /** Today's date (Y-m-d) in the organisation timezone. */
    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }

    /** Current local time (H:i:s) in the organisation timezone. */
    public static function nowTime(): string
    {
        return self::now()->format('H:i:s');
    }

    /** Minutes elapsed since local midnight (0-1439). */
    public static function minutesSinceMidnight(): int
    {
        return ((int) self::now()->format('G')) * 60 + (int) self::now()->format('i');
    }

    /**
     * Parse "HH:MM" or "HH:MM:SS" into minutes since midnight.
     * Falls back to 480 (08:00) for unparseable input.
     */
    public static function parseClockTime(string $time): int
    {
        $dt = \DateTimeImmutable::createFromFormat('H:i', substr($time, 0, 5));
        if ($dt === false || $dt->format('H:i') !== substr($time, 0, 5)) {
            return 8 * 60;
        }
        return ((int) $dt->format('G')) * 60 + (int) $dt->format('i');
    }
}
