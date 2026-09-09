<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Auth;
use App\Helpers\Session;

/**
 * Authentication Middleware - the SINGLE active authentication gate for the API.
 *
 * Phase 1 consolidation:
 *   - process() is invoked once from api.php for EVERY API request, before
 *     routing. Controllers keep their fine-grained permission checks
 *     (requirePermission()); this gate guarantees no request reaches a
 *     controller unauthenticated unless the route is explicitly allow-listed.
 *   - Authentication is session-first with JWT fallback (Auth::check()).
 *   - After authentication the account status is revalidated against the
 *     database (is_active). A valid-but-stale token or session for a disabled,
 *     suspended or deleted user is rejected immediately - the system never
 *     trusts a previously issued token indefinitely.
 *
 * Public (unauthenticated) routes - keep this list minimal:
 *   - POST /api/auth/login           credential exchange
 *   - POST /api/system/client-errors pre-login browser error collector
 *   - GET  /api/consent/status       must answer pre-session gracefully
 */
class AuthenticationMiddleware extends BaseMiddleware
{
    private const PUBLIC_ROUTES = [
        'POST /auth/login',
        'POST /system/client-errors',
        'GET /consent/status',
    ];

    /** Per-request cache of account-status lookups (user id => active?). */
    private static array $accountStatusCache = [];

    private Auth $auth;
    private Session $session;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->session = Session::getInstance();
    }

    /**
     * Pipeline entry (handle pattern) - kept for middleware-pipeline callers.
     */
    public function handle(callable $next): mixed
    {
        self::process();

        return $next();
    }

    /**
     * Static entry point used by api.php (runs before routing).
     */
    public static function process(): void
    {
        if (in_array(self::currentRoute(), self::PUBLIC_ROUTES, true)) {
            return;
        }

        if (self::isAuthenticated()) {
            return;
        }

        self::denyUnauthenticated();
    }

    /**
     * Current request as "METHOD /path" with the /hrdemo and /api prefixes
     * stripped (mirrors the ApiRouter normalization in api.php).
     */
    public static function currentRoute(): string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = preg_replace('#^/hrdemo#', '', $path) ?? $path;
        $path = preg_replace('#^/api#', '', $path) ?? $path;
        $path = '/' . trim((string) $path, '/');

        return $method . ' ' . $path;
    }

    /**
     * Authenticate the request and revalidate account status.
     */
    public static function isAuthenticated(): bool
    {
        $auth = Auth::getInstance();

        if (!$auth->check()) {
            return false;
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);

        if ($userId <= 0) {
            return false;
        }

        return self::isAccountActive($userId);
    }

    /**
     * DB-backed account-status validation. Disabled users lose access
     * immediately, even when their token/session has not expired yet.
     */
    private static function isAccountActive(int $userId): bool
    {
        if (array_key_exists($userId, self::$accountStatusCache)) {
            return self::$accountStatusCache[$userId];
        }

        try {
            $row = \db()->fetchOne('SELECT is_active FROM users WHERE id = ?', 'i', [$userId]);
        } catch (\Throwable $e) {
            \logger()->error('Account status check failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            self::$accountStatusCache[$userId] = false;
            return false;
        }

        $active = is_array($row) && (int) ($row['is_active'] ?? 0) === 1;
        self::$accountStatusCache[$userId] = $active;

        return $active;
    }

    /**
     * Uniform 401 for API requests (with CORS headers so the SPA can read it).
     */
    private static function denyUnauthenticated(): void
    {
        // CORS is handled centrally by SecurityMiddleware::applyCorsHeaders()
        // (called in api.php before routing). Security headers are also set
        // there, but X-Content-Type-Options is repeated here for safety since
        // this method may be called outside the middleware chain.
        header('X-Content-Type-Options: nosniff');

        \App\Helpers\ApiResponse::error(
            'Authentication required.',
            'UNAUTHENTICATED',
            [],
            401
        );
    }
}