<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantees every API response carries an X-Request-ID header.
 *
 * The React SPA adopts this id for error reporting and the audit/error
 * tracking layers correlate on it (legacy api.php behaviour). An incoming
 * id is honoured when it is a sane token, otherwise a UUIDv4 is generated.
 */
class EnsureRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $requestId = preg_match('/^[A-Za-z0-9\-_]{8,64}$/', $incoming) === 1
            ? $incoming
            : (string) \Illuminate\Support\Str::uuid();

        $request->attributes->set('request_id', $requestId);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
