<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Json - safe JSON encode/decode helpers.
 *
 * Why this exists
 * ---------------
 * The codebase had 16 bare `json_decode()` calls, none of which used
 * JSON_THROW_ON_ERROR. Bare json_decode() returns null on malformed input and
 * sets json_last_error(); the two are easy to ignore together, so a corrupt
 * value silently became `null`, then became `[]`, then became an empty
 * permission list or an empty next-of-kin list. That failure is invisible: no
 * exception, no log line, just data quietly not being there.
 *
 * The distinction that matters is WHERE the malformed value came from:
 *
 *   - Data already in our database (TEXT columns holding JSON). This can be
 *     legacy or corrupted. It must NEVER throw: an exception here would take
 *     down an employee profile page or a delegation check because one row is
 *     bad. Log it, return a safe default, keep serving.
 *   - Data from the request body. Malformed JSON here is a genuine client
 *     error and should be reported as a 400 rather than silently reinterpreted
 *     as an empty payload.
 *
 * decodeStored() covers the first case, decodeRequest() the second.
 *
 * PHP serialization note: this project uses json_encode/json_decode
 * exclusively. serialize()/unserialize() appears nowhere in backend/app, so
 * there is no PHP object-injection surface. That is worth preserving: if a
 * future change needs to read a legacy serialized value, it must go through
 * an explicit, reviewed migration rather than an unserialize() call.
 */
final class Json
{
    /**
     * Decode JSON that originates from our own storage (a TEXT column).
     *
     * Never throws. Malformed input is logged and $default is returned, so
     * one corrupt row cannot break the page that reads it.
     *
     * @template T
     * @param  string|null $value   Raw column value.
     * @param  mixed       $default Returned for null/empty/malformed input.
     * @param  string      $context Short label used in the log line, e.g.
     *                              'employees.next_of_kin'.
     * @return mixed
     */
    public static function decodeStored(?string $value, mixed $default = null, string $context = 'json'): mixed
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            self::report($context, $e->getMessage());

            return $default;
        }
    }

    /**
     * Decode JSON that arrived in a request body.
     *
     * Throws JsonException on malformed input so the caller can answer 400.
     * An empty body is not an error - many endpoints accept form posts and
     * legitimately send nothing - so null/'' returns $default.
     *
     * @template T
     * @return mixed
     */
    public static function decodeRequest(?string $raw, mixed $default = null, string $context = 'request body'): mixed
    {
        if ($raw === null || trim($raw) === '') {
            return $default;
        }

        // JSON_THROW_ON_ERROR surfaces a JsonException with a real message
        // instead of a null return plus json_last_error().
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Decode to an array, coalescing any non-array result to [].
     *
     * Convenience for the common "expected a list/assoc-array" case.
     *
     * @return array<mixed>
     */
    public static function decodeStoredArray(?string $value, string $context = 'json'): array
    {
        $decoded = self::decodeStored($value, [], $context);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Encode, surfacing failure instead of returning false.
     *
     * json_encode() returns false on failure (invalid UTF-8, recursion) and
     * callers that ignore the return value will happily persist or emit an
     * empty string.
     *
     * @throws \JsonException when the value cannot be encoded.
     */
    public static function encode(mixed $value, string $context = 'json'): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \JsonException(json_last_error_msg());
        }

        return $json;
    }

    /**
     * Log a decode failure without letting the logger itself throw.
     *
     * Logging is best-effort on purpose: this class is called from error
     * paths (ErrorTracker, SecurityMiddleware), and a logging failure must
     * not escalate into a second fault.
     */
    private static function report(string $context, string $reason): void
    {
        try {
            \logger()->warning('Malformed JSON decoded', [
                'context' => $context,
                'reason'  => $reason,
            ]);
        } catch (\Throwable) {
            // Never let observability turn a data problem into an outage.
            error_log("[Json] malformed JSON in {$context}: {$reason}");
        }
    }
}
