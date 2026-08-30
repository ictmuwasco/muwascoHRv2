Prevent deleting cycles that are in use (already there) and add better guardrails / messages
<?php

declare(strict_types=1);

namespace App\Services\ErrorTracking;

/**
 * ErrorFingerprint
 *
 * Deterministic grouping key generator. Two occurrences of the same underlying
 * problem MUST map to the same fingerprint so that thousands of occurrences
 * collapse into one Error Group.
 *
 * Canonical parts:
 *   - HR module (e.g. "attendance")
 *   - short exception class (e.g. "mysqli_sqlexception" -> "database")
 *   - normalized message (numbers/ids/uuids/quoted strings stripped, slugified)
 *
 * The stored `fingerprint_hash` is a SHA-256 of the canonical parts PLUS the
 * originating file:line, guaranteeing stability while separating distinct
 * throw-sites; `fingerprint` stays human-readable and searchable.
 */
final class ErrorFingerprint
{
    private function __construct() {}

    /**
     * Build the fingerprint pair for an exception occurrence.
     *
     * @param string      $module     HR module slug (attendance, leave, ...)
     * @param string|null $exceptionClass
     * @param string|null $message    Raw exception message.
     * @param string|null $file       Originating file (server errors).
     * @return array{fingerprint:string, hash:string}
     */
    public static function make(
        string $module,
        ?string $exceptionClass = null,
        ?string $message = null,
        ?string $file = null
    ): array {
        $exceptionSlug = self::exceptionSlug((string) $exceptionClass);
        $messageSlug   = self::normalizeMessage($message);

        $readable = trim(implode('.', array_filter([
            self::slugify($module),
            $exceptionSlug,
            $messageSlug !== '' ? $messageSlug : 'unspecified',
        ])), '.');

        $canonical = implode('|', array_filter([
            strtolower($module),
            strtolower((string) $exceptionClass),
            self::stripDynamicParts((string) $message),
            strtolower((string) $file),
        ]));

        return [
            'fingerprint' => substr($readable, 0, 190),
            'hash'        => hash('sha256', $canonical),
        ];
    }

    /** Map exception class to a stable, readable bucket name. */
    public static function exceptionSlug(string $exceptionClass): string
    {
        $short = strtolower(substr(strrchr('\\' . ltrim($exceptionClass, '\\'), '\\'), 1));
        $short = str_replace(['exception', 'error'], '', $short);
        $short = trim($short, '_-');

        if ($short === '') {
            // mysqli_sql_exception -> database ; PDOException -> database
            $full = strtolower($exceptionClass);
            return match (true) {
                str_contains($full, 'mysqli'), str_contains($full, 'pdo') => 'database',
                str_contains($full, 'jwt'), str_contains($full, 'auth')   => 'authentication',
                default                                                   => 'runtime',
            };
        }

        return self::slugify($short);
    }

    /** Slugified message with dynamic content removed (ids, uuids, numbers). */
    public static function normalizeMessage(?string $message): string
    {
        return self::slugify(self::stripDynamicParts((string) $message));
    }

    /** Remove volatile fragments so equivalent messages normalize identically. */
    public static function stripDynamicParts(string $message): string
    {
        $replacements = [
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i' => '<uuid>',
            '/\b\d+(?:\.\d+)?\b/'                                             => '#',
            '/(["\'])[^"\']*\1/'                                              => '$1$1',  // quoted strings -> ''
            '/\s+/'                                                           => ' ',
        ];
        $message = preg_replace(array_keys($replacements), array_values($replacements), $message) ?? '';
        return trim($message);
    }

    public static function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        return trim($value, '_');
    }

    private function __clone(): void {}
}
