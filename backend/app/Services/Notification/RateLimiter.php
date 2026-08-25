<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Helpers\Logger;

/**
 * File-based rate limiter.
 *
 * SecurityMiddleware::checkRateLimit() is session-based and cannot
 * persist because bootstrap.php closes the session for API requests
 * (see its own docblock). This limiter uses atomic file counters in
 * storage/cache/rate-limits/ keyed by action+IP+window so it works
 * across requests without new database tables or Redis.
 */
final class RateLimiter
{
    private static ?RateLimiter $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Consume one attempt; false when over the limit.
     */
    public function hit(string $action, int $maxAttempts, int $windowSeconds): bool
    {
        $key     = $action . '_' . substr(md5($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 16);
        $now     = time();
        $file    = $this->filePath($key);

        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }

        $fh = fopen($file, 'c+');
        if ($fh === false) {
            return true; // Fail open rather than break the app on FS issues.
        }

        $allowed = true;
        try {
            flock($fh, LOCK_EX);
            $raw   = stream_get_contents($fh);
            $state = $raw !== false && $raw !== '' ? json_decode($raw, true) : null;

            if (!is_array($state) || !isset($state['window_start'], $state['count'])
                || $now - (int) $state['window_start'] > $windowSeconds) {
                $state = ['window_start' => $now, 'count' => 0];
            }

            if ((int) $state['count'] >= $maxAttempts) {
                $allowed = false;
                \logger()->warning('Rate limit exceeded', [
                    'action' => $action,
                    'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
                    'count'  => $state['count'],
                ]);
            } else {
                $state['count'] = (int) $state['count'] + 1;
            }

            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($state));
            fflush($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }

        return $allowed;
    }

    private function filePath(string $key): string
    {
        return STORAGE_PATH . '/cache/rate-limits/' . preg_replace('/[^a-z0-9_]/', '', strtolower($key)) . '.json';
    }

    private function __clone() {}
}
