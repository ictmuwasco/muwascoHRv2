<?php

declare(strict_types=1);

/**
 * Cache configuration.
 *
 * There is no APCu/Redis/Memcached extension on the default XAMPP runtime
 * (verified: `opcache:no`, no apcu), so the shipping driver is a small,
 * dependency-free file cache under backend/storage/cache. That is enough to
 * remove repeated work per request AND - together with the HTTP validators in
 * BaseController::successCached() - to let browsers answer repeat dashboard
 * loads with a 304 and no body at all.
 *
 * Every value is overridable from .env so a deployment can tune without a code
 * change, and the whole layer is failure-isolated: a disabled or broken cache
 * must never break a request (see App\Helpers\Cache).
 */
return [

    // Master switch. When false, Cache::remember() always runs the callback.
    'enabled' => (bool) env('CACHE_ENABLED', true),

    // Backing store. Currently only 'file' ships; APCu/Redis can be added as
    // additional drivers without touching call sites.
    'driver' => env('CACHE_DRIVER', 'file'),

    // Absolute path of the file cache root. Lives under storage/ next to the
    // rate-limit buckets so it is already writable and already excluded from
    // version control.
    'path' => env('CACHE_PATH', STORAGE_PATH . '/cache/data'),

    // Keys are namespaced so several environments/deployments can share one
    // storage directory without colliding. Bump to invalidate everything.
    'prefix' => env('CACHE_PREFIX', 'hrdemo'),

    // Default TTL (seconds) for entries stored via remember() without an
    // explicit TTL. Dashboard aggregates are deliberately short-lived: they are
    // "as of now" widgets, so a few seconds of staleness is invisible.
    'default_ttl' => (int) env('CACHE_TTL', 60),

    // Hard cap on the size of a single cached entry (bytes). Nothing larger is
    // written, which keeps a runaway payload from filling the disk.
    'max_entry_bytes' => (int) env('CACHE_MAX_ENTRY_BYTES', 262144),

    // Opportunistic garbage collection: on roughly 1-in-N writes, expired
    // files are swept so the directory cannot grow without bound (there is no
    // cron dependency for this).
    'gc_divisor' => (int) env('CACHE_GC_DIVISOR', 100),
];