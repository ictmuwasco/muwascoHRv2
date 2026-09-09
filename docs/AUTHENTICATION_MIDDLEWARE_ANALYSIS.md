# Authentication Middleware Analysis

## Why We Have Three Middlewares

The application contains three authentication/authorization-related middlewares, each serving a distinct purpose:

### 1. `AuthenticationMiddleware.php` (Modern OOP Style)
**Purpose:** Gatekeeper for authenticated routes in the new API architecture.

**Characteristics:**
- Extends `BaseMiddleware` using the pipeline pattern (`handle(callable $next)`)
- Used in the Laravel-style middleware stack
- Checks `Auth::getInstance()->check()` for session/JWT authentication
- Returns JSON for API requests, redirects for web requests
- Updates last activity timestamp

**When Used:**
- New API routes via `api.php`
- Modern controller actions that use dependency injection

### 2. `AuthMiddleware.php` (Legacy Static Style)
**Purpose:** Application-wide authentication and role/permission enforcement.

**Characteristics:**
- Static methods (`requireAuth()`, `requireRole()`, `requirePermission()`)
- Procedural style, called directly in page scripts
- Handles JWT auto-login by converting JWT to session
- Integrates with RBAC system for fine-grained permissions
- Supports both web and API request detection

**When Used:**
- Legacy PHP pages (`leave_management.php`, etc.)
- Pages that need quick role/permission checks without full middleware pipeline
- Situations where middleware chaining is not available

### 3. `AuthorizationMiddleware.php` (Modern OOP Style)
**Purpose:** Permission-based authorization after authentication.

**Characteristics:**
- Extends `BaseMiddleware`
- Reads `permission_resource` and `permission_action` from request
- Delegates to `AuthorizationService::hasPermission()`
- Returns 403 Forbidden if user lacks required permission

**When Used:**
- Routes requiring specific resource permissions
- Fine-grained access control beyond simple authentication

## Security Posture Analysis

### What We Have (Strengths)

1. **Defense in Depth**
   - Authentication layer (`AuthenticationMiddleware` / `AuthMiddleware`)
   - Authorization layer (`AuthorizationMiddleware`)
   - Security headers (`SecurityMiddleware`)

2. **Multiple Authentication Methods**
   - Session-based authentication
   - JWT token authentication with auto-login fallback
   - Secure session cookie configuration (HttpOnly, SameSite, Secure)

3. **Role-Based Access Control (RBAC)**
   - `role_permissions` table for permission mapping
   - `user_page_permissions` for page-level access
   - `AuthorizationService` for runtime permission checks

4. **Security Headers Implemented**
   - `X-Content-Type-Options: nosniff`
   - `X-XSS-Protection: 1; mode=block`
   - `X-Frame-Options: DENY`
   - Content-Security-Policy
   - Referrer-Policy
   - HSTS (on HTTPS)
   - Permissions-Policy

5. **CSRF Protection**
   - Token generation via `SecurityMiddleware::ensureCsrfToken()`
   - Validation via `SecurityMiddleware::validateCsrfToken()`

6. **Input Sanitization**
   - `SecurityMiddleware::sanitizeInput()` strips HTML tags and null bytes

7. **Rate Limiting**
   - `SecurityMiddleware::checkRateLimit()` using session storage

### What We Don't Have (Gaps)

1. **No Centralized Auth Middleware Registry**
   - Middlewares are inconsistently applied across routes
   - No single source of truth for which routes are protected

2. **No Request Logging/Monitoring**
   - Failed authentication attempts not logged
   - No audit trail for permission denials

3. **No Brute-Force Protection**
   - Rate limiting exists but not tied to authentication endpoints
   - No account lockout after failed login attempts

4. **No Token Revocation**
   - Refresh tokens table exists but no revocation mechanism
   - JWT tokens cannot be invalidated before expiry

5. **No CORS Policy Enforcement**
   - CORS headers are set but not validated against allowed origins

6. **No Input Validation Middleware**
   - Validation exists in controllers but not as reusable middleware

7. **No Security Event Monitoring**
   - No real-time alerting for suspicious patterns
   - No integration with security information systems

## SecurityMiddleware.php Enhancement Plan

