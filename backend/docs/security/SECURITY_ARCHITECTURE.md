# Security Architecture

> Phase 7 (2026-09) — authoritative overview of how MUWASCO HR enforces
> security end-to-end. Companion docs in this directory cover each layer in
> depth. Earlier analysis: `docs/ARCHITECTURE.md`, `docs/AUTHENTICATION.md`,
> `docs/AUTHORIZATION.md`, `docs/SECURITY_AUDIT.md` (root docs/ tree).

## 1. Runtime platform

This is **not** Laravel. The active runtime is a custom flat PHP framework
(documented in `docs/ARCHITECTURE_AUDIT.md` §1 and `docs/PHASE5_REPORT.md` §0):

```
React SPA
   │  JSON / httpOnly access_token cookie / X-Request-ID
   ▼
api.php  (root front controller)
   ├── SecurityMiddleware::applyCorsHeaders()      → CORS allowlist
   ├── SecurityMiddleware::run()                   → security headers, trusted
   │                                                 proxy, CSRF origin check,
   │                                                 session timeout
   ├── AuthenticationMiddleware::process()         → single auth gate
   │                                                 (public allowlist)
   └── ApiRouter (inline class, ~300 routes)       → route permission metadata
                                                    → (Phase 7) route throttle
                                                    → reflection dispatch
   ▼
Controller          — session identity, request shaping, outcome → HTTP
   ▼
Validator/Whitelist — input shape + format (security boundary)
   ▼
Domain Services     — THE authoritative business engine (transactions)
   ▼
Repositories/Models — prepared statements (mysqli prepare + bind_param)
   ▼
MySQL               — InnoDB, foreign keys, ENUMs
```

Laravel-shaped Phase 7 concepts map to this framework's own conventions
(same mapping Phases 1–5 used):

| Mandate concept | Implemented as |
|---|---|
| Middleware | `SecurityMiddleware` + `AuthenticationMiddleware` + `AuthorizationMiddleware` invoked from `api.php` |
| Policies | Route permission metadata (`module:action`) + `AuthorizationService` (deny-over-grant) + per-record scope checks in services |
| Form Requests | `App\Validators\*` + enum whitelists matched to schema ENUMs |
| `$fillable` | Explicit field allowlists in `UserService`/`EmployeeService` (Phase 7 `filterWritable()`) |
| Rate limiting | File-backed flock-atomic counters, keyed action+IP+account |
| Audit | `AuditService` (actor/action/target/before-after) + structured PII-safe `logger()` |

## 2. Trust boundaries

| Boundary | Enforcement |
|---|---|
| Internet → API | TLS at LB/proxy; HTTPS-only cookies; HSTS on HTTPS; CORS allowlist |
| Browser → API (state change) | CSRF Origin/Referer validation (`CSRF_ORIGIN_ENFORCE`) + SameSite=Lax |
| Unauthenticated → protected route | `AuthenticationMiddleware` single gate → 401 |
| Authenticated → route | Route permission metadata → `AuthorizationMiddleware` → 403 |
| User → another user's resource | Service ownership/scope checks (IDOR pins) |
| User → protected columns | Mass-assignment allowlists in services |
| Executable webroot | Uploads tree denied (`backend/public/uploads/.htaccess`) |
| Attacker → secrets in Git | `scripts/ci/secret_scan.php` BLOCKING CI gate |
## 3. Security layers (defense in depth)

1. **Transport**: HTTPS, HSTS, secure cookies, trusted-proxy proto detection.
2. **Headers**: CSP (`script-src 'self'`, `frame-ancestors 'none'`), nosniff,
   X-Frame-Options DENY, Referrer-Policy, Permissions-Policy, COOP/CORP.
3. **Authentication**: Argon2ID/bcrypt hashing, signed HS256 JWT access token
   in httpOnly cookie, refresh-token rotation/revocation, per-request
   `is_active` revalidation, session regeneration on login, idle+absolute
   session timeout.
4. **Authorization**: server-defined route permissions, deny-over-grant RBAC
   with user overrides, privilege-escalation guards, per-record scope checks.
5. **Input**: validators + enum whitelists, JSON body limits, mass-assignment
   allowlists, parameterized SQL only.
6. **Abuse**: rate limits on auth (per IP + account) and on 27 sensitive
   routes (Phase 7), file-upload size/MIME/extension validation.
7. **Data protection**: sensitive-field redaction in audit logs, API
   resources expose explicit field maps (never secrets), file streaming with
   sandbox CSP + nosniff + no-store.
8. **Operational**: secret-scan CI gate, deny-by-default identical login
   errors (no user enumeration), auditable identify/change events.

## 4. Sensitive data map

| Data | Storage | Additional controls |
|---|---|---|
| Password hashes | `users.password` (Argon2ID/bcrypt) | never logged; redacted in audit `new_values` |
| JWT secrets | `.env` → `JWT_SECRET` | fail-safe: missing/short/known-default rejected |
| Session tokens | `users.session_token` | not client-writable (mass-assignment allowlist) |
| Refresh tokens | `refresh_tokens` table | revoked on logout/password-change/disable/delete |
| Employee personal data | `employees` (national_id, dob, salary…) | `salary` and `profile_token` not client-writable; profile-image endpoints own `profile_image_url` |
| HR documents | private `backend/storage/uploads` + legacy `backend/public/uploads` | webroot tree denied; authorized streaming endpoints only |
| SMS/VAPID/mail creds | `.env` | never committed; `.env` gitignored |

## 5. Phase 7 additions at a glance

| Change | File(s) |
|---|---|
| Retired unvalidated upload action | `backend/app/Controllers/Employee/EmployeeController.php` |
| Locked down uploads webroot | `backend/public/uploads/.htaccess` |
| CSRF Origin/Referer enforcement | `backend/app/Middleware/SecurityMiddleware.php` (wired in `run()`) |
| Route throttling metadata + enforcement | `api.php` (router `add()` + `dispatch()`), `backend/config/rate_limits.php` |
| Mass-assignment allowlists | `backend/app/Services/UserService.php`, `backend/app/Services/EmployeeService.php` |
| Hardened file-streaming headers | `SecurityMiddleware::applyStreamHeaders()` + 3 streaming endpoints |
| Production env template | `.env.production.example` |
| Regression suites | `backend/tests/Unit/Middleware/*`, `backend/tests/Unit/Security/*` |
| Documentation | this directory |