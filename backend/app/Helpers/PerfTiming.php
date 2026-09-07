<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * PerfTiming — per-request performance instrumentation (Phase 2).
 *
 * Captures wall-clock checkpoints, accumulated phase timers and query /
 * provider counters for ONE HTTP/CLI execution without touching application
 * logic and WITHOUT logging sensitive data. Everything recorded here is
 * metadata: phase names, durations, query counts, peak memory. No SQL text,
 * tokens, prompts, headers, bodies or PII are ever captured.
 *
 * The class is intentionally static, minimal and failure-isolated; it is safe
 * to call from any layer (bootstrap, middleware, controllers, the instrumented
 * mysqli wrapper, AI providers). If the observability config is unavailable it
 * simply no-ops so monitoring can never break the request path.
 */
final class PerfTiming
{
    /** @var array<string, float> checkpoint name => microtime(true) */
    private static array $marks = [];

    /** @var array<string, float> accumulator name => accumulated ms */
    private static array $accumulators = [];

    /** @var array<string, int> counter name => count */
    private static array $counters = [];

    private static int $queryCount = 0;
    private static float $queryMs = 0.0;
    private static float $maxQueryMs = 0.0;

    /** @var ?bool lazily resolved master switch */
    private static ?bool $enabled = null;

    /**
     * Record a named wall-clock checkpoint (e.g. 'gate_start', 'controller_start').
     */
    public static function mark(string $name): void
    {
        if (!self::isEnabled()) {
            return;
        }
        self::$marks[$name] = microtime(true);
    }

    /**
     * Add milliseconds to a named accumulator (e.g. 'authorization',
     * 'serialization', 'ai_provider'). Safe to call many times.
     */
    public static function accumulate(string $name, float $ms): void
    {
        if (!self::isEnabled()) {
            return;
        }
        self::$accumulators[$name] = (self::$accumulators[$name] ?? 0.0) + $ms;
    }

    /**
     * Increment a named counter (e.g. 'ai_provider_calls', 'ai_tool_calls').
     */
    public static function count(string $name, int $by = 1): void
    {
        if (!self::isEnabled()) {
            return;
        }
        self::$counters[$name] = (self::$counters[$name] ?? 0) + $by;
    }

    /**
     * Record one executed SQL query (invoked by InstrumentedMysqli /
     * InstrumentedMysqliStmt for EVERY query on the shared connection).
     */
    public static function recordQuery(float $ms): void
    {
        if (!self::isEnabled()) {
            return;
        }
        self::$queryCount++;
        self::$queryMs += $ms;
        if ($ms > self::$maxQueryMs) {
            self::$maxQueryMs = $ms;
        }
    }

    /**
     * Reset all state for a fresh execution (start of a CLI job / request).
     */
    public static function reset(): void
    {
        self::$marks = [];
        self::$accumulators = [];
        self::$counters = [];
        self::$queryCount = 0;
        self::$queryMs = 0.0;
        self::$maxQueryMs = 0.0;
        self::$enabled = null;
    }

    /**
     * Milliseconds between two checkpoints, or null when either is missing.
     */
    public static function between(string $from, string $to): ?float
    {
        if (!isset(self::$marks[$from]) || !isset(self::$marks[$to])) {
            return null;
        }
        return (self::$marks[$to] - self::$marks[$from]) * 1000.0;
    }

    /**
     * Milliseconds from a checkpoint to now, or null when it is missing.
     * Used for phases that end with exit() (controller, auth gate denial).
     */
    public static function since(string $name): ?float
    {
        if (!isset(self::$marks[$name])) {
            return null;
        }
        return (microtime(true) - self::$marks[$name]) * 1000.0;
    }
/**
     * Flat, PII-safe report of everything measured so far. Unknown phases
     * come back as null so callers can store NULL instead of 0 (which would
     * look like a legitimate fast phase in reports).
     */
    public static function report(): array
    {
        $requestStart = $_SERVER['REQUEST_TIME_FLOAT'] ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : null;
        $totalMs = $requestStart !== null
            ? (microtime(true) - $requestStart) * 1000.0
            : 0.0;

        $bootstrapMs = null;
        if (isset($GLOBALS['_perf_bootstrap_start']) && isset(self::$marks['bootstrap_end'])) {
            $bootstrapMs = (self::$marks['bootstrap_end'] - (float) $GLOBALS['_perf_bootstrap_start']) * 1000.0;
        }

        $gateMs = self::between('gate_start', 'gate_end') ?? self::since('gate_start');
        $controllerMs = self::since('controller_start');

        return [
            'duration_ms'        => (int) round($totalMs),
            'bootstrap_ms'       => $bootstrapMs !== null ? (int) round($bootstrapMs) : null,
            'gate_ms'            => $gateMs !== null ? (int) round($gateMs) : null,
            'route_ms'           => self::between('gate_end', 'controller_start'),
            'controller_ms'      => $controllerMs !== null ? (int) round($controllerMs) : null,
            'authorization_ms'   => (int) round(self::$accumulators['authorization'] ?? 0.0),
            'serialization_ms'   => (int) round(self::$accumulators['serialization'] ?? 0.0),
            'external_http_ms'   => (int) round(self::$accumulators['external_http'] ?? 0.0),
            'ai_provider_ms'     => (int) round(self::$accumulators['ai_provider'] ?? 0.0),
            'ai_provider_calls'  => self::$counters['ai_provider_calls'] ?? 0,
            'ai_tool_calls'      => self::$counters['ai_tool_calls'] ?? 0,
            'ai_history_messages'=> self::$counters['ai_history_messages'] ?? 0,
            'query_count'        => self::$queryCount,
            'query_ms'           => (int) round(self::$queryMs),
            'max_query_ms'       => (int) round(self::$maxQueryMs),
            'peak_memory_kb'     => (int) round(memory_get_peak_usage(true) / 1024),
        ];
    }

    /**
     * Master switch from config. Failure-isolated: any config error keeps
     * instrumentation ON with safe counter-only output.
     */
    private static function isEnabled(): bool
    {
        if (self::$enabled === null) {
            try {
                self::$enabled = (bool) \config('observability.performance.instrumented', true);
            } catch (\Throwable $e) {
                self::$enabled = true;
            }
        }
        return self::$enabled;
    }

    private function __clone(): void
    {
    }
}