# API Security

Phase 7 (2026-09). How the API layer is protected: transport, headers, CSRF,
CORS, body limits, rate limiting, error handling and route hardening.

## 1. Authentication model on the API

Cookie-based. The SPA authenticates with an **httpOnly `access_token`
cookie** (`withCredentials: true`); the browser attaches it automatically.
Tokens are never stored in `localStorage`/`sessionStorage`. SameSite=Lax is
the first CSRF layer; Origin/Referer validation (below) is the second.

## 2. CSRF — Origin/Referer enforcement (Phase 7, P7-3)

`SecurityMiddleware::enforceStateChangeOrigin()` runs from `run()` for every
`POST`/`PUT`/`DELETE`/`PATCH`:

- An `Origin` header is required to be on the CORS allowlist **or** to match
  the API's own `host:port` (scheme-insensitive behind proxies).
- Without `Origin`, the `Referer` origin is checked the same way.
- Requests with **neither header** are non-browser clients (curl/cron/mobile)
  and pass: browsers always attach Origin/Referer to cross-site
  state-changing requests, and non-browser clients cannot be CSRFed because
  a third party cannot attach the victim's cookies to them.
- Rejected → `403 CSRF_ORIGIN_MISMATCH` + security-log event.
- Kill-switch `CSRF_ORIGIN_ENFORCE=false` documented in `.env.production.example`
  for exotic split-origin deployments.

Tests: `backend/tests/Unit/Middleware/CsrfOriginTest.php` (including look-alike
host rejection `hr.muwasco.co.ke.evil.example`).

## 3. CORS

`backend/config/cors.php` — exact-origin allowlist (`CORS_ALLOWED_ORIGINS`
env override), credentials enabled, no wildcard. `applyCorsHeaders()` emits
`Access-Control-Allow-Origin` only when the request Origin is on the list.
Preflight OPTIONS handled ahead of routing. Regression-pinned by
`SecurityPolicyConfigurationTest::test_cors_configuration_never_uses_wildcard`.

## 4. Security headers

Emitted by `SecurityMiddleware::applySecurityHeaders()` + `secureCryptoHeaders()`:

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(self), microphone=(), camera=()
Content-Security-Policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com;
  style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net;
  img-src 'self' data:; font-src 'self' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net;
  connect-src 'self'; frame-ancestors 'none'; upgrade-insecure-requests;
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-origin
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload   (HTTPS only)
```

CSP notes: `script-src` has **no `unsafe-inline`**; `style-src 'unsafe-inline'`
and the two CDNs are required by the built SPA (Tailwind-injected styles,
chart/font CDN) — audited exceptions. `frame-ancestors 'none'` + X-Frame-Options
DENY block clickjacking.

## 5. Request body limits

`SecurityMiddleware::validateJsonBody()` — max 2 MB (`MAX_JSON_BODY_SIZE`),
max depth 10, valid JSON required, else 400. The SPA's form/file endpoints
are additionally capped by PHP `post_max_size`/`upload_max_filesize` (10 MB)
in the root `.htaccess`.

## 6. Rate limiting (Phase 7, P7-4)

Two mechanisms:

1. **Route-level** — `ApiRouter::add(..., $throttle)` 7th metadata argument
   (`'max:windowSeconds'`); enforced in `dispatch()` after the permission gate,
   keyed per authenticated user + IP. Governance list
   `backend/config/rate_limits.php` groups: exports `20:300`, identity writes
   `30:300`, admin password set `10:900`, privilege changes `30:300`, uploads
   `20:300`, leave decisions `120:300`, clock `10:300`, FY allocation `10:300`.
2. **Controller-level** — login and self password change
   (`protectAgainstBruteForce`, file-backed flock counters).

Throttled requests → `429 RATE_LIMITED`. Neither mechanism can be bypassed by
rotating cookies because storage is file-backed and keyed by IP + identifier.

## 7. Error handling

ApiResponse envelope: `{ success, message, data | error: { code, request_id,
reference?, details? } }`. `APP_DEBUG=false` in production → no stack traces,
no DB structure, no filesystem paths. `display_errors=0` always; errors go to
the structured PII-redacted logger + error tracker. The observability flag
`observability.redaction_placeholder` redacts sensitive fields at ingestion.

## 8. Route hardening (Phase 7)

- Router enforces permission → throttle → dispatch order.
- Typed route params (`int $id`) reject non-numeric via reflection cast rule
  (`callAction`) — no string IDs leaking into queries.
- Latent dead routes removed in Phase 5; `RoutePermissionMapTest` keeps the
  route+permission+throttle metadata honest.
- Public surface is exactly 3 routes (auth login, client-errors, consent
  status) — pinned by `AuthenticationGateTest`.