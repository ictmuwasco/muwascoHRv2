<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Unified API envelope for the MUWASCO HR Laravel backend (contract v1).
 *
 * The React SPA (frontend/) is built against the legacy PHP API contract:
 *   success: { success: true,  message: string, data: mixed }
 *   failure: { success: false, message: string, data: null,
 *              errors: { code, request_id, details } }
 * plus the X-Request-ID response header (correlation with audit/error logs).
 *
 * Every API controller uses this trait so the envelope can never drift —
 * the Phase 3 audit finding C4 (ad-hoc json_encode shapes) must not be
 * reproduced in Laravel.
 */
trait ApiResponse
{
    /**
     * A successful envelope.
     */
    protected function success(mixed $data = null, string $message = 'Request completed successfully.', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    /**
     * A failure envelope with a stable machine-readable code.
     *
     * @param  array<string, mixed>|list<string>|null  $details  Field-level or contextual details.
     */
    protected function failure(
        string $message,
        int $status = 400,
        string $code = 'ERROR',
        array|null $details = null,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => array_filter([
                'code'       => $code,
                'request_id' => request()->headers->get('X-Request-Id') ?: (string) request()->attributes->get('request_id'),
                'details'    => $details,
            ], static fn (mixed $v): bool => $v !== null),
        ], $status);
    }
}
