<?php

declare(strict_types=1);

namespace App\Database;

use App\Helpers\PerfTiming;

/**
 * Query Performance Logger
 * 
 * Monitors and logs slow queries for performance optimization.
 * Integrates with the existing PerfTiming system.
 */
class QueryLogger
{
    private static array $activeQueries = [];
    private static array $queryLog = [];
    private static float $slowQueryThreshold = 100.0;
    private static int $maxLogSize = 100;
    private static bool $enabled = true;
    private static int $totalQueries = 0;
    private static float $totalQueryTime = 0.0;

    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    public static function setSlowQueryThreshold(float $threshold): void
    {
        self::$slowQueryThreshold = $threshold;
    }

    public static function start(string $name): void
    {
        if (!self::$enabled) return;
        self::$activeQueries[$name] = ['start_time' => microtime(true)];
    }

    public static function end(string $name, ?string $query = null, array $params = []): float
    {
        if (!self::$enabled || !isset(self::$activeQueries[$name])) return 0.0;

        $duration = (microtime(true) - self::$activeQueries[$name]['start_time']) * 1000.0;
        unset(self::$activeQueries[$name]);

        self::logQuery($name, $duration, $query, $params);
        return $duration;
    }

    private static function logQuery(string $name, float $duration, ?string $query, array $params): void
    {
        self::$totalQueries++;
        self::$totalQueryTime += $duration;

        $entry = [
            'query' => $query ?? $name,
            'params' => self::sanitizeParams($params),
            'duration_ms' => round($duration, 2),
            'is_slow' => $duration >= self::$slowQueryThreshold,
            'timestamp' => microtime(true),
        ];

        self::$queryLog[] = $entry;
        if (count(self::$queryLog) > self::$maxLogSize) array_shift(self::$queryLog);

        if ($entry['is_slow']) {
            error_log(sprintf('[SLOW QUERY] %.2fms: %s', $entry['duration_ms'], substr($entry['query'], 0, 200)));
        }

        if (class_exists(PerfTiming::class)) {
            PerfTiming::accumulate('query_' . $name, $duration);
        }
    }

    private static function sanitizeParams(array $params): array
    {
        $sensitive = ['password', 'passwd', 'pwd', 'token', 'secret'];
        return array_map(fn($k, $v) => in_array(strtolower($k), $sensitive) ? '***' : $v, array_keys($params), $params);
    }

    public static function getStatistics(): array
    {
        $queriesByTable = [];
        foreach (self::$queryLog as $entry) {
            if (preg_match('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?(\w+)`?/i', $entry['query'], $m)) {
                $table = $m[1];
                $queriesByTable[$table] = ($queriesByTable[$table] ?? ['count' => 0, 'time' => 0, 'slow' => 0]);
                $queriesByTable[$table]['count']++;
                $queriesByTable[$table]['time'] += $entry['duration_ms'];
                if ($entry['is_slow']) $queriesByTable[$table]['slow']++;
            }
        }

        return [
            'total_queries' => self::$totalQueries,
            'total_time_ms' => round(self::$totalQueryTime, 2),
            'avg_time_ms' => self::$totalQueries > 0 ? round(self::$totalQueryTime / self::$totalQueries, 2) : 0,
            'slow_queries' => count(array_filter(self::$queryLog, fn($q) => $q['is_slow'])),
            'threshold_ms' => self::$slowQueryThreshold,
            'by_table' => $queriesByTable,
        ];
    }

    public static function getQueryLog(): array { return self::$queryLog; }
    public static function getSlowQueries(): array { return array_filter(self::$queryLog, fn($q) => $q['is_slow']); }
    public static function reset(): void { self::$activeQueries = []; self::$queryLog = []; self::$totalQueries = 0; self::$totalQueryTime = 0.0; }
}
