<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Services\ErrorTracking\RequestIdService;
use App\Services\ErrorTracking\ErrorTrackerService;

/**
 * Central, standardized JSON response formatter.
 *
 * Single source of truth for the API envelope contract:
 *   success: { success: true,  message: string, data: mixed }
 *   failure: { success: false, message: string,
 *              error: { code, request_id, reference, details? } }
 *
 * The X-Request-ID response header is set automatically so the frontend can
 * correlate requests with server-side logs.
 *
 * All controllers and middleware MUST emit responses through this class (or
 * BaseController wrappers) so the envelope shape stays consistent.
 *
 * Note: controller-level errors (validation / auth / access-denied / not-found)
 * are *expected* control-flow and do NOT get persisted by the error tracker.
 * Only the central exception handler (bootstrap.php) captures real exceptions
 * and populates `error.reference` (error_uuid). When a controller needs to
 * surface a tracked reference, it should pass one explicitly.
 */
class ApiResponse
{
    /**
     * Echo a success envelope.
     *
     * @param mixed  $data    Payload placed under the `data` key.
     * @param string $message Human-readable status message.
     * @param int    $status  HTTP status code (default 200).
     */
    public static function success($data = null, string $message = 'Operation successful', int $status = 200): void
    {
        self::clearOutputBuffers();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        self::sendRequestIdHeader();

        echo json_encode([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Echo a failure envelope.
     *
     * @param string $message    Safe, user-facing error message.
     * @param string $errorCode  Application error code (e.g. 'VALIDATION_ERROR').
     * @param array  $details    Optional extra context (validation fields, etc.).
     * @param int    $status     HTTP status code (default 400).
     * @param string $reference  Optional error tracking reference (error_uuid) —
     *                            only set when the error has been persisted.
     */
    public static function error(string $message, string $errorCode = 'ERROR', array $details = [], int $status = 400, string $reference = null): void
    {
        self::clearOutputBuffers();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        self::sendRequestIdHeader();

        echo json_encode([
            'success' => false,
            'message' => $message,
            'error'   => [
                'code'       => $errorCode,
                'request_id' => self::currentRequestId(),
                'reference'  => $reference,
                'details'    => $details,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send a raw, non-enveloped JSON body (used by CSV / file endpoints that
     * still need the X-Request-ID header for traceability).
     *
     * @param mixed $data
     */
    public static function raw($data): void
    {
        self::sendRequestIdHeader();
        echo is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send a Content-Type / body and exit (used by CSV and file endpoints that
     * must keep the X-Request-ID header for traceability).
     *
     * @param string $body
     * @param string $contentType
     */
    public static function send(string $body, string $contentType): void
    {
        header('Content-Type: ' . $contentType);
        self::sendRequestIdHeader();
        echo $body;
        exit;
    }

    /**
     * The current request correlation id, or null if unavailable.
     */
    protected static function currentRequestId(): ?string
    {
        try {
            return RequestIdService::current();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Guarantee a pure-JSON body: strip any stray output (third-party notices,
     * debug echoes) that accumulated in the output buffers before emitting.
     */
    protected static function clearOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    /**
     * Emit the X-Request-ID response header if a request id is available and
     * headers have not yet been sent.
     */
    protected static function sendRequestIdHeader(): void
    {
        RequestIdService::applyHeader();
    }
}

