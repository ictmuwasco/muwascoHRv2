<?php

declare(strict_types=1);

namespace App\Services\Notification\Sms;

/**
 * Phone number normalisation (server-side only).
 *
 * Employees' official numbers come from the `employees.phone` column
 * - never from client input. Handles common Kenyan formats and any
 * other international format, producing E.164:
 *
 *   0712345678   -> +254712345678
 *   0110123456   -> +254110123456  (Kenyan fixed-data prefix range)
 *   254712345678 -> +254712345678
 *   +254712345678-> +254712345678
 *   +441234567890-> +441234567890
 */
final class PhoneNormalizer
{
    public static function defaultCountryCode(): string
    {
        return preg_replace('/\D/', '', (string) env('PHONE_DEFAULT_COUNTRY_CODE', '254')) ?: '254';
    }

    /**
     * Normalise to E.164 or return null when missing/invalid.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/[\s\-().]/', '', trim($raw));
        if ($digits === '' || $digits === null) {
            return null;
        }

        // 00 international prefix -> +
        if (strpos($digits, '00') === 0) {
            $digits = '+' . substr($digits, 2);
        }

        $cc = self::defaultCountryCode();

        if ($digits[0] === '+') {
            $e164 = '+' . preg_replace('/\D/', '', substr($digits, 1));
        } elseif (strpos($digits, $cc) === 0 && strlen($digits) >= strlen($cc)) {
            // Already has country code without plus (e.g. 2547...)
            $e164 = '+' . preg_replace('/\D/', '', $digits);
        } elseif ($digits[0] === '0') {
            // Local trunk format (07.. / 01..)
            $e164 = '+' . $cc . preg_replace('/\D/', '', substr($digits, 1));
        } else {
            // Bare subscriber number (7xxxxxxxx / 1xxxxxxxx for KE)
            $e164 = '+' . $cc . preg_replace('/\D/', '', $digits);
        }

        return self::isValidE164($e164) ? $e164 : null;
    }

    /**
     * Basic E.164 shape check: + then 8-15 digits. When the number is
     * in the default country (KE) also sanity-check mobile prefixes:
     * exactly 9 national digits beginning with 7 or 1.
     */
    public static function isValidE164(string $e164): bool
    {
        if (!preg_match('/^\+(\d{8,15})$/', $e164, $m)) {
            return false;
        }
        if (substr($e164, 0, 4) === '+' . self::defaultCountryCode()) {
            $national = substr($m[1], strlen(self::defaultCountryCode()));
            return strlen($national) === 9
                && ($national[0] === '7' || $national[0] === '1');
        }
        return true;
    }

    /** Masked display form safe for UI: +2547•••••678 */
    public static function mask(?string $e164): ?string
    {
        if ($e164 === null || strlen($e164) < 7) {
            return $e164 === null ? null : $e164;
        }
        return substr($e164, 0, 6) . str_repeat('•', max(0, strlen($e164) - 9)) . substr($e164, -3);
    }
}
