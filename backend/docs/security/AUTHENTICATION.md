# Authentication

Phase 7 (2026-09) companion to `docs/AUTHENTICATION.md` (root tree). This
file records the phase's authentication hardening decisions and the control
map. The runtime flow is unchanged from Phase 1 consolidation; Phase 7 added
defense-in-depth around state-changing requests and verified the lifecycle.

## 1. Flow

```
POST /api/auth/login
  → rate limit (5 / 15 min, per IP + account hash)
  → AuthService::login()
      → normalize email, find user
      → verify password (Hash::verify — Argon2ID / bcrypt cost 12)
         every failure path returns the identical message
         'Invalid credentials' (no user enumeration)
      → is_active check (disabled accounts rejected, same message)
      → consent-version lookup
      → signed HS256 access token (iss/iat/nbf/exp/sub/email/role/type=access)
      → session_regenerate_id(true)         ← session-fixation prevention
      → httpOnly access_token cookie        ← token never in JS storage
      → session keys (user_id, role, login_time, last_activity, session_valid)
      → audit LOGIN
```

## 2. Enforcement per request

`AuthenticationMiddleware::process()` runs before routing for every request
not on the tiny public allowlist:

| Route | Reason |
|---|---|
| `POST /auth/login` | credential exchange |
| `POST /system/client-errors` | pre-login browser error collector |
| `GET /consent/status` | must answer pre-session |

After authentication the gate revalidates **`users.is_active` from the
database on every request** (`AuthenticationMiddleware::isAccountActive()`),
so disabled/deleted accounts lose access immediately — never after a token
expires. Identity fields are refreshed from the DB on the JWT restore path,
never from (possibly stale) token claims.

## 3. Password rules

| Rule | Where enforced |
|---|---|
| Min length 8 | `Validators/AuthValidator`, `Validators/UserValidator`, service, frontend |
| Hash at rest | `Helpers/Hash` — `PASSWORD_DEFAULT` (Argon2ID where available), bcrypt fallback cost 12 |
| Plaintext never stored | createUser/updatePassword only ever store `Hash::make()`. The controller passes *unfiltered* payload to the audit log, but `AuditService::filterSensitive()` redacts `password`-named keys before persistence |
| No default/reused secrets | not enforced automatically — see remaining risks |

## 4. Token & session lifecycle

| Event | Action |
|---|---|
| Login | `session_regenerate_id(true)`; httpOnly access cookie minted |
| Refresh | refresh token validated, rotation-ready (`refresh_tokens` table) |
| Logout | refresh tokens revoked (`JWT::revokeAllTokens`), access cookie cleared, session destroyed |
| Password change (self) | current password verified; refresh tokens revoked; `session_regenerate_id(true)` |
| Admin sets password | route throttled `10:900`; refresh tokens revoked |
| User disabled | refresh tokens revoked — active sessions fail the next `is_active` gate revalidation |
| User deleted | refresh tokens revoked |

## 5. Brute-force & abuse protection

- `POST /auth/login` — `protectAgainstBruteForce('login', 5, 900, $email)`
  (file-backed, atomic; keyed IP + account identifier hash so credential
  stuffing against one mailbox from rotating IPs is still throttled).
- `POST /auth/change-password` — 5 / 15 min per account (controller).
- Storage failures **fail open** with an error log so a disk problem can never
  lock all legitimate users out.

## 6. Session cookie configuration

| Setting | Value |
|---|---|
| HttpOnly | always on (`bootstrap.php`) |
| SameSite | `Lax` (`.env` `SESSION_SAME_SITE`) |
| Secure | env-driven: production template sets `SESSION_SECURE_COOKIE=true`; `.env.production.example` ships it on |
| Lifetime | `SESSION_LIFETIME=120` minutes |
| Idle timeout | 30 min (`SecurityMiddleware::enforceSessionTimeout`) |
| Absolute timeout | 12 h (`SecurityMiddleware::enforceSessionTimeout`) |

## 7. Phase 7 verification

- `backend/tests/Unit/Middleware/AuthenticationGateTest.php` — no-session
  detection, session-without-valid-flag rejection, public-route normalization,
  protected routes never public.
- `backend/tests/Unit/Middleware/CsrfOriginTest.php` — state-changing
  cross-site origin rejection.
- `backend/tests/Unit/Middleware/RateLimitTest.php` — limiter behavior.
- `backend/tests/Unit/Helpers/JwtSecurityTest.php` — signature/exp/alg/issuer.
- `backend/tests/Unit/Services/AuthServiceTest.php` — login lifecycle.

Operational note: password reset is **admin-driven** (`POST /users/{id}/change-password`
with `users:edit`), not self-service — there is deliberately no public
"forgot password" endpoint to abuse.