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
 *   - enforceStateChangeOrigin()  - CSRF origin/Referer validation (Phase 7)
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
        self::enforceStateChangeOrigin();
        self::enforceSessionTimeout();
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
     * Apply CORS headers from the centralized config (backend/config/cors.php).
     *
     * Replaces the inline origin lists that were duplicated in api.php,
     * BaseController::json() and AuthenticationMiddleware. Called once per
     * request from api.php (and safe to call again for preflight).
     */
    public static function applyCorsHeaders(): void
    {
        $origin   = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed  = (array) \config('cors.allowed_origins', []);

        if ($origin !== '' && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            if ((bool) \config('cors.allow_credentials', true)) {
                header('Access-Control-Allow-Credentials: true');
            }
        }

        header('Access-Control-Allow-Methods: ' . \config('cors.allowed_methods', 'GET, POST, PUT, DELETE, OPTIONS'));
        header('Access-Control-Allow-Headers: ' . \config('cors.allowed_headers', 'Content-Type, Authorization'));
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
                        \App\Helpers\ApiResponse::error(
                'CSRF token mismatch.',
                'CSRF_TOKEN_MISMATCH',
                [],
                419
            );
        }
    }

    /**
     * CSRF protection — Origin/Referer validation for state-changing requests.
     *
     * The SPA authenticates with an httpOnly access-token cookie. A malicious
     * website can make a victim's browser send a cross-site POST/PUT/DELETE
     * with those cookies attached (classic CSRF). SameSite=Lax cookies block
     * most of those, but defense in depth requires verifying that
     * state-changing requests genuinely originate from an approved origin:
     *
     *   - If the browser sent an Origin header, it must be in the CORS
     *     allowlist (backend/config/cors.php) or be the API's own origin.
     *   - Otherwise the Referer origin (if present) must pass the same check.
     *   - Requests carrying neither header are non-browser clients (curl,
     *     cron, mobile apps). Browsers ALWAYS attach Origin or Referer to
     *     cross-site state-changing requests, so this does not weaken the
     *     guard; and non-browser requests cannot be CSRFed because a third
     *     party cannot force the victim's cookies onto them.
     *
     * Enabled by default; disable with CSRF_ORIGIN_ENFORCE=false for exotic
     * deployments (see backend/docs/security/API_SECURITY.md).
     *
     * This intentionally reuses the CORS allowlist: the origins the SPA may
     * call from are exactly the origins allowed to make credentialed
     * state-changing calls.
     */
    public static function enforceStateChangeOrigin(): void
    {
        if (self::passesStateChangeOriginCheck()) {
            return;
        }

        self::logSecurityEvent('CSRF origin check failed', 'warning', [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'uri'    => $_SERVER['REQUEST_URI'] ?? '',
            'origin' => self::requestOriginCandidate() ?? '',
        ]);

        \App\Helpers\ApiResponse::error(
            'Request origin is not allowed.',
            'CSRF_ORIGIN_MISMATCH',
            [],
            403
        );
    }

    /**
     * Testable core of enforceStateChangeOrigin(): true when the request
     * either is safe (GET/HEAD/OPTIONS), has enforcement disabled, carries
     * no Origin/Referer (non-browser client), or presents an allowed origin.
     */
    public static function passesStateChangeOriginCheck(): bool
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        $enforce = strtolower(trim((string) \env('CSRF_ORIGIN_ENFORCE', 'true')));
        if (in_array($enforce, ['0', 'false', 'off', 'no', ''], true)) {
            return true;
        }

        $origin = self::requestOriginCandidate();
        if ($origin === null) {
            return true; // Non-browser client — see enforceStateChangeOrigin().
        }

        return self::isAllowedRequestOrigin($origin);
    }

    /**
     * The origin candidate for this request: the Origin header, else the
     * origin portion of the Referer header. Returns null when the browser
     * sent neither.
     */
    private static function requestOriginCandidate(): ?string
    {
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin !== '') {
            return $origin;
        }

        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '') {
            $parts = parse_url($referer);
            if (is_array($parts) && !empty($parts['host'])) {
                $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                return $scheme . '://' . strtolower((string) $parts['host']) . $port;
            }
        }

        return null;
    }

    /**
     * True when the given origin is on the CORS allowlist or is the API's
     * own origin (scheme://host[:port] of the request being handled).
     */
    private static function isAllowedRequestOrigin(string $origin): bool
    {
        $allowed = (array) \config('cors.allowed_origins', []);
        if (in_array($origin, $allowed, true)) {
            return true;
        }

        $self = self::selfOrigin();
        if ($self === null) {
            return false;
        }

        $originHost = self::originHostPort($origin);

        return $originHost !== null && $originHost === self::originHostPort($self);
    }

    /**
     * Origin of the request as the server sees it (Host header + scheme),
     * falling back to APP_URL when Host is absent (CLI/testing contexts).
     */
    private static function selfOrigin(): ?string
    {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '') {
            $scheme = self::isHttps() ? 'https' : 'http';
            return $scheme . '://' . strtolower($host);
        }

        $parts = parse_url((string) \env('APP_URL', ''));
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        if (!empty($parts['port'])) {
            $host .= ':' . $parts['port'];
        }

        return strtolower((string) ($parts['scheme'] ?? 'http')) . '://' . $host;
    }

    /**
     * Normalize "scheme://host[:port]" to "host:port" with default ports
     * filled in, so http/https variants of the same host compare equal.
     *
     * Scheme equality is deliberately NOT required: behind reverse proxies
     * the server-detected scheme can differ from the browser's. The
     * host:port pair is the security boundary — a cross-site attacker page
     * can never present the API's host in its Origin header.
     */
    private static function originHostPort(string $origin): ?string
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $port = $parts['port'] ?? (strcasecmp($scheme, 'https') === 0 ? 443 : 80);

        return $host . ':' . $port;
    }

    /**
     * Filesystem root for rate-limit counters (Phase 1).
     *
     * Counters MUST persist across requests. The previous implementation used
     * $_SESSION, but bootstrap.php closes the session early for API requests,
     * which made brute-force protection a no-op: cookie-less attackers simply
     * rotated session ids per request.
     */
    public static function rateLimitStoragePath(): string
    {
        return STORAGE_PATH . '/cache/rate-limits';
    }

    /**
     * Path for one rate-limit bucket.
     */
    public static function rateLimitPath(string $key): string
    {
        return self::rateLimitStoragePath() . '/' . $key . '.json';
    }

    /**
     * Bucket key: action + client IP + optional account identifier.
     * The identifier is hashed so raw emails/ids never appear on disk.
     */
    public static function rateLimitKey(string $action, ?string $identifier): string
    {
        return hash('sha256', $action . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . ($identifier ?? ''));
    }

    /**
     * Check (and record) one attempt against a rate limit.
     *
     * File-backed and atomic (flock), keyed by action + IP + optional account
     * identifier so both cookie-less attackers and credential stuffing against
     * a single mailbox are throttled.
     *
     * Storage failures fail OPEN (request allowed) with an error log: a disk
     * problem must never lock every legitimate user out of authentication.
     */
    public static function checkRateLimit(
        string $action,
        int $maxAttempts = 5,
        int $windowSeconds = 900,
        ?string $identifier = null
    ): bool {
        $dir = self::rateLimitStoragePath();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $path = self::rateLimitPath(self::rateLimitKey($action, $identifier));
        $now = time();

        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            \logger()->error('Rate limit store unavailable - failing open', ['action' => $action]);
            return true;
        }

        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            \logger()->error('Rate limit lock failed - failing open', ['action' => $action]);
            return true;
        }

        $raw = stream_get_contents($fh);
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || !isset($data['count'], $data['first']) || ($now - (int) $data['first']) > $windowSeconds) {
            $data = ['count' => 0, 'first' => $now];
        }

        $data['count'] = (int) $data['count'] + 1;
        $data['first'] = (int) $data['first'];

        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data) ?: '{}');
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);

        if ((int) $data['count'] > $maxAttempts) {
            \logger()->warning('Rate limit exceeded', [
                'action' => $action,
                'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
                'count'  => $data['count'],
            ]);
            return false;
        }

        if (random_int(1, 100) === 1) {
            self::gcRateLimits($now);
        }

        return true;
    }

    /**
     * Opportunistic cleanup of rate-limit buckets older than one day.
     */
    private static function gcRateLimits(int $now): void
    {
        $files = glob(self::rateLimitStoragePath() . '/*.json') ?: [];
        foreach ($files as $file) {
            if (is_file($file) && ($now - (int) filemtime($file)) > 86400) {
                @unlink($file);
            }
        }
    }

    /**
     * Protect a sensitive action (login, change-password) from brute force.
     *
     * Exits with HTTP 429 + JSON when the rate limit is exhausted.
     * Call at the top of the action handler:
     *   \App\Middleware\SecurityMiddleware::protectAgainstBruteForce('login', 5, 900, $email);
     *
     * The optional identifier (email / user id) additionally throttles
     * credential stuffing against a single account from rotating IPs. It is
     * hashed before logging - raw identifiers never reach the log.
     */
    public static function protectAgainstBruteForce(
        string $action,
        int $maxAttempts = 5,
        int $windowSeconds = 900,
        ?string $identifier = null
    ): void {
        if (!self::checkRateLimit($action, $maxAttempts, $windowSeconds, $identifier)) {
            self::logSecurityEvent('auth.bruteforce_blocked', 'warning', [
                'action'          => $action,
                'user_id'         => $_SESSION['user_id'] ?? null,
                'identifier_hash' => $identifier !== null ? substr(hash('sha256', $identifier), 0, 12) : null,
            ]);

            \App\Helpers\ApiResponse::error(
                'Too many attempts. Please try again later.',
                'RATE_LIMITED',
                [],
                429
            );
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
                \App\Helpers\ApiResponse::error(
                    'Session expired. Please sign in again.',
                    'SESSION_EXPIRED',
                    [],
                    401
                );
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
            \App\Helpers\ApiResponse::error(
                'Request body too large.',
                'REQUEST_TOO_LARGE',
                [],
                400
            );
        }

        $data = json_decode($raw, true, $maxDepth);
        if (json_last_error() !== JSON_ERROR_NONE) {
            \App\Helpers\ApiResponse::error(
                'Invalid JSON body: ' . json_last_error_msg(),
                'INVALID_JSON',
                [],
                400
            );
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