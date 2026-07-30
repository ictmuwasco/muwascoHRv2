<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Request — shared request normalization helpers.
 *
 * Centralizes the URI-stripping logic that was previously duplicated
 * in App\Router::getUri() and App\Core\Application::route(). Both
 * routers call Request::normalizeUri() to derive the path the routing
 * tables are keyed by.
 */
final class Request
{
    /**
     * Normalize the current request URI to the path used for routing.
     *
     *   - Strips the query string
     *   - Removes a trailing slash
     *   - Strips the script's directory prefix (so `/hrdemo/api/dashboard/stats`
     *     becomes `api/dashboard/stats` when served from `/hrdemo/api.php`)
     *   - Drops the leading slash, returning `/` for the root request
     *
     * @return string Normalized path, e.g. "api/dashboard/stats" or "/"
     */
    public static function normalizeUri(): string
    {
        $rawUri     = $_SERVER['REQUEST_URI'] ?? '/';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

        $uri = parse_url($rawUri, PHP_URL_PATH) ?? '/';
        $uri = rtrim($uri, '/');

        $scriptDir = rtrim(dirname($scriptName), '/');
        if ($scriptDir !== '/' && $scriptDir !== '' && str_starts_with($uri, $scriptDir)) {
            $uri = substr($uri, strlen($scriptDir));
        }

        $uri = ltrim($uri, '/');

        return $uri === '' ? '/' : $uri;
    }
}
