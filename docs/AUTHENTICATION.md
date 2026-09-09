# Authentication Architecture - MUWASCO HR System

**Status:** Authoritative reference (Phase 1 - Security Stabilization)
**Scope:** Login, JWT, authentication middleware, token lifecycle, account status, brute-force protection, security logging

> This document describes the SINGLE, traceable authentication architecture
> established in Phase 1. Where older documents disagree, this document wins.

---

## 1. Final Authentication Flow

```text
User Login (React SPA)
        |
        v
POST /api/auth/login                (public allowlist route)
        |
        v
SecurityMiddleware::protectAgainstBruteForce('login', 5, 900, $email)
        |                            file-backed counter: IP + account
        v
AuthController::loginAction
        |
        v
AuthService::login()
        |-- user lookup (email)
        |-- password_verify()        (Argon2ID / bcrypt)
        |-- account status check     (is_active)
        |-- JWT access token minted  (HS256, firebase/php-jwt)
        |-- session fixation guard   (session_regenerate_id)
        |-- httpOnly access_token cookie
        v
API response (user profile + token)

--- every subsequent request ---

Incoming Request /api/*
        |
        v
SecurityMiddleware::run()            headers, trusted proxy, session timeout
        |
        v
AuthenticationMiddleware::process()  THE single authentication gate
        |-- public allowlist check
        |-- Auth::check()            session first, JWT fallback
        |-- DB is_active revalidation (disabled users lose access NOW)
        v
Controller (fine-grained requirePermission checks)
        |
        v
Authorization layer / Protected API
```

## 2. Active Components

| File | Role | Status |
|------|------|--------|
| `api.php` | API entry; wires `SecurityMiddleware::run()` + `AuthenticationMiddleware::process()` before routing | ACTIVE |
| `backend/app/Middleware/SecurityMiddleware.php` | Security headers, trusted-proxy handling, session timeout, file-backed rate limiting, PII-safe security logging | ACTIVE |
| `backend/app/Middleware/AuthenticationMiddleware.php` | The single global authentication gate (public allowlist + DB account-status revalidation) | ACTIVE |
| `backend/app/Helpers/JWT.php` | Token minting + cryptographic validation (HS256 only, issuer + type enforcement, fail-safe secret) | ACTIVE |
| `backend/app/Helpers/Auth.php` | Session/JWT hybrid auth resolution; JWT path revalidates account status in DB | ACTIVE |
| `backend/app/Services/AuthService.php` | Login business logic, password policy, token revocation hooks | ACTIVE |
| `backend/app/Controllers/Auth/AuthController.php` | HTTP endpoints: login, logout, refresh, me, change-password | ACTIVE |
| `backend/app/Middleware/AuthMiddleware.php` | Static helper for legacy PHP pages | LEGACY (documented removal plan) |
| `backend/app/Middleware/AuthorizationMiddleware.php` | Permission gate, never wired in; authorization is handled per-controller | LEGACY |
| `backend/routes/routes/api.php` + `backend/bootstrap/app.php` | Laravel-style scaffold that is NOT the running router | LEGACY |

## 3. Token Design

| Property | Access token | Refresh token |
|----------|--------------|---------------|
| `type` claim | `access` | `refresh` |
| Algorithm | HS256 (enforced server-side) | HS256 |
| Expiry | `JWT_ACCESS_TOKEN_EXPIRY` (default 1h) | `JWT_REFRESH_TOKEN_EXPIRY` (default 7d) |
| Claims | `iss iat nbf exp sub email role employee_id type` | `iss iat exp sub type token_id` |
| Transport | httpOnly `access_token` cookie + JSON body | `refresh_tokens` table row (rotation-ready) |
| Accepted by | `JWT::validateAccessToken()` | `JWT::validateRefreshToken()` only |

Hard guarantees:

* Only HS256 is trusted. The token header can never select the algorithm;
  `alg=none` and algorithm-confusion tokens are rejected by firebase/php-jwt.
* Signature, `exp`/`nbf`/`iat` and `iss` are verified on every validation.
* A refresh token can NEVER authenticate an API request (`type` mismatch).
* The JWT secret comes exclusively from `JWT_SECRET`; missing, short (<32
  chars) or publicly-known default secrets abort authentication (fail-safe).

## 4. Token Lifecycle

| Event | What happens |
|-------|--------------|
| Login | Access token issued (cookie + body); refresh-token rows revoked state untouched; audit `LOGIN` |
| Every request | Gate revalidates session/JWT + `is_active` in DB |
| Logout | Session destroyed, cookies cleared, ALL refresh tokens revoked, audit `LOGOUT` |
| Password change (self) | New password hashed (min 8 chars), ALL refresh tokens revoked, session id rotated, audit `PASSWORD_CHANGE` |
| Password change (admin) | Same revocation + audit `PASSWORD_CHANGE` with admin context |
| User disabled | Refresh tokens revoked immediately; access dies within one request (gate DB check) |
| User deleted | Refresh tokens revoked; gate rejects on next request |
| Token expiry | Access token silently rejected (client redirects to login) |

