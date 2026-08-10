# MUWASCO HR System — Security Audit & Hardening Guide

**Audit date:** 2026-08-09  
**Scope:** Backend (`backend/`), API entry (`api.php`, `index.php`), frontend auth (React SPA), server config (`.htaccess`, `.env`)  
**Audit type:** Static code review (no penetration testing performed)

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Authentication Architecture — Why Three Middlewares?](#2-authentication-architecture--why-three-middlewares)
3. [Actual Request Flow](#3-actual-request-flow)
4. [Security Posture Matrix](#4-security-posture-matrix)
5. [Vulnerability Findings](#5-vulnerability-findings)
6. [Recommended Enhancements to `SecurityMiddleware.php`](#6-recommended-enhancements-to-securitymiddlewarephp)
7. [Priority Implementation Roadmap](#7-priority-implementation-roadmap)
8. [Appendix — File Reference](#8-appendix--file-reference)

---

## 1. Executive Summary

The application has a **solid foundation**: prepared statements everywhere (SQLi protected), strong password hashing (Argon2ID/bcrypt), session cookie hardening (HttpOnly + SameSite), a competent RBAC + hybrid authorization layer, and sensitive-file protection in `.htaccess`.

However, the audit found **4 critical, 7 high, 7 medium, and 4 low severity issues** that must be addressed before production.

The most severe issue is an **authentication bypass**: `AuthService::generateToken()` produces an *unsigned, base64-encoded JSON blob* instead of a signed JWT, and `Auth::authenticateFromToken()` trusts it without verifying a signature. Combined with the default `JWT_SECRET` in `.env` and `APP_DEBUG=true`, an attacker can forge a `super_admin` token and call any API endpoint.

A second architectural problem is that **none of the three auth middlewares are actually invoked** — the running `api.php` router performs zero middleware execution, and authentication depends on every controller remembering to call `getUserId()` inline. This is fragile and inconsistent.

---

## 2. Authentication Architecture — Why Three Middlewares?

The `backend/app/Middleware` directory contains **three authentication-related middlewares** (`AuthenticationMiddleware`, `AuthMiddleware`, `AuthorizationMiddleware`) plus `SecurityMiddleware` and the pipeline base (`BaseMiddleware`, `MiddlewareInterface`).

### 2.1 The three middlewares

| # | Middleware | Pattern | Responsibilities | Status |
|---|-----------|---------|-----------------|--------|
| 1 | `AuthenticationMiddleware` | Extends `BaseMiddleware` (pipeline `handle(callable $next)`) | Calls `Auth::check()` (session + JWT fallback), returns JSON 401 for API or redirects to login, updates `last_activity` | **Dead code** — never referenced anywhere |
| 2 | `AuthMiddleware` | Static utility class (no interface) | `requireAuth()`, `requireRole()`, `requirePermission()` using direct `$_SESSION` checks + `JWT::getAuthenticatedUser()` fallback | **Dead code** — never referenced |
| 3 | `AuthorizationMiddleware` | Extends `BaseMiddleware` (pipeline) | Reads `permission_resource` / `permission_action` from GET/POST (**client-supplied**), checks via `AuthorizationService->hasPermission()` | **Dead code** — never referenced |

### 2.2 Why do they exist?

This is **legacy layering** — multiple iterations added auth protection at different stages:

- **`AuthMiddleware` (static)** — the *oldest* pattern. Static helpers meant to be called at the top of legacy PHP pages before the React SPA split.
- **`AuthenticationMiddleware` (pipeline)** — added when the middleware-pipeline abstraction (`BaseMiddleware` + `MiddlewareInterface`) was introduced. Wraps the old logic in the `handle(callable $next)` contract.
- **`AuthorizationMiddleware` (pipeline)** — added to formalize *permission-based* authorization via `AuthorizationService` (see finding F-12 for the client-parameter risk).

None were ever wired into `api.php`, `index.php`, or `backend/bootstrap.php`. There is **no middleware dispatcher**. The Laravel-style `backend/routes/routes/api.php` references a `jwt.auth` alias but **that routes file is not the running router** — the active router is `api.php`, which executes no middleware at all.

### 2.3 The *actual* authentication path

```
api.php (router, no middleware)
  └─ Controller method (e.g., EmployeeController::indexAction)
       └─ BaseController::getUserId() / getAuthUserId()
            └─ Auth::getInstance()->check()
                 ├─ $_SESSION['user_id'] + $_SESSION['session_valid'] === true → trusted
                 └─ authenticateFromToken()
                      ├─ Reads Authorization: Bearer <token>
                      ├─ base64-decodes payload
                      ├─ Checks 'user_id' + 'exp' (NO SIGNATURE VERIFICATION)
                      └─ Restores $_SESSION from token → authenticated
```

### 2.4 Impact of this fragmentation

1. **Inconsistent protection:** A controller that forgets `getUserId()` / `requirePermission()` exposes its endpoints unauthenticated.
2. **Maintenance trap:** Fixing a vulnerability requires touching every controller rather than one middleware.
3. **Dead code:** ~180 lines of middleware mislead developers into thinking auth is handled.
4. **Client-controlled authorization:** `AuthorizationMiddleware::getRequiredPermission()` takes `permission_resource`/`permission_action` from **query/post parameters** — a client could elevate its own request. (Latent today, never wire as-is.)

### 2.5 Recommended target architecture

```
api.php (router)
  └─ SecurityMiddleware::run()                    ← headers, session timeout, JSON body validation
       └─ AuthenticationMiddleware (wired)        ← verify signed JWT + session, 401 on failure
            └─ AuthorizationMiddleware (wired)    ← server-side route→permission map (NOT client params)
                 └─ Controller
```

Concretely:
- Wire `SecurityMiddleware::run()` into `api.php` for all routes.
- Wire `AuthenticationMiddleware` for all routes except `/auth/login` (and possibly `/auth/refresh`).
- **Rewrite** `AuthorizationMiddleware::getRequiredPermission()` to derive resource/action from a **server-side route map**.
- Remove/archive `AuthMiddleware` (static) once the pipeline version is live.
- Fix `AuthService::generateToken()` to emit a **signed JWT** via `JWT::generateAccessToken()` (finding F-01).

---

## 3. Actual Request Flow

```
Client (React SPA)
   │  axios (frontend/src/utils/api.js)
   │    baseURL: http://localhost/hrdemo/api (dev) | /hrdemo/api (prod)
   │    withCredentials: true
   │    Authorization: Bearer <token from localStorage>
   ▼
.htaccess  →  RewriteRule ^(hrdemo/)?api/(.*)$ api.php  [passes Authorization header]
   ▼
api.php (active router, hand-rolled, no middleware)
   │  require backend/bootstrap.php
   │  CORS headers (hardcoded localhost origins)
   │  OPTIONS preflight short-circuit
   │  Strip /hrdemo and /api prefix → $endpoint
   │  try { match $endpoint + $requestMethod → new Controller()->action() }
   │  catch (\Throwable) → JSON 500 (debug info if APP_DEBUG=true)
   ▼
Controller (extends BaseController)
   │  getUserId() → Auth::check()
   │  Controller-specific permission checks (inconsistent)
   ▼
Service layer → Repository → Database (MySQLi prepared statements)
```

`backend/routes/routes/api.php` (Laravel-style, `jwt.auth` group) is **not executed** at runtime — it is a scaffold from an earlier/future Laravel migration.

---

## 4. Security Posture Matrix

### 4.1 Access Control & Authentication

| Control | Status | Evidence / Notes |
|---------|--------|------------------|
| Password hashing | ✅ **Strong** | `Hash.php`: Argon2ID (64MB, 4 iter) fallback bcrypt (cost 12) |
| SQL injection | ✅ **Protected** | `Database.php`: all queries via prepared statements (`prepare` + `bind_param`) |
| Session cookies HttpOnly | ✅ | `bootstrap.php`: `httponly => true` |
| Session cookies SameSite | ✅ | `bootstrap.php`: `Lax`/`None` (HTTPS) |
| Session cookies Secure flag | ⚠️ **Partial** | Only when HTTPS detected; `.env` sets `SESSION_SECURE_COOKIE=false` |
| Session fixation protection | ❌ **Missing** | No `session_regenerate_id()` on login |
| Session idle/absolute timeout | ❌ **Missing** | `last_activity` tracked but never enforced |
| JWT signature verification | ❌ **CRITICAL** | `Auth::authenticateFromToken()` decodes unsigned base64 JSON (F-01) |
| JWT secret strength | ❌ **CRITICAL** | Default `your-jwt-secret-key-change-in-production` in `.env` (F-02) |
| Centralized auth middleware | ❌ **CRITICAL** | `api.php` executes no middleware; per-controller checks (F-04) |
| Authorization (RBAC) | ✅ **Present** | `RBAC.php` + `AuthorizationService.php` (hybrid: role + user overrides, deny-over-grant) |
| Authorization on every endpoint | ⚠️ **Partial** | Relies on per-controller `requirePermission()` discipline |
| Login brute-force rate limit | ❌ **Missing** | `checkRateLimit()` exists but never invoked (F-06) |
| MFA / TOTP | ❌ **Missing** | `FEATURE_MFA=false` in `.env` |
| Account lockout | ❌ **Missing** | No lockout after N failed attempts |
| Remember-me security | ⚠️ **Broken/insecure** | Cookie secure=false, token not stored server-side, never validated (F-10) |

### 4.2 Data Protection & Transport

| Control | Status | Evidence / Notes |
|---------|--------|------------------|
| HTTP→HTTPS redirect | ❌ **Missing** | Commented out in `.htaccess` |
| HSTS | ⚠️ **Partial** | Only when `HTTPS` detected + commented out in `.htaccess` |
| Token storage (frontend) | ❌ **HIGH** | JWT in `localStorage` → XSS-exposed (F-05) |
| Sensitive files blocked | ✅ | `.env`, `.sql`, `.log`, `.md`, `.lock`, `composer.json` denied in `.htaccess` |
| Directory listing | ✅ | `Options -Indexes` |
| Error display | ✅ | `display_errors=0` globally |
| Debug info in API errors | ❌ **HIGH** | `APP_DEBUG=true` → full file/line/trace returned to clients (F-03) |
| DB credentials | ⚠️ | Defaults `root`/`root` in `.env` (dev only; must change in prod) |
| PII redaction in logs | ❌ **HIGH** | Emails, roles, password-verify status logged (F-11) |

### 4.3 XSS, CSRF & Injection

| Control | Status | Evidence / Notes |
|---------|--------|------------------|
| Output escaping helper | ✅ | `SecurityMiddleware::e()` (`htmlspecialchars`, ENT_QUOTES, UTF-8) |
| Input sanitization helper | ✅ | `SecurityMiddleware::sanitizeInput()` (strip_tags, trim, null-byte removal) |
| CSRF token generation | ✅ | `SecurityMiddleware::ensureCsrfToken()` (32-byte random) |
| CSRF token validation applied | ❌ **Missing** | `validateCsrfToken()` never called (F-07) |
| CSP header | ⚠️ **Weak** | `'unsafe-inline'` for scripts; CDNs whitelisted (F-14) |
| X-Frame-Options | ✅ | `DENY` in `.htaccess` + CSP `frame-ancestors 'none'` |
| X-Content-Type-Options | ✅ | `nosniff` |
| Referrer-Policy | ✅ | `strict-origin-when-cross-origin` |
| Permissions-Policy | ✅ | geolocation=(self), mic/camera=() |
| COOP / CORP headers | ❌ **Missing** | Not set (F-16) |
| JSON body validation | ❌ **Missing** | `getJsonBody()` accepts any JSON, no depth/size limits (F-13) |
| File upload validation | ✅ | `SecurityMiddleware::validateFileUpload()` (size, extension, image MIME) |

### 4.4 Infrastructure & Configuration

| Control | Status | Evidence / Notes |
|---------|--------|------------------|
| Environment separation | ❌ **HIGH** | `APP_ENV=development`, `APP_DEBUG=true` committed in `.env` (F-03) |
| Rate limiting (general) | ⚠️ **Exists, unused** | `checkRateLimit()` in `SecurityMiddleware` |
| CORS allowlist | ⚠️ **Dev-focused** | Hardcoded `localhost:5173/3000/localhost` origins (F-15) |
| Security logging | ⚠️ **Missing** | No `logSecurityEvent()`; PII leaks in current logs |
| Error handler | ✅ | `set_exception_handler` → logs + safe JSON |
| Composer dev dependencies in prod | ⚠️ | `phpunit`, `phpstan`, `phpcs`, `mockery` in `require-dev` |
| Gzip + cache headers | ✅ | `.htaccess` mod_deflate/mod_expires |

### 4.5 OWASP Top 10 (2021) Mapping

| OWASP Category | Status | Notes |
|----------------|--------|-------|
| A01 Broken Access Control | ❌ **High risk** | No central auth enforcement; client-controlled permission params (latent) |
| A02 Cryptographic Failures | ❌ **Critical** | Unsigned forgeable tokens; default JWT secret; no HTTPS enforcement |
| A03 Injection | ✅ Protected | Prepared statements everywhere |
| A04 Insecure Design | ⚠️ | No login rate limit, no MFA, no session timeout |
| A05 Security Misconfiguration | ❌ **High** | `APP_DEBUG=true`; RBAC dev-mode "allow all"; deprecated header |
| A06 Vulnerable Components | ⚠️ | Run `composer audit` |
| A07 Identification & Auth Failures | ❌ **Critical** | Unsigned tokens + no rate limit + no session regeneration |
| A08 Software & Data Integrity | ⚠️ | No signed releases/CI signing |
| A09 Logging & Monitoring | ⚠️ | Logs exist; no security-event log, PII leakage |
| A10 SSRF | ✅ N/A | No server-side URL fetching |

---

## 5. Vulnerability Findings

### 🔴 CRITICAL

#### F-01 — Authentication bypass via unsigned, forgeable tokens
- **File:** `backend/app/Services/AuthService.php` (lines 323–336), `backend/app/Helpers/Auth.php` (lines 60–107)
- **Issue:** `generateToken()` returns `base64_encode(json_encode($payload))` — no signature, no HMAC. `Auth::authenticateFromToken()` decodes it and trusts `user_id`, `role`, `email` without any integrity check.
- **Exploit:** Send `Authorization: Bearer <base64_encode(json_encode(['user_id'=>1,'email'=>'admin@x.com','role'=>'super_admin','exp'=>time()+999999]))>` → full admin access on any endpoint relying on `Auth::check()`.
- **Fix:** Use `JWT::generateAccessToken($user)` (HS256 via `firebase/php-jwt`) and validate with `JWT::validateToken()` → verify `type === 'access'`, issuer, exp. Remove the base64 fallback in `Auth::authenticateFromToken()`.

#### F-02 — Default JWT secret committed
- **File:** `.env` (line 17)
- **Issue:** `JWT_SECRET=your-jwt-secret-key-change-in-production` is publicly known. Even after F-01 is fixed, anyone who knows the secret can forge signed tokens.
- **Fix:** Generate a strong random secret (`openssl rand -base64 48`), keep it out of the repo, rotate immediately. Never commit real secrets.

#### F-03 — APP_ENV=development + APP_DEBUG=true in committed .env
- **File:** `.env` (lines 3–4), `api.php` (lines 405–418), `RBAC.php` (lines 134–168)
- **Issue:**
  - `api.php` returns `debug.file`, `debug.line`, `debug.trace` to any API client when `APP_DEBUG=true`.
  - `RBAC::lookup()` — when `role_permissions` table is missing or a query fails — **returns `true` (allow all)** in development. If this ships to prod with a broken table, every permission check is bypassed.
- **Fix:** `APP_ENV=production`, `APP_DEBUG=false` in prod. In `RBAC::lookup()`, default to **deny** on error regardless of env — never allow-all.

#### F-04 — No authentication middleware executed on the active router
- **File:** `api.php` (entire file)
- **Issue:** The hand-rolled router instantiates controllers directly with no auth gate. Protection is per-controller via `getUserId()`. Any controller that forgets it exposes endpoints.
- **Fix:** Wire `AuthenticationMiddleware` into `api.php` for all non-auth routes. Add a route→permission map and invoke `AuthorizationMiddleware`.

### 🟠 HIGH

#### F-05 — JWT stored in localStorage (XSS-exposed)
- **File:** `frontend/src/utils/api.js` (line 24), `frontend/src/context/AuthContext.jsx` (lines 65–66)
- **Issue:** Any XSS can read `localStorage` and exfiltrate the bearer token.
- **Fix:** Move token to an **httpOnly, Secure, SameSite** cookie set by the server. Frontend uses `withCredentials: true` already.

#### F-06 — Login brute-force / credential stuffing not rate-limited
- **File:** `backend/app/Middleware/SecurityMiddleware.php` (`checkRateLimit()` exists), `backend/app/Controllers/AuthController.php`
- **Issue:** `checkRateLimit()` is never called on `/auth/login` or `/auth/change-password`. Unlimited guesses.
- **Fix:** Call `SecurityMiddleware::protectAgainstBruteForce('login', …)` at the top of `loginAction()` and `changePasswordAction()`.

#### F-07 — CSRF protection not applied
- **File:** `backend/app/Middleware/SecurityMiddleware.php` (`validateCsrfToken()` exists, never called)
- **Issue:** For session-cookie-authenticated requests, no CSRF token is validated on state-changing requests.
- **Fix:** In `api.php` or middleware, on non-GET requests verify the CSRF token (except `/auth/login`).

#### F-08 — No session regeneration on login (session fixation)
- **File:** `backend/app/Services/AuthService.php` (login, lines 57–134)
- **Issue:** Session ID is not regenerated after successful authentication. An attacker who can set a victim's session ID can ride the session.
- **Fix:** `session_regenerate_id(true)` after successful credential validation, before storing session variables.

#### F-09 — `meAction()` / `changePasswordAction()` pass empty email to `getUserByEmail('')`
- **File:** `backend/app/Controllers/AuthController.php` (lines 120, 150)
- **Issue:** `getUserByEmail('')` returns no user → `meAction()` always 404s; `changePasswordAction()` always fails with "Current password is incorrect". Users cannot change their password.
- **Fix:** Query by `getAuthUserId()` using `findById`, or add `AuthService::getUserById()`.

#### F-10 — Remember-me is broken and insecure
- **File:** `backend/app/Services/AuthService.php` (lines 351–368)
- **Issue:** Cookie `secure=false`; the token is **never stored** in a DB table and **never validated** on return. Dead + insecure.
- **Fix:** Implement a `remember_tokens` table (token hash, user_id, expires_at, revoked_at), set cookie `secure=true, samesite=Lax`, validate on bootstrap, rotate on use.

#### F-11 — PII & sensitive info in logs
- **File:** `backend/app/Services/AuthService.php` (lines 68, 73, 79, 89), `backend/app/Helpers/AuthorizationService.php` (lines 359–380), `backend/app/Helpers/RBAC.php`
- **Issue:** Login attempts log full emails + "Password verification SUCCESS/FAILED"; authorization logs roles + redirect reasons. Data-protection concern.
- **Fix:** Log only user IDs/hashes; use `SecurityMiddleware::logSecurityEvent()` with a PII-safe schema.

### 🟡 MEDIUM

#### F-12 — Client-controlled authorization parameters (latent)
- **File:** `backend/app/Middleware/AuthorizationMiddleware.php` (`getRequiredPermission()`)
- **Issue:** If ever wired, `permission_resource`/`permission_action` come from `$_GET`/`$_POST` — the client supplies its own required permission, trivially bypassed.
- **Fix:** Derive permissions from a server-side route map (controller/action → resource/action).

#### F-13 — No JSON body validation (size/depth/content-type)
- **File:** `backend/app/Controllers/BaseController.php` (`getJsonBody()`)
- **Issue:** `json_decode` with no depth limit, no size cap, no content-type check. Malformed/deep/large payloads consume memory and may bypass input filters.
- **Fix:** Use `SecurityMiddleware::validateJsonBody()` — cap size (e.g. 2MB), depth (e.g. 10), verify `Content-Type: application/json`.

#### F-14 — Weak Content-Security-Policy
- **File:** `backend/app/Middleware/SecurityMiddleware.php` (`applySecurityHeaders()`)
- **Issue:** `script-src 'unsafe-inline'` disables most CSP XSS protection. Also allows two CDN hosts.
- **Fix:** Remove `'unsafe-inline'` from `script-src`; move inline JS to files; use nonces/hashes if needed; tighten `style-src`; add `upgrade-insecure-requests`.

#### F-15 — CORS allowlist is dev-only; credentials allowed
- **File:** `api.php` (lines 11–23), `backend/bootstrap.php` (lines 139–149)
- **Issue:** Hardcoded `localhost:5173`, `localhost:3000`, `localhost` origins with `Access-Control-Allow-Credentials: true`. Production behind a different domain will break; careless edits allow malicious origins.
- **Fix:** Make CORS origins config-driven (`env('CORS_ALLOWED_ORIGINS', …)`), never `*` with credentials.

#### F-16 — Missing COOP / CORP / COEP headers
- **File:** `.htaccess` / `SecurityMiddleware::applySecurityHeaders()`
- **Issue:** No `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy` — weakens browser isolation against Spectre-class attacks.
- **Fix:** Add `Cross-Origin-Opener-Policy: same-origin`, `Cross-Origin-Resource-Policy: same-origin`.

#### F-17 — No absolute/idle session timeout enforcement
- **File:** `backend/bootstrap.php`, `AuthenticationMiddleware`
- **Issue:** `last_activity` is set but never compared to a timeout. Sessions live until cookie expiry (120 min) regardless of activity.
- **Fix:** `SecurityMiddleware::enforceSessionTimeout()` — idle timeout (e.g. 30 min) + absolute timeout (e.g. 12 h); destroy + redirect/JSON 401.

### 🟢 LOW

#### F-18 — X-XSS-Protection deprecated
- Harmless but obsolete; can trigger false warnings. Remove or keep — no security value in modern browsers.

#### F-19 — `backend/routes/routes/api.php` dead Laravel-style route file
- Confusing; implies middleware (`jwt.auth`) that doesn't run. Archive or remove to avoid false assurance.

#### F-20 — `FEATURE_MFA` / `FEATURE_CAPTCHA` flags exist but disabled
- Provide an MFA roadmap (TOTP via `spomky-labs/otphp` or similar) and CAPTCHA (hCaptcha/reCAPTCHA) for login.

#### F-21 — `frontend/src/api/client.ts` referenced in tabs but file does not exist on disk
- Stale tab/open reference. Verify and remove or create.

---

## 6. Recommended Enhancements to `SecurityMiddleware.php`

All of the following have been **implemented in the file** alongside this audit. Each is a new static method that can be called individually or via the single bootstrap `run()`.

### 6.1 `run()` — single bootstrap entry point

```php
public static function run(): void
{
    self::applySecurityHeaders();
    self::enforceSessionTimeout();
    self::trustedProxyHeaderCheck();
    // Optionally: sanitizeInput on $_GET/$_POST, addCsrfGuard(), validateJsonBody()
}
```

Call from `api.php` (and optionally `index.php`) immediately after `require backend/bootstrap.php`:

```php
\App\Middleware\SecurityMiddleware::run();
```

**What it does:** applies headers, enforces session timeout, and normalizes trusted-proxy detection in one call. This is the *central wiring point* that fixes "security middleware never runs".

### 6.2 `validateJwtOnRequest()` — signature-verified JWT validation

```php
public static function validateJwtOnRequest(): ?array
```

- Extracts `Authorization: Bearer <token>` (handles `HTTP_AUTHORIZATION` / `REDIRECT_HTTP_AUTHORIZATION`).
- Calls `JWT::getInstance()->validateToken($token)` (signed HS256 decode via `firebase/php-jwt`).
- Verifies `type === 'access'`, non-expired, non-empty `sub`.
- Returns `['user_id' => (int)$decoded->sub, 'email' => ..., 'role' => ...]` or `null`.
- **Wiring:** Use in `AuthenticationMiddleware` instead of the insecure base64 decode. This is the fix for F-01/F-02.

### 6.3 `enforceSessionTimeout()` — idle + absolute timeout

```php
public static function enforceSessionTimeout(int $idleSeconds = 1800, int $absoluteSeconds = 43200): void
```

- Reads `$_SESSION['last_activity']` and `$_SESSION['login_time']`.
- If idle exceeded → destroy session, redirect or JSON 401.
- If absolute exceeded → destroy session, require re-login.
- **Wiring:** Call inside `run()` and after every authenticated middleware pass. Fixes F-17.

### 6.4 `protectAgainstBruteForce()` — login rate limiting wrapper

```php
public static function protectAgainstBruteForce(string $action, int $maxAttempts = 5, int $windowSeconds = 900): void
```

- Wraps the existing `checkRateLimit()`; on exhaustion sends HTTP 429 JSON and exits.
- **Wiring:** At the top of `AuthController::loginAction()` and `changePasswordAction()`:
  ```php
  \App\Middleware\SecurityMiddleware::protectAgainstBruteForce('login');
  ```
  Fixes F-06.

> ⚠️ **Caveat:** current `checkRateLimit()` keys on `$_SESSION` + `REMOTE_ADDR`. Since `bootstrap.php` calls `session_write_close()` for API requests, per-session rate counters will not persist across API requests. For production, move the counter to a DB/Redis-backed store keyed by IP + email hash.

### 6.5 `addCsrfGuard()` — validate CSRF on state-changing requests

```php
public static function addCsrfGuard(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;

    if (!self::validateCsrfToken()) {
        http_response_code(419);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'CSRF token mismatch.']);
        exit();
    }
}
```

- **Wiring:** Call in `run()` (or per route) for all state-changing requests. Fixes F-07. Exclude `/auth/login` when using token-first login.

### 6.6 `validateJsonBody()` — JSON structure + depth + size limits

```php
public static function validateJsonBody(): array
```

- Reads `php://input`, enforces max size (default 2 MB via `env('MAX_JSON_BODY_SIZE', 2097152)`), max depth (10), verifies JSON parse.
- **Wiring:** In `BaseController::getJsonBody()` or the router for JSON routes. Fixes F-13.

### 6.7 `secureCryptoHeaders()` — strengthen CSP + browser-isolation headers

```php
public static function secureCryptoHeaders(): void
```

- Upgrade `Content-Security-Policy`: remove `'unsafe-inline'` from `script-src`, add `upgrade-insecure-requests`, keep `frame-ancestors 'none'`.
- Add `Cross-Origin-Opener-Policy: same-origin` and `Cross-Origin-Resource-Policy: same-origin`.
- **Wiring:** Called by `applySecurityHeaders()`. Fixes F-14/F-16.

### 6.8 `logSecurityEvent()` — centralized, PII-safe security logging

```php
public static function logSecurityEvent(string $event, string $level = 'info', array $context = []): void
```

- Writes to `\logger()` with: event name, user ID (not email), IP, user-agent, status.
- Strips keys named `password`, `email`, `token`, `authorization` from context before logging.
- **Wiring:** Use in `validateJwtOnRequest()`, `protectAgainstBruteForce()`, `enforceSessionTimeout()`, and controllers. Fixes F-11.

### 6.9 `trustedProxyHeaderCheck()` — X-Forwarded-Proto behind proxies

```php
public static function trustedProxyHeaderCheck(): void
```

- When `TRUSTED_PROXIES` env is set (comma-separated IPs), honors `X-Forwarded-Proto` so HSTS/cookie-secure logic works behind nginx/AWS LB.
- **Wiring:** Called by `run()` for correct HSTS/secure-cookie behavior in production.

---

## 7. Priority Implementation Roadmap

### P0 — Fix now (blockers)

| # | Task | Files | Finding |
|---|------|-------|---------|
| 1 | Replace unsigned token with signed JWT | `AuthService.php`, `Auth.php`, `JWT.php` | F-01 |
| 2 | Rotate/remove default `JWT_SECRET` | `.env`, `.gitignore` | F-02 |
| 3 | `APP_DEBUG=false`, `APP_ENV=production` in prod | `.env` | F-03 |
| 4 | RBAC deny-by-default on error (remove dev allow-all) | `RBAC.php` | F-03 |
| 5 | Wire `SecurityMiddleware::run()` + `AuthenticationMiddleware` into `api.php` | `api.php`, middlewares | F-04 |
| 6 | Login rate limiting via `protectAgainstBruteForce()` | `AuthController.php` | F-06 |

### P1 — Deploy-time hardening

| # | Task | Files | Finding |
|---|------|-------|---------|
| 7 | Move JWT to httpOnly cookie | `AuthService.php`, `api.php`, `AuthContext.jsx`, `api.js` | F-05 |
| 8 | `session_regenerate_id(true)` on login | `AuthService.php` | F-08 |
| 9 | Fix `meAction`/`changePasswordAction` (use `findById`) | `AuthController.php`, `AuthService.php` | F-09 |
| 10 | Implement secure remember-me (DB-backed) | `AuthService.php`, new migration | F-10 |
| 11 | Add CSRF guard to state-changing requests | via `addCsrfGuard()` | F-07 |
| 12 | Session idle/absolute timeout via `enforceSessionTimeout()` | `run()` wiring | F-17 |
| 13 | Remove PII from logs; use `logSecurityEvent()` | `AuthService.php`, `AuthorizationService.php` | F-11 |
| 14 | Enable MFA flag + CAPTCHA roadmap | `.env`, login flow | F-20 |

### P2 — Production quality

| # | Task | Files | Finding |
|---|------|-------|---------|
| 15 | Enable HTTPS redirect + HSTS in `.htaccess` | `.htaccess` | 4.2 |
| 16 | Tighten CSP (remove `unsafe-inline`) | `SecurityMiddleware.php` | F-14 |
| 17 | Add COOP/CORP headers | `SecurityMiddleware.php` | F-16 |
| 18 | Config-driven CORS origins | `api.php`, `bootstrap.php` | F-15 |
| 19 | JSON body validation | `BaseController.php` | F-13 |
| 20 | Remove/archive dead Laravel routes + `AuthMiddleware` | `backend/routes/`, middlewares | F-19 |
| 21 | Server-side route→permission map for `AuthorizationMiddleware` | `AuthorizationMiddleware.php` | F-12 |
| 22 | Rate limit store to DB/Redis (not session) | `SecurityMiddleware.php` | 6.4 caveat |
| 23 | `composer audit` + remove dev deps in prod | `composer.json` | 4.4 |
| 24 | Run PHPStan + PHPCS as CI gates | CI config | 4.4 |

---

## 8. Appendix — File Reference

| File | Role in security |
|------|------------------|
| `api.php` | Active API router — **no middleware**, error details when debug |
| `index.php` | SPA entry point — proxies `/api` to `api.php` |
| `backend/bootstrap.php` | Session cookie params, error handling, CORS, env loading |
| `backend/app/Middleware/*` | 3 dead auth middlewares + 1 security utility |
| `backend/app/Helpers/Auth.php` | **Insecure token decode** (base64, unsigned) — F-01 |
| `backend/app/Helpers/JWT.php` | Proper signed JWT (HS256) helper — use it! |
| `backend/app/Helpers/Session.php` | Session utility singleton |
| `backend/app/Helpers/Hash.php` | Argon2ID/bcrypt hashing |
| `backend/app/Helpers/RBAC.php` | Role permissions; **dev allow-all fallback** — F-03 |
| `backend/app/Helpers/AuthorizationService.php` | Hybrid authorization (role + overrides) |
| `backend/app/Services/AuthService.php` | **Unsigned token gen**; login/logout; remember-me — F-01/F-08/F-10 |
| `backend/app/Controllers/AuthController.php` | Login/refresh/me/change-password — F-09 |
| `backend/app/Controllers/BaseController.php` | `getUserId()`, permission helpers, `getJsonBody()` — F-13 |
| `frontend/src/utils/api.js` | JWT from localStorage — F-05 |
| `frontend/src/context/AuthContext.jsx` | Token persisted to localStorage — F-05 |
| `.env` | **Default secrets + debug enabled** — F-02/F-03 |
| `.htaccess` | Security headers, file blocking, HTTPS redirect (commented) |
| `composer.json` | JWT + dotenv + mailer; dev deps present |

---

*This audit is a static code review and does not replace a live penetration test. After implementing the P0 items, run a real pentest (or at minimum `composer audit`, PHPStan, PHPCS, and an OWASP ZAP scan) before production.*

---

## 9. P0 Implementation Guide — Step-by-Step

This section walks through the **6 P0 blockers** in dependency order. Each step shows the exact file, the change, and why it matters. Apply them in order — later steps depend on earlier ones.

### Step 1 — Fix the unsigned token (F-01) 🔴

**File:** `backend/app/Services/AuthService.php` → `generateToken()`

**Problem:** The current method returns `base64_encode(json_encode($payload))` — anyone can decode it, edit `user_id`/`role`, re-encode, and be authenticated. There is no signature.

**Change:** Replace the body of `generateToken()` to use the existing signed-JWT helper:

```php
private function generateToken(array $user): string
{
    // Use the signed JWT helper (firebase/php-jwt, HS256)
    return \App\Helpers\JWT::getInstance()->generateAccessToken($user);
}
```

`JWT::generateAccessToken()` already builds a proper payload (`iss`, `iat`, `nbf`, `exp`, `sub`, `email`, `role`, `type: 'access'`) and signs it with `JWT_SECRET`.

**Also fix the decoder** in `backend/app/Helpers/Auth.php` → `authenticateFromToken()`. It currently base64-decodes and trusts the payload. Replace the decode block with signature verification:

```php
// Replace the base64_decode block with:
$jwt = \App\Helpers\JWT::getInstance();
$decoded = $jwt->validateToken($token);   // verifies signature + exp
if ($decoded === null || !isset($decoded->type) || $decoded->type !== 'access') {
    return false;
}
$_SESSION['user_id'] = (int) $decoded->sub;
$_SESSION['user_email'] = $decoded->email ?? '';
$_SESSION['user_role'] = $decoded->role ?? '';
$_SESSION['session_valid'] = true;
$_SESSION['last_activity'] = time();
$this->authService->clearCache();
return true;
```

> ⚠️ **Compatibility note:** After this change, any token issued by the *old* base64 method stops working. Users will need to log in again once. That is expected and safe.

### Step 2 — Rotate the JWT secret (F-02) 🔴

**File:** `.env` (and `.gitignore`)

**Problem:** `JWT_SECRET=your-jwt-secret-key-change-in-production` is public knowledge.

**Change:**
1. Generate a strong secret:
   ```
   openssl rand -base64 48
   ```
2. Put the output in `.env`:
   ```
   JWT_SECRET=<the-generated-value>
   ```
3. Add `.env` to `.gitignore` if not already there, and **never commit** the real secret. Use a `.env.example` with a placeholder for teammates.

> ⚠️ Rotating the secret invalidates all existing tokens — users must log in again. Do this at the same time as Step 1 to avoid a double logout.

### Step 3 — Harden the environment (F-03) 🔴

**File:** `.env`

**Problem:** `APP_ENV=development` + `APP_DEBUG=true` leak stack traces to API clients, and `RBAC::lookup()` **allows all access** in development when the `role_permissions` table is missing.

**Change:**
```
APP_ENV=production
APP_DEBUG=false
```

**Also fix the RBAC fallback** in `backend/app/Helpers/RBAC.php` → `lookup()`. Remove the "allow all in development" branches so errors always **deny**:

```php
// In the "table doesn't exist" branch:
$this->cache[$key] = false;   // was: true in development
return false;

// In the catch (\Throwable) branch:
$this->cache[$key] = false;   // was: true in development
return false;
```

> ⚠️ **Why this matters:** If the `role_permissions` table is ever missing in production, the current code silently grants every permission. Deny-by-default is the only safe behavior.

### Step 4 — Wire the security middleware into the router (F-04) 🔴

**File:** `api.php`

**Problem:** The router instantiates controllers with no auth gate. Protection depends on each controller remembering `getUserId()`.

**Change:** Add the security bootstrap right after `require backend/bootstrap.php` (around line 8):

```php
// Apply security headers, session timeout, and proxy normalization
\App\Middleware\SecurityMiddleware::run();
```

Then add an **authentication gate** for all non-auth routes. The simplest safe approach is a guard at the top of the `try` block (after the `/auth` branch is matched, or before dispatching any non-auth controller):

```php
// After the auth routes block, before employee/department/etc.:
$isPublicRoute = str_starts_with($endpoint, '/auth')
    || $endpoint === '/consent/verify-employee';   // add any genuinely public routes

if (!$isPublicRoute && !\App\Helpers\Auth::getInstance()->check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
    exit;
}
```

> 💡 **Recommended (cleaner):** Wire the existing `AuthenticationMiddleware` pipeline instead of the inline guard. Create a small dispatcher in `api.php`:
> ```php
> $middleware = new \App\Middleware\AuthenticationMiddleware();
> $middleware->handle(function () use ($endpoint, $requestMethod) {
>     // ... existing route dispatch ...
> });
> ```
> This reuses the already-written middleware and gives you a single place to add `AuthorizationMiddleware` later.

### Step 5 — Rate-limit login (F-06) 🟠

**File:** `backend/app/Controllers/AuthController.php`

**Problem:** Unlimited login attempts.

**Change:** Add one line at the top of `loginAction()` (and `changePasswordAction()`):

```php
public function loginAction(): void
{
    \App\Middleware\SecurityMiddleware::protectAgainstBruteForce('login');
    // ... existing code ...
}
```

This returns HTTP 429 after 5 attempts in 15 minutes.

> ⚠️ **Production caveat:** `checkRateLimit()` currently stores counters in `$_SESSION`, but `bootstrap.php` calls `session_write_close()` for API requests, so per-session counters won't persist. For production, replace the storage with a DB/Redis table keyed by `IP + action` (see P2 item 22). The method is already structured so you only change the storage layer.

### Step 6 — Regenerate the session on login (F-08) 🟠

**File:** `backend/app/Services/AuthService.php` → `login()`

**Problem:** The session ID is never regenerated after login → session-fixation risk.

**Change:** After the password is verified and before storing session variables (around line 104):

```php
// Prevent session fixation: new session ID after successful auth
session_regenerate_id(true);
```

---

### Verification checklist after applying P0

1. `php -l backend/app/Services/AuthService.php backend/app/Helpers/Auth.php backend/app/Helpers/RBAC.php backend/app/Controllers/AuthController.php`
2. Log in → confirm you get a **signed** token (decode it — it should have `iss`, `iat`, `exp`, `type: 'access'`).
3. Try a forged base64 token → should be rejected with 401.
4. Try 6 rapid failed logins → 6th returns 429.
5. Confirm `APP_DEBUG=false` hides stack traces in API error responses.
6. Confirm an unauthenticated request to `/api/employees` returns 401.
7. Run the existing test suite: `php backend/run_tests.php` (or `composer test`).