### Current Implementation
`backend/app/Middleware/SecurityMiddleware.php` currently provides:
- Security headers
- Input sanitization
- CSRF protection
- Rate limiting
- File upload validation
- Output escaping helper (`e()`)

### Proposed Enhancements

#### 1. Add Request Logging
```php
public static function logSecurityEvent(string $event, array $context = []): void
{
    $log = [
        'timestamp' => date('c'),
        'event' => $event,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_id' => $_SESSION['user_id'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        'context' => $context,
    ];
    error_log('[SECURITY] ' . json_encode($log));
}
```

#### 2. Add Authentication Attempt Tracking
```php
public static function checkAuthAttemptLimit(string $identifier, int $maxAttempts = 5, int $windowSeconds = 300): bool
{
    $key = 'auth_attempts_' . md5($identifier);
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
    }
    
    $data = $_SESSION[$key];
    
    // Reset if window expired
    if (time() - $data['first_attempt'] > $windowSeconds) {
        $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
        return true;
    }
    
    if ($data['count'] >= $maxAttempts) {
        self::logSecurityEvent('auth_attempt_limit_exceeded', ['identifier' => $identifier]);
        return false;
    }
    
    $_SESSION[$key]['count']++;
    return true;
}
```

#### 3. Add Suspicious Activity Detection
```php
public static function detectSuspiciousActivity(): ?array
{
    $flags = [];
    
    // Check for SQL injection attempts
    $input = file_get_contents('php://input');
    if (preg_match('/(union|select|insert|delete|update|drop|script|javascript)/i', $input)) {
        $flags[] = 'potential_injection';
    }
    
    // Check for path traversal
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('/(\.\.\/|\.\.\\)/', $uri)) {
        $flags[] = 'path_traversal';
    }
    
    // Check for unusual request patterns
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (empty($userAgent) || preg_match('/(bot|crawler|scanner)/i', $userAgent)) {
        $flags[] = 'suspicious_user_agent';
    }
    
    if (!empty($flags)) {
        self::logSecurityEvent('suspicious_activity', ['flags' => $flags]);
        return $flags;
    }
    
    return null;
}
```

#### 4. Add Content Security Policy Nonce Support
```php
private static function generateNonce(): string
{
    if (!isset($_SESSION['csp_nonce'])) {
        $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
    }
    return $_SESSION['csp_nonce'];
}

public static function applySecurityHeaders(): void
{
    $nonce = self::generateNonce();
    $csp = "default-src 'self'; "
         . "script-src 'self' 'nonce-{$nonce}' https://cdnjs.cloudflare.com; "
         . "style-src 'self' 'nonce-{$nonce}' https://cdnjs.cloudflare.com; "
         . "img-src 'self' data:; "
         . "font-src 'self' https://cdnjs.cloudflare.com; "
         . "connect-src 'self'; "
         . "frame-ancestors 'none';";
    header("Content-Security-Policy: {$csp}");
}
```

#### 5. Add Session Security Enhancements
```php
public static function enforceSessionSecurity(): void
{
    // Regenerate session ID periodically
    if (!isset($_SESSION['last_regeneration'])) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 300) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
    
    // Bind session to IP address (optional, may break mobile users)
    if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
        session_destroy();
        exit('Session expired due to IP change');
    }
    
    // Bind session to User-Agent
    if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
        session_destroy();
        exit('Session expired due to security violation');
    }
}
```

#### 6. Add Security Headers Validation
```php
public static function validateSecurityHeaders(): void
{
    // Ensure sensitive endpoints have proper cache control
    $sensitivePatterns = ['/api/auth', '/api/employees', '/api/leave'];
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    
    foreach ($sensitivePatterns as $pattern) {
        if (str_starts_with($path, $pattern)) {
            header('Cache-Control: no-store, no-cache, must-revalidate, proxy-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            break;
        }
    }
}
```

## Recommendations

### Immediate Actions (High Priority)

1. **Consolidate Authentication Logic**
   - Create a single `AuthMiddleware` that extends `BaseMiddleware`
   - Deprecate `AuthMiddleware.php` static methods gradually
   - Update legacy pages to use the new middleware

2. **Add Authentication Logging**
   - Log all failed authentication attempts
   - Log successful logins with IP and user agent
   - Implement alerting for suspicious patterns