> Note: the login flow currently issues access tokens only; the
> `refresh_tokens` table + `JWT::refreshAccessToken()` rotation machinery is
> deployed and revoked-on-lifecycle-events but not yet issued at login.
> Issuance is a planned Phase 2 enhancement; revocation paths are already live.

## 5. Brute-Force Protection

* `SecurityMiddleware::protectAgainstBruteForce($action, $max, $window, $identifier)`
* Storage: `backend/storage/cache/rate-limits/*.json` - atomic (`flock`),
  survives across requests (the old session-based counters were a no-op
  because the API closes sessions early).
* Key: `sha256(action | client IP | account identifier)` - raw identifiers
  never touch disk or logs (hashed prefix in security logs).
* Login: 5 attempts / 15 min per IP AND per account. Change-password: same.
* Storage failures fail OPEN with an error log (a disk problem must not lock
  out every legitimate user).
* HTTP 429 + JSON on exhaustion; audit event `auth.bruteforce_blocked`.

Login errors are deliberately uniform (`Invalid credentials`) for unknown
users, wrong passwords and disabled accounts - no account enumeration.

## 6. Security Logging & Audit Trail

* Authentication events go through `AuditService` (`LOGIN`, `LOGIN_FAILED`,
  `LOGOUT`, `PASSWORD_CHANGE`) and `SecurityMiddleware::logSecurityEvent()`
  (`auth.bruteforce_blocked`, `auth.session_expired`, `auth.jwt_invalid`,
  `auth.token_rejected_inactive_account`, `auth.login_inactive_account`).
* Captured: event, timestamp, IP, user agent, user id. Never captured:
  passwords, password hashes, JWT secrets, full tokens, credentials
  (`logSecurityEvent` redacts sensitive keys).

## 7. Security Configuration

Required environment variables (see `.env.example`):

| Variable | Requirement |
|----------|-------------|
| `JWT_SECRET` | REQUIRED. 32+ random chars, unique per environment. Generate: `php -r "echo bin2hex(random_bytes(32));"`. Never committed. Application refuses to authenticate without it. |
| `JWT_ISSUER` | Issuer claim validated on every token. |
| `JWT_ACCESS_TOKEN_EXPIRY` / `JWT_REFRESH_TOKEN_EXPIRY` | Seconds. |
| `TRUSTED_PROXIES` | Comma-separated proxy IPs allowed to set X-Forwarded-* headers. |

Production requirements:

1. `JWT_SECRET` rotated if ever exposed; different per environment.
2. `APP_DEBUG=false`; HTTPS terminated in front of PHP (`TRUSTED_PROXIES` set).
3. `.env` excluded from version control and deployment archives (deploy.yml
   already excludes `**/.env`).
4. CI gates (`php -l`, secret scan, phpunit) must pass before deploy
   (`.github/workflows/deploy.yml`).

## 8. Security Operations

* **Credential rotation:** rotate the DB password and `JWT_SECRET` via the
  secret manager, update `.env` on the host, restart PHP. Rotation of
  `JWT_SECRET` invalidates all outstanding tokens (users re-login).
* **Account disablement:** set the user inactive (admin UI). Tokens are
  revoked and the gate blocks the very next request.
* **Suspected compromise:** disable the account (immediate), rotate
  `JWT_SECRET` if token forgery is suspected, review `audit_logs` and
  `application_errors` for the actor IP.
* **Exposed credential in repo:** remove from source, rotate the credential,
  then run `php scripts/ci/secret_scan.php` locally before pushing.

## 9. Public Route Allowlist

Only these API routes are reachable unauthenticated (see
`AuthenticationMiddleware::PUBLIC_ROUTES`):

| Route | Reason |
|-------|--------|
| `POST /api/auth/login` | Credential exchange |
| `POST /api/system/client-errors` | Pre-login browser error collector (size-capped) |
| `GET /api/consent/status` | Must answer gracefully before the session propagates |

Everything else requires an authenticated, ACTIVE account.

## 10. Frontend Contract

* Tokens are NEVER stored in JavaScript storage; the access token lives in an
  httpOnly cookie (`withCredentials: true`).
* localStorage caches only the user PROFILE for fast UI restore.
* Any API 401 clears the cached profile and redirects to `/login`.
* Frontend password policy mirrors the backend (minimum 8 characters).