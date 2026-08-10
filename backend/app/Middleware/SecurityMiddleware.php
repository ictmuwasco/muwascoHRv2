<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Security Middleware - Implements OWASP Top 10 security recommendations.
 *
 * Handles security headers, CSRF protection, input validation,
 * SQL injection prevention, XSS protection, rate limiting, session
 * timeout enforcement, signed JWT validation, and PII-safe logging.
 *
 * Enhancement additions (see docs/SECURITY_AUDIT.md):
 *   - run()                       - single bootstrap entry point
 *   - validateJwtOnRequest()      - signature-verified JWT validation
 *   - enforceSessionTimeout()     - idle + absolute session timeout
 *   - protectAgainstBruteForce()  - rate limiting wrapper returning 429
 *   - addCsrfGuard()              - validate CSRF on state-changing requests
 *   - validateJsonBody()          - JSON structure + depth + size limits
 *   - secureCryptoHeaders()       - strengthened CSP + COOP/CORP headers
 *   - logSecurityEvent()          - centralized, PII-safe security logging
 *   - trustedProxyHeaderCheck()   - X-Forwarded-Proto behind trusted proxies
 */
class SecurityMiddleware
{
    /**
     * Run all security hardening steps for the current request.
     *
     * Call this from api.php / index.php immediately after bootstrap:
     *   \App\Middleware\SecurityMiddleware::run();
     *
     * Optional additional guards (enable per-endpoint as needed):
     *   - SecurityMiddleware::addCsrfGuard();
     *   - SecurityMiddleware::validateJsonBody();
     *   - $_GET/$_POST sanitization via SecurityMiddleware::sanitizeInput()
     */
    public static function run(): void
    {
        self::applySecurityHeaders();
        self::trustedProxyHeaderCheck();
        self::enforceSessionTimeout();

        self::logSecurityEvent('security.bootstrap', 'debug', [
            'method'  => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'uri'     => $_SERVER['REQUEST_URI'] ?? '',
            'user_id' => $_SESSION['user_id'] ?? null,
        ]);
    }

    /**
     * Apply all security headers to the response.
     */
    public static function applySecurityHeaders(): void
    {
        // Prevent MIME-type sniffing
        header('X-Content-Type-Options: nosniff');

        // Enable XSS filter in older browsers (deprecated; kept for legacy coverage)
        header('X-XSS-Protection: 1; mode=block');

        // Control frame embedding (Clickjacking protection)
        header('X-Frame-Options: DENY');

        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Strengthened CSP + browser isolation headers (COOP/CORP)
        self::secureCryptoHeaders();

        // Permissions Policy
        header('Permissions-Policy: geolocation=(self), microphone=(), camera=()');
    }

    /**
     * Strengthen Content Security Policy and add browser-isolation headers.
     *
     * - Removes 'unsafe-inline' from script-src (mitigates XSS; inline scripts
     *   must be moved to files or use nonce/hash strategy).
     * - Adds upgrade-insecure-requests (browsers auto-upgrade http→https).
     * - Adds Cross-Origin-Opener-Policy / Cross-Origin-Resource-Policy to
     *   harden the browsing context against Spectre-class attacks.
     */
    public static function secureCryptoHeaders(): void
    {
        // Content Security Policy (no 'unsafe-inline' for scripts)
        $csp = "default-src 'self'; "
             . "script-src 'self' https://cdnjs.cloudflare.com; "
             . "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; "
             . "img-src 'self' data:; "
             . "font-src 'self' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; "
             . "connect-src 'self'; "
             . "frame-ancestors 'none'; "
             . "upgrade-insecure-requests;";
        header("Content-Security-Policy: {$csp}");

        // Browser isolation headers
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');

        // HTTP Strict Transport Security (only on HTTPS)
        $isSecure = self::isHttps();
        if ($isSecure) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
    }

    /**
     * Detect HTTPS, honoring X-Forwarded-Proto when the request comes from a
     * trusted proxy (see trustedProxyHeaderCheck()).
     */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (($_SERVER['SERVER_PORT'] ?? 80) == 443) {
            return true;
        }
        // Trusted-proxy forwarded proto (only if the proxy IP is allowed)
        if (self::isTrustedProxy()) {
            $proto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
            if ($proto === 'https') {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize request headers when behind a trusted reverse proxy.
     *
     * Reads TRUSTED_PROXIES env (comma-separated IPs). When the client IP is
     * in that list, X-Forwarded-Proto / X-Forwarded-For are honored so HSTS
     * and secure-cookie logic work behind nginx / AWS LB.
     */
    public static function trustedProxyHeaderCheck(): void
    {
        if (!self::isTrustedProxy()) {
            return;
        }

        // Normalize forwarded proto into a synthetic HTTPS indicator
        $proto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        if ($proto === 'https' && empty($_SERVER['HTTPS'])) {
            $_SERVER['HTTPS'] = 'on';
        }

        // Normalize forwarded client IP (if the trusted proxy supplies it)
        $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwardedFor !== '') {
            $ips = array_map('trim', explode(',', $forwardedFor));
            $clientIp = $ips[0] ?? '';
            if ($clientIp !== '' && filter_var($clientIp, FILTER_VALIDATE_IP)) {
                $_SERVER['REMOTE_ADDR'] = $clientIp;
            }
        }
    }

    /**
     * Check if the current client IP is a trusted reverse proxy.
     */
    private static function isTrustedProxy(): bool
    {
        $trustedProxies = \env('TRUSTED_PROXIES', '');
        if ($trustedProxies === '') {
            return false;
        }

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $proxies = array_map('trim', explode(',', $trustedProxies));

        return in_array($clientIp, $proxies, true);
    }

    /**
     * Validate and sanitize input data.
     */
    public static function sanitizeInput(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeInput($value);
            } elseif (is_string($value)) {
                // Remove null bytes
                $value = str_replace("\0", '', $value);

                // Strip HTML tags (configurable)
                $value = strip_tags($value);

                // Trim whitespace
                $value = trim($value);

                $sanitized[$key] = $value;
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Validate CSRF token from request.
     */
    public static function validateCsrfToken(): bool
    {
        $token = $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_SERVER['HTTP_X_XSRF_TOKEN']
            ?? '';

        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Generate a CSRF token if not exists.
     */
    public static function ensureCsrfToken(): void
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    /**
     * Guard all state-changing requests with a CSRF token check.
     *
     * Safe methods (GET/HEAD/OPTIONS) pass through. Any other method must
     * present a valid CSRF token or the request is rejected with HTTP 419.
     *
     * NOTE: For token-first API auth (Bearer), CSRF is less critical, but
     *       this guard is still valuable while cookie sessions are active.
     */
    public static function addCsrfGuard(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        self::ensureCsrfToken();

        if (!self::validateCsrfToken()) {
            \logger()->warning('CSRF validation failed', [
                'method' => $method,
                'uri'    => $_SERVER['REQUEST_URI'] ?? '',
                'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            http_response_code(419);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'CSRF token mismatch.']);
            exit();
        }
    }

    /**
     * Check rate limit for an action.
     *
     * NOTE: Because bootstrap.php calls session_write_close() for API
     * requests, per-session counters will not persist across requests.
     * Migrate to a DB/Redis-backed store keyed by IP + action for production.
     */
    public static function checkRateLimit(string $action, int $maxAttempts = 5, int $windowSeconds = 900): bool
    {
        $key = 'rate_limit_' . $action . '_' . ($_SERVER['REMOTE_ADDR'] ?? '');

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'count' => 1,
                'first_attempt' => time(),
            ];
            return true;
        }

        $data = $_SESSION[$key];

        // Reset if window has expired
        if (time() - $data['first_attempt'] > $windowSeconds) {
            $_SESSION[$key] = [
                'count' => 1,
                'first_attempt' => time(),
            ];
            return true;
        }

        // Check if max attempts exceeded
        if ($data['count'] >= $maxAttempts) {
            \logger()->warning('Rate limit exceeded', [
                'action' => $action,
                'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
                'count'  => $data['count'],
            ]);
            return false;
        }

        $_SESSION[$key]['count']++;
        return true;
    }

    /**
     * Protect a sensitive action (login, change-password) from brute force.
     *
     * Exits with HTTP 429 + JSON when the rate limit is exhausted.
     * Call at the top of the action handler:
     *   \App\Middleware\SecurityMiddleware::protectAgainstBruteForce('login');
     */
    public static function protectAgainstBruteForce(string $action, int $maxAttempts = 5, int $windowSeconds = 900): void
    {
        if (!self::checkRateLimit($action, $maxAttempts, $windowSeconds)) {
            self::logSecurityEvent('auth.bruteforce_blocked', 'warning', [
                'action'  => $action,
                'user_id' => $_SESSION['user_id'] ?? null,
            ]);

            http_response_code(429);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Too many attempts. Please try again later.']);
            exit();
        }
    }

    /**
     * Enforce session idle and absolute timeouts.
     *
     * - Idle timeout:  $_SESSION['last_activity'] older than $idleSeconds
     * - Absolute timeout: $_SESSION['login_time'] older than $absoluteSeconds
     *
     * On timeout: destroys the session and returns JSON 401 for API requests,
     * otherwise sets a flash and redirects to login.
     */
    public static function enforceSessionTimeout(int $idleSeconds = 1800, int $absoluteSeconds = 43200): void
    {
        // Only enforce when a session is actually established
        if (empty($_SESSION['user_id'])) {
            return;
        }

        $now = time();
        $lastActivity = (int) ($_SESSION['last_activity'] ?? $now);
        $loginTime    = (int) ($_SESSION['login_time'] ?? $now);

        $idleExpired     = ($now - $lastActivity) > $idleSeconds;
        $absoluteExpired = ($now - $loginTime) > $absoluteSeconds;

        if ($idleExpired || $absoluteExpired) {
            self::logSecurityEvent('auth.session_expired', 'info', [
                'reason'  => $idleExpired ? 'idle_timeout' : 'absolute_timeout',
                'user_id' => (int) $_SESSION['user_id'],
            ]);

            // Clear the session
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params['path'], $params['domain'],
                    $params['secure'], $params['httponly']
                );
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }

            $uri = $_SERVER['REQUEST_URI'] ?? '';
            $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
            if (str_starts_with($uri, '/api') || str_contains($accept, 'application/json')) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Session expired. Please sign in again.']);
                exit();
            }

            $_SESSION['flash_message'] = 'Your session has expired. Please sign in again.';
            $_SESSION['flash_type'] = 'info';
            header('Location: login.php');
            exit();
        }

        // Refresh last activity on active requests
        $_SESSION['last_activity'] = $now;
    }

