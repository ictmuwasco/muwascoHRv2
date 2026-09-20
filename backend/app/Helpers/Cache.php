<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Cache - dependency-free, failure-isolated caching helper.
 *
 * Why this exists
 * ---------------
 * The dashboard fans out into several endpoints (stats, charts/*, hr-insights,
 * notifications, hr-policies/current) that each recompute the same org-wide
 * aggregates. Every recomputation is a fresh round of COUNT() queries and a
 * fresh JSON payload, and under concurrent load those requests queue behind
 * each other (measured: a trivial endpoint costs ~40 ms alone but 408-2,779 ms
 * when 8 run in parallel). Removing the repeat work is the cheapest way to
 * shrink both the per-request cost and that contention.
 *
 * There is no APCu/Redis/Memcached on the target XAMPP runtime, so the store is
 * a plain file cache. It is intentionally tiny and defensive:
 *
 *   - TWO layers. An in-process memo (L1) guarantees a single request never
 *     computes the same key twice, even if two code paths ask for it. The file
 *     store (L2) survives across requests.
 *   - Never throws. If the cache directory is missing, unwritable, or holds a
 *     corrupt file, every method degrades to "miss" and the caller's callback
 *     runs as normal. Caching must never break a request.
 *   - Atomic writes (temp file + rename) so a concurrent reader can never see a
 *     half-written entry.
 *   - Expiry is stored INSIDE the payload, so a stale file is detected even if
 *     the filesystem mtime is unreliable.
 *   - Opportunistic GC on ~1-in-N writes keeps the directory bounded without
 *     depending on a cron job.
 *
 * Usage:
 *   $stats = Cache::remember('dashboard.stats', fn () => $this->computeStats(), 30);
 *   Cache::forget('dashboard.stats');
 *
 * Keys are namespaced by config('cache.prefix') and may include a scope array
 * so per-user / per-permission variants never collide.
 */
final class Cache
{
    /** @var array<string, mixed> per-request memo (L1) */
    private static array $memo = [];

    /** @var array<string, mixed>|false|null lazily resolved config, false when disabled */
    private static array|false|null $config = null;

    /**
     * Read a value, returning $default on miss or expiry.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $memoKey = self::memoKey($key);

        if (array_key_exists($memoKey, self::$memo)) {
            return self::$memo[$memoKey];
        }

        $config = self::config();
        if ($config === false) {
            return $default;
        }

        $decoded = self::readEntry(self::pathFor($config, $memoKey));
        if ($decoded === null) {
            return $default;
        }

        self::$memo[$memoKey] = $decoded['value'];

        return $decoded['value'];
    }

    /**
     * Store a value. Returns false when the cache is disabled or unwritable.
     *
     * @param int|null $ttl Seconds; null uses config('cache.default_ttl'). 0 means "no expiry".
     */
    public static function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $config = self::config();
        if ($config === false) {
            return false;
        }

        $memoKey = self::memoKey($key);

        // Always populate L1 so repeat lookups within this request are free,
        // even if the disk write below fails.
        self::$memo[$memoKey] = $value;

        $ttl     = $ttl ?? (int) $config['default_ttl'];
        $payload = json_encode([
            'expires' => $ttl > 0 ? time() + $ttl : 0,
            'value'   => $value,
        ]);

        if ($payload === false || strlen($payload) > (int) $config['max_entry_bytes']) {
            return false;
        }

        $path = self::pathFor($config, $memoKey);
        self::ensureDirectory(dirname($path));

        // Atomic write: a concurrent reader either sees the old entry or the
        // complete new one, never a partial file.
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            self::deleteFile($tmp);
            return false;
        }

        self::maybeGc($config);

        return true;
    }

    /**
     * Return the cached value, computing and storing it via $factory on miss.
     *
     * The factory only runs when the entry is absent or expired, which is the
     * whole point: N dashboard calls do the expensive work once per TTL window
     * instead of N times.
     */
    public static function remember(string $key, callable $factory, ?int $ttl = null): mixed
    {
        $memoKey = self::memoKey($key);

        if (array_key_exists($memoKey, self::$memo)) {
            return self::$memo[$memoKey];
        }

        $config = self::config();
        if ($config === false) {
            return $factory();
        }

        $decoded = self::readEntry(self::pathFor($config, $memoKey));
        if ($decoded !== null) {
            self::$memo[$memoKey] = $decoded['value'];
            return $decoded['value'];
        }

        $value = $factory();
        self::put($key, $value, $ttl);

        return $value;
    }

    /**
     * Build a deterministic key that includes a scope (e.g. the acting user, so
     * permission-scoped payloads can never leak between accounts).
     *
     * @param array<string, scalar|null> $scope
     */
    public static function scopedKey(string $key, array $scope = []): string
    {
        if ($scope === []) {
            return $key;
        }

        ksort($scope);

        return $key . '.' . substr(hash('sha256', (string) json_encode($scope)), 0, 16);
    }

    /**
     * Check for the presence of a live entry (populates L1 as a side effect).
     */
    public static function has(string $key): bool
    {
        $sentinel = new \stdClass();

        return self::get($key, $sentinel) !== $sentinel;
    }

    /**
     * Remove a single entry from both layers.
     */
    public static function forget(string $key): bool
    {
        $memoKey = self::memoKey($key);
        unset(self::$memo[$memoKey]);

        $config = self::config();
        if ($config === false) {
            return false;
        }

        return self::deleteFile(self::pathFor($config, $memoKey));
    }

    /**
     * Remove every entry written by this deployment prefix. Used by maintenance
     * endpoints/cron, never on the request path.
     */
    public static function flush(): int
    {
        self::$memo = [];

        $config = self::config();
        if ($config === false) {
            return 0;
        }

        $root = (string) $config['path'];
        if (!is_dir($root)) {
            return 0;
        }

        $removed = 0;
        foreach (self::cacheFiles($root, (string) $config['prefix']) as $file) {
            if (self::deleteFile($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Delete expired (or corrupt) entries. Safe to call from cron; put() also
     * invokes it probabilistically via gc_divisor.
     */
    public static function gc(): int
    {
        $config = self::config();
        if ($config === false) {
            return 0;
        }

        $root = (string) $config['path'];
        if (!is_dir($root)) {
            return 0;
        }

        $removed = 0;
        foreach (self::cacheFiles($root, (string) $config['prefix']) as $file) {
            $raw = self::readFile($file);
            if ($raw === null) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || self::isExpired($decoded)) {
                if (self::deleteFile($file)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }
// ------------------------------------------------------------------
    // Internals - every filesystem call is failure-isolated
    // ------------------------------------------------------------------

    /**
     * Resolved config, or false when caching is disabled/misconfigured.
     *
     * @return array<string, mixed>|false
     */
    private static function config(): array|false
    {
        if (self::$config !== null) {
            return self::$config;
        }

        try {
            $config = \config('cache', []);
        } catch (\Throwable) {
            return self::$config = false;
        }

        if (!is_array($config) || !($config['enabled'] ?? true)) {
            return self::$config = false;
        }

        // Only the file driver ships today; anything else disables the layer
        // rather than guessing at semantics.
        if (($config['driver'] ?? 'file') !== 'file') {
            return self::$config = false;
        }

        if (!is_string($config['path'] ?? null) || $config['path'] === '') {
            return self::$config = false;
        }

        // Normalise the numeric/string knobs once so hot paths never cast.
        $config['default_ttl']     = (int) ($config['default_ttl'] ?? 60);
        $config['max_entry_bytes'] = (int) ($config['max_entry_bytes'] ?? 262144);
        $config['gc_divisor']      = max(1, (int) ($config['gc_divisor'] ?? 100));
        $config['prefix']          = (string) ($config['prefix'] ?? 'cache');

        return self::$config = $config;
    }

    private static function memoKey(string $key): string
    {
        // Resolve the config FIRST: the prefix must be identical for reads and
        // writes even on a cold process, otherwise get() and put() would hash
        // different logical keys and every entry would be a permanent miss.
        $config = self::config();
        $prefix = is_array($config) ? (string) $config['prefix'] : 'cache';

        return $prefix . ':' . $key;
    }

    /**
     * Read and validate an entry. Returns null on miss, expiry or corruption -
     * callers treat all three identically.
     *
     * @return array{value: mixed}|null
     */
    private static function readEntry(string $path): ?array
    {
        $raw = self::readFile($path);
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_key_exists('value', $decoded)) {
            // Corrupt entry - drop it and report a miss.
            self::deleteFile($path);
            return null;
        }

        if (self::isExpired($decoded)) {
            return null;
        }

        return ['value' => $decoded['value']];
    }

    /** Expiry lives inside the payload; 0 means "never expires". */
    private static function isExpired(array $decoded): bool
    {
        $expires = (int) ($decoded['expires'] ?? 0);

        return $expires !== 0 && $expires < time();
    }

    /**
     * Map a logical key to a sharded file path. The 2-hex-char shard keeps any
     * single directory small, and the prefix is part of the filename so flush()
     * and gc() can identify this deployment's entries.
     */
    private static function pathFor(array $config, string $memoKey): string
    {
        $hash = hash('sha256', $memoKey);

        return rtrim((string) $config['path'], '/\\')
            . DIRECTORY_SEPARATOR . substr($hash, 0, 2)
            . DIRECTORY_SEPARATOR . $config['prefix'] . '-' . substr($hash, 2) . '.cache';
    }

    /** @return string[] absolute paths of this prefix's cache files */
    private static function cacheFiles(string $root, string $prefix): array
    {
        $pattern = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . '*'
            . DIRECTORY_SEPARATOR . $prefix . '-*.cache';

        return glob($pattern) ?: [];
    }

    private static function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    private static function readFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        return is_string($raw) ? $raw : null;
    }

    private static function deleteFile(string $path): bool
    {
        return is_file($path) && @unlink($path);
    }

    private static function maybeGc(array $config): void
    {
        try {
            if (random_int(1, (int) $config['gc_divisor']) === 1) {
                self::gc();
            }
        } catch (\Throwable) {
            // GC is best-effort only.
        }
    }

    private function __construct()
    {
    }
}