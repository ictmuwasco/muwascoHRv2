<?php

declare(strict_types=1);

namespace App\Services\ErrorTracking;

/**
 * RequestSanitizer
 *
 * Centralized sensitive-data redaction for anything persisted by the error
 * tracker (request payloads, query strings, headers, client reports).
 *
 * - Recursively walks nested arrays/JSON up to a configurable depth
 * - Replaces values whose KEY matches a sensitive-field pattern with '[REDACTED]'
 *   (case/separator-insensitive substring match, mirroring AuditService)
 * - Truncates long strings and oversized collections to bound storage
 *
 * This class never throws and never touches the database.
 */
final class RequestSanitizer
{
    public const REDACTED = '[REDACTED]';

    private function __construct() {}

    /**
     * Sanitize an arbitrary structure (payload from JSON body, $_GET, headers...).
     */
    public static function sanitize(mixed $data, int $depth = 0): mixed
    {
        if ($data === null) {
            return null;
        }

        if (is_scalar($data)) {
            return is_string($data)
                ? self::clampString($data)
                : $data;
        }

        if ($depth >= self::maxDepth()) {
            return '[TRUNCATED:MAX_DEPTH]';
        }

        if (!is_array($data)) {
            // Objects / closures / resources are never stored raw.
            return '[' . ucfirst(gettype($data)) . ']';
        }

        $maxItems     = self::maxItems();
        $isSequential = array_is_list($data);
        $out          = [];
        $count        = 0;

        foreach ($data as $key => $value) {
            if ($count++ >= $maxItems) {
                if ($isSequential) {
                    $out[] = '[TRUNCATED]';
                } else {
                    $out['[TRUNCATED]'] = "[more items omitted]";
                }
                break;
            }

            $safeKey = is_int($key) ? $key : mb_substr((string) $key, 0, 100);

            if (!is_int($safeKey) && self::isSensitiveKey((string) $safeKey)) {
                $out[$safeKey] = self::REDACTED;
                continue;
            }

            $out[$safeKey] = self::sanitize($value, $depth + 1);
        }

        return $out;
    }

    /**
     * Sanitize + JSON-encode for storage. Returns null when there is nothing
     * worth storing or when encoding fails; output is byte-bounded.
     */
    public static function sanitizeToJson(mixed $data): ?string
    {
        try {
            $json = json_encode(
                self::sanitize($data),
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
        } catch (\Throwable) {
            return null;
        }


        if (!is_string($json) || $json === '' || $json === '[]' || $json === '{}') {
            return null;
        }

        return self::boundBytes($json);
    }

    /**
     * Filter raw request headers down to the debug-safe allow-list and encode.
     * Authorization / Cookie / token-bearing headers are never stored.
     */
    public static function sanitizeHeaders(array $headers): ?string
    {
        $allowed = self::allowedHeaders();
        $subset  = [];

        foreach ($headers as $name => $value) {
            // Accept both 'Content-Type' style and HTTP_CONTENT_TYPE style keys.
            $normal = strtolower(str_replace('HTTP_', '', (string) $name));
            if (in_array($normal, $allowed, true)) {
                $pretty          = ucwords(strtolower(str_replace('_', '-', $normal)), '-');
                $subset[$pretty] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
            }
        }

        return self::sanitizeToJson($subset);
    }

    /**
     * True when a key looks sensitive. Mirrors AuditService's normalization:
     * separators removed, case-insensitive substring comparison, so
     * "password_confirmation", "accessToken", "client-secret" all match.
     */
    public static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        $normalized = str_replace(['_', '-', ' ', '.'], '', $normalized);
        if ($normalized === '') {
            return false;
        }

        foreach (self::sensitiveFields() as $sensitive) {
            $s = str_replace(['_', '-', ' ', '.'], '', strtolower((string) $sensitive));
            if ($s !== '' && strpos($normalized, $s) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip credential material embedded anywhere inside free text such as
     * stack traces or exception messages before storage/display.
     *
     * @param bool $clamp When false the result keeps its length (used for
     *                    stack traces which are bounded by the caller).
     */
    public static function scrubSecretsFromText(string $text, bool $clamp = true): string
    {
        // Bearer tokens / Basic auth fragments in traces or messages.
        $scrubbed = preg_replace('/(Bearer\s+)[A-Za-z0-9\-_.~+\/]+=*/i', '$1[REDACTED]', $text) ?? '';
        // key=value / key: value secret assignments.
        $scrubbed = preg_replace(
            '/((?:password|passwd|secret|api[_-]?key|token|authorization)\s*[=:]\s*)(["\']?)[^\s"\',;]+\\2/i',
            '$1$2[REDACTED]$2',
            $scrubbed
        ) ?? '';

        return $clamp ? self::clampString($scrubbed) : $scrubbed;
    }

    /** Byte-bound any stored JSON blob to the configured maximum. */
    private static function boundBytes(string $json): string
    {
        $maxBytes = max(1024, (int) \config('observability.max_stored_json_bytes', 8192));
        if (strlen($json) > $maxBytes) {
            return substr($json, 0, $maxBytes - 14) . '...[TRUNCATED]';
        }
        return $json;
    }

    private static function clampString(string $value): string
    {
        $max = self::maxStringLength();
        if (mb_strlen($value) > $max) {
            return mb_substr($value, 0, $max) . '…';
        }
        return $value;
    }

    /** @return array<int,string> */
    private static function sensitiveFields(): array
    {
        $fields = \config('observability.sensitive_fields');
        return is_array($fields) && !empty($fields)
            ? array_map('strval', array_values($fields))
            : ['password', 'token', 'secret', 'authorization'];
    }

    /** @return array<int,string> */
    private static function allowedHeaders(): array
    {
        $headers = \config('observability.allowed_headers');
        return is_array($headers) && !empty($headers)
            ? array_map('strval', array_values($headers))
            : ['content-type', 'accept', 'origin', 'referer', 'x-request-id', 'accept-language'];
    }

    private static function maxDepth(): int
    {
        return max(1, (int) \config('observability.max_payload_depth', 4));
    }

    private static function maxItems(): int
    {
        return max(5, (int) \config('observability.max_payload_items', 60));
    }

    private static function maxStringLength(): int
    {
        return max(64, (int) \config('observability.max_string_length', 512));
    }

    private function __clone(): void {}
}