3. **Implement Rate Limiting on Auth Endpoints**
   - Limit login attempts to 5 per 5 minutes per IP
   - Implement exponential backoff after failures
   - Add CAPTCHA after threshold

4. **Add JWT Token Revocation**
   - Store token identifiers in database
   - Check revocation status on each request
   - Implement token blacklist for logout

### Short-term Actions (Medium Priority)

5. **Add Security Event Monitoring**
   - Create `security_events` table for audit trail
   - Log permission denials, suspicious activity, etc.
   - Add admin dashboard for security events

6. **Implement CORS Validation**
   - Validate `Origin` header against whitelist
   - Reject requests from untrusted origins
   - Log CORS violations

7. **Add Request ID Tracking**
   - Generate unique request ID for each request
   - Include in response headers
   - Correlate logs across services

8. **Enhance CSRF Protection**
   - Rotate CSRF tokens after successful validation
   - Implement double-submit cookie pattern for APIs
   - Add CSRF token to all state-changing operations

### Long-term Actions (Low Priority)

9. **Implement Security Headers Testing**
   - Add automated tests for security headers
   - Verify CSP compliance in CI/CD pipeline
   - Test against OWASP ZAP baseline

10. **Add Web Application Firewall (WAF)**
    - Consider ModSecurity or cloud WAF
    - Implement rate limiting at infrastructure level
    - Add bot detection and mitigation

11. **Implement Security Scanning**
    - Integrate dependency vulnerability scanning
    - Add static application security testing (SAST)
    - Perform regular penetration testing

12. **Add Security Documentation**
    - Document security best practices for developers
    - Create incident response playbook
    - Establish security review process for code changes

## Implementation Priority

| Enhancement | Priority | Effort | Impact |
|-------------|----------|--------|--------|
| Auth attempt logging | High | Low | High |
| Rate limiting on auth endpoints | High | Medium | High |
| JWT revocation | High | High | Medium |
| Consolidate middleware | Medium | Medium | Medium |
| Security event monitoring | Medium | High | Medium |
| CORS validation | Medium | Low | Medium |
| Request ID tracking | Low | Low | Low |
| WAF integration | Low | High | High |

## Conclusion

The application has a solid security foundation with multiple middleware layers providing authentication and authorization. However, there are opportunities to improve:

1. **Consolidation** - Reduce complexity by unifying the three auth middlewares
2. **Observability** - Add logging and monitoring for security events
3. **Protection** - Implement rate limiting and brute-force protection
4. **Flexibility** - Add JWT revocation for better session management

The proposed enhancements to `SecurityMiddleware.php` should be implemented incrementally, starting with logging and rate limiting, followed by suspicious activity detection and nonce-based CSP.
---

## Phase 1 Final Classification (2026-08-31)

The consolidation described above has been IMPLEMENTED. Final state:

| Middleware | Classification | Detail |
|------------|----------------|--------|
| `AuthenticationMiddleware` | **ACTIVE** | Upgraded into the single global authentication gate. Wired once in `api.php` (`SecurityMiddleware::run()` + `AuthenticationMiddleware::process()`). Enforces the public-route allowlist, session/JWT authentication and DB account-status revalidation. |
| `SecurityMiddleware` | **ACTIVE** | `run()` invoked from `api.php` for headers/trusted-proxy/session-timeout; `protectAgainstBruteForce()` is now a file-backed (flock-atomic) limiter keyed by IP + account identifier. |
| `BaseMiddleware` | ACTIVE (fixed) | The broken `App\Middleware\Contracts\MiddlewareInterface` import (interface does not exist) was repaired to the real `App\Middleware\MiddlewareInterface`. |
| `AuthMiddleware` | LEGACY | Never referenced. Retained for legacy PHP pages; removal candidate once no page includes it. Do not extend. |
| `AuthorizationMiddleware` | LEGACY | Never referenced; client-supplied `permission_resource` parameters are an anti-pattern. Authorization lives in controllers via `requirePermission()`. Removal candidate. |
| Laravel scaffold (`backend/bootstrap/app.php`, `backend/routes/routes/api.php`, `jwt.auth` alias) | LEGACY | Not the running router. The active router is the root `api.php`. Documented for eventual removal. |

The authoritative request flow is documented in `docs/AUTHENTICATION.md`.