<?php

declare(strict_types=1);

namespace App\Services\ErrorTracking;

/**
 * RequestIdService
 *
 * Generates / resolves a unique correlation ID for every HTTP request and
 * exposes it to any layer (controllers, services, audit, error tracker).
 *
 * Format: "req_" + 26 uppercase Crockford base32 chars (ULID-like), e.g.
 *     req_01K3X7J8Q2A9B4C6D8E0F2H4J7
 *
 * The leading time-based portion keeps ids lexicographically sortable.
 * An incoming X-Request-ID header (e.g. minted by the SPA so that frontend
 * and backend share one trace id) is adopted after strict format validation.
 */
final class RequestIdService
{
    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const HEADER    = 'HTTP_X_REQUEST_ID';

    /** @var string|null Active request id for the current PHP process. */
    private static ?string $requestId = null;

    private function __construct() {}

    /**
     * Initialize the request id. Safe to call multiple times; only the first
     * call wins. Must be called as early as possible (bootstrap.php).
     */
    public static function initialize(): string
    {
        if (self::$requestId !== null) {
            return self::$requestId;
        }

        $candidate = $_SERVER[self::HEADER] ?? '';
        if (\config('observability.trust_incoming_request_id', true) && self::isValidFormat($candidate)) {
            // Normalize our own format to uppercase; keep foreign-but-safe ids verbatim.
            self::$requestId = $candidate;
            return self::$requestId;
        }

        self::$requestId = self::generate();
        return self::$requestId;
    }

    /** Current request id, initializing on demand if bootstrap missed it. */
    public static function current(): string
    {
        return self::$requestId ?? self::initialize();
    }

    /** Header name for responses: X-Request-ID */
    public static function headerName(): string
    {
        return (string) \config('observability.request_id_header', 'X-Request-ID');
    }

    /** Emit the response header (no-op when headers are already sent). */
    public static function applyHeader(): void
    {
        if (!headers_sent()) {
            header(self::headerName() . ': ' . self::current());
        }
    }

    /** True when the value is safe to adopt as a correlation id. */
    public static function isValidFormat(string $value): bool
    {
        return (bool) preg_match('/^req_[0-9A-HJ-NP-TV-Z]{10,32}$/', strtoupper($value))
            || (bool) preg_match('/^[A-Za-z0-9_-]{8,64}$/', $value);
    }

    /** Generate a new time-sortable unique id. */
    public static function generate(): string
    {
        $ms = (int) floor(microtime(true) * 1000);

        try {
            $random = random_bytes(10);
        } catch (\Throwable) {
            $random = substr(md5(uniqid('', true), true), 0, 10);
        }

        // 48-bit big-endian timestamp + 80 bits of randomness = 16 bytes = 26 chars.
        $binary = pack('N', $ms >> 16) . pack('n', $ms & 0xFFFF) . $random;

        return 'req_' . self::base32Encode($binary);
    }

    /** Crockford base32 encode (5 bits/char -> 128 bits => exactly 26 chars). */
    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0');
            }
            $out .= self::CROCKFORD[bindec($chunk)];
        }

        return $out;
    }

    private function __clone(): void {}
}