    /**
     * Validate a JWT from the Authorization header with signature verification.
     *
     * Uses JWT::validateToken() (firebase/php-jwt, HS256) and verifies the
     * token type is 'access' and the subject is non-empty. Returns the user
     * context array, or null when no valid token is present.
     *
     * @return array{user_id:int, email:string, role:string}|null
     */
    public static function validateJwtOnRequest(): ?array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        if ($authHeader === '') {
            return null;
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return null;
        }

        $token = trim($matches[1]);
        $decoded = \App\Helpers\JWT::getInstance()->validateToken($token);

        if ($decoded === null) {
            self::logSecurityEvent('auth.jwt_invalid', 'warning', [
                'user_id' => $_SESSION['user_id'] ?? null,
            ]);
            return null;
        }

        // Must be an access token (refresh tokens must not authenticate)
        if (!isset($decoded->type) || $decoded->type !== 'access') {
            return null;
        }

        if (empty($decoded->sub)) {
            return null;
        }

        return [
            'user_id' => (int) $decoded->sub,
            'email'   => $decoded->email ?? '',
            'role'    => $decoded->role ?? '',
        ];
    }

    /**
     * Read and validate the JSON request body.
     *
     * Enforces a max size (default 2 MB), max decode depth (10), and requires
     * valid JSON. Returns the decoded array, or exits with 400 on failure.
     */
    public static function validateJsonBody(): array
    {
        $maxSize = (int) \env('MAX_JSON_BODY_SIZE', 2097152); // 2 MB
        $maxDepth = (int) \env('MAX_JSON_DEPTH', 10);

        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }

        if (strlen($raw) > $maxSize) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Request body too large.']);
            exit();
        }

        $data = json_decode($raw, true, $maxDepth);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid JSON body.', 'detail' => json_last_error_msg()]);
            exit();
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Validate file upload for security.
     */
    public static function validateFileUpload(array $file, array $allowedTypes = []): array
    {
        $errors = [];

        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed with error code: ' . $file['error'];
            return ['valid' => false, 'errors' => $errors];
        }

        // Check file size (default 10MB)
        $maxSize = (int) \env('UPLOAD_MAX_SIZE', 10485760);
        if ($file['size'] > $maxSize) {
            $errors[] = 'File size exceeds maximum allowed size of ' . ($maxSize / 1048576) . 'MB';
        }

        // Validate file extension
        if (empty($allowedTypes)) {
            $allowedTypes = explode(',', \env('ALLOWED_FILE_TYPES', 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx'));
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedTypes, true)) {
            $errors[] = 'File type "' . $extension . '" is not allowed. Allowed types: ' . implode(', ', $allowedTypes);
        }

        // Validate MIME type for images
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            $imageInfo = getimagesize($file['tmp_name']);
            if ($imageInfo === false) {
                $errors[] = 'Uploaded file is not a valid image.';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Output sanitization for safe HTML rendering.
     */
    public static function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
    }

    /**
     * Centralized, PII-safe security event logging.
     *
     * Automatically attaches IP + user-agent + current user ID (ID only, not
     * email). Redacts sensitive context keys (password, email, token, etc.)
     * so secrets never reach the log.
     */
    public static function logSecurityEvent(string $event, string $level = 'info', array $context = []): void
    {
        // Never log these keys — strip them from context
        $redacted = ['password', 'passwd', 'pwd', 'email', 'token', 'authorization', 'access_token', 'refresh_token', 'cookie'];

        foreach ($redacted as $key) {
            if (array_key_exists($key, $context)) {
                $context[$key] = '[REDACTED]';
            }
        }

        $context = array_merge([
            'event'     => $event,
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
            'user_id'   => $_SESSION['user_id'] ?? null,
        ], $context);

        switch ($level) {
            case 'error':
                \logger()->error($event, $context);
                break;
            case 'warning':
                \logger()->warning($event, $context);
                break;
            case 'debug':
                \logger()->debug($event, $context);
                break;
            default:
                \logger()->info($event, $context);
        }
    }
}