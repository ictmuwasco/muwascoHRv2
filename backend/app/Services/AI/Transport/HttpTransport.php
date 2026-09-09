<?php

declare(strict_types=1);

namespace App\Services\AI\Transport;

use App\Helpers\PerfTiming;

/**
 * HttpTransport — shared cURL JSON client for AI provider calls.
 *
 * Mirrors the SMS provider's raw cURL usage (HttpSmsProvider) rather than
 * introducing a new HTTP dependency. Responsibilities:
 *   - configurable connect + total timeouts (no hanging requests),
 *   - single POST + optional GET liveness helper,
 *   - very small retry count handled by callers (providers decide), the
 *     transport itself performs ONE attempt.
 *
 * Provider API keys are attached by the caller via $headers — this transport
 * never logs request or response bodies, never logs headers, and never throws
 * on transport errors (it returns a structured result array instead).
 *
 * Deliberately NOT final: unit tests inject an in-memory fake transport by
 * subclassing this class and overriding postJson()/getJson().
 */
class HttpTransport
{
    /** @return array{ok: bool, http_code: int, body: string, error: string} */
    public function postJson(
        string $url,
        array $payload,
        array $headers = [],
        int $timeoutSeconds = 60,
        int $connectTimeout = 5
    ): array {
        $ch = curl_init($url);
        $defaultHeaders = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_USERAGENT      => 'MUWASCO-HR-AI/1.0',
            CURLOPT_HTTPHEADER     => array_values(array_unique(array_merge($defaultHeaders, $headers))),
            // Never follow provider redirects to an unexpected host.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
        ]);

        $start    = microtime(true);
        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        // Phase 2: outbound HTTP latency (metadata only).
        PerfTiming::accumulate('external_http', (microtime(true) - $start) * 1000.0);

        if ($body === false) {
            return ['ok' => false, 'http_code' => $httpCode, 'body' => '', 'error' => (string) $curlErr];
        }

        return ['ok' => true, 'http_code' => $httpCode, 'body' => (string) $body, 'error' => ''];
    }

    /**
     * Lightweight GET used by provider health checks (e.g. Ollama /api/tags).
     * Must be non-interactive and cheap.
     *
     * @return array{ok: bool, http_code: int, body: string, error: string}
     */
    public function getJson(
        string $url,
        array $headers = [],
        int $timeoutSeconds = 5,
        int $connectTimeout = 3
    ): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_USERAGENT      => 'MUWASCO-HR-AI/1.0',
            CURLOPT_HTTPHEADER     => array_values(array_unique(array_merge(['Accept: application/json'], $headers))),
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $start    = microtime(true);
        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        // Phase 2: outbound HTTP latency (metadata only).
        PerfTiming::accumulate('external_http', (microtime(true) - $start) * 1000.0);

        if ($body === false) {
            return ['ok' => false, 'http_code' => $httpCode, 'body' => '', 'error' => (string) $curlErr];
        }

        return ['ok' => true, 'http_code' => $httpCode, 'body' => (string) $body, 'error' => ''];
    }
}