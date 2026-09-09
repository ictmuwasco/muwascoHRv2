# Security Testing

Phase 7 (2026-09). How security is verified continuously, and how to run the
tests.

## 1. Running the suites

```bash
# Backend (PHPUnit 9, test DB mocked for unit tests)
php vendor/bin/phpunit                # or backend/run_tests.php

# Frontend (Vitest)
cd frontend && npx vitest run

# CI gates shipped in the repo
php scripts/ci/secret_scan.php        # BLOCKING: commits must not contain secrets
composer audit                        # advisory scan
```

Phase 7 result: **backend 201 tests / 816 assertions, 0 failures (1 skipped);
frontend 42 tests, 8 files, 0 failures**. DB-bound integration tests remain
grouped/excluded (`@group requires-db`) as in prior phases.

## 2. Security test inventory

### Authentication
| Test file | Covers |
|---|---|
| `Unit/Middleware/AuthenticationGateTest.php` | no session → unauthenticated; session without valid flag rejected; public-route normalization; protected routes never public |
| `Unit/Middleware/CsrfOriginTest.php` (Phase 7) | cross-site origin rejected on state changes; same-origin/allowlist/non-browser pass; look-alike host rejected; kill-switch |
| `Unit/Middleware/RateLimitTest.php` | file-backed limiter: max attempts, identifier isolation, window expiry |
| `Unit/Helpers/JwtSecurityTest.php` | missing/default/short secret rejected; tamper/wrong-key/alg-none/expired/wrong-issuer rejected; access↔refresh separation |
| `Unit/Services/AuthServiceTest.php` | login lifecycle (credentials → account status → JWT) |

### Authorization / IDOR
| Test file | Covers |
|---|---|
| `Unit/Authorization/AuthorizationServiceTest.php` | RBAC grants, user overrides, deny-over-grant |
| `Unit/Authorization/PrivilegeEscalationTest.php` | role assignment guards |
| `Unit/Authorization/RoutePermissionMapTest.php` | route→permission integrity + Phase 7 throttle governance |
| `Unit/Security/IdorOwnershipEnforcementTest.php` (Phase 7) | notification scope, leave-cancel ownership, profile/leave doc scoping |
| `Unit/Security/SecurityPolicyConfigurationTest.php` (Phase 7) | CSP, headers, CORS wildcard ban, webroot + uploads lockdown, UserResource secrets |

### Injection & validation
| Test file | Covers |
|---|---|
| `Unit/Security/SqlInjectionSurfaceTest.php` (Phase 7) | no raw SQL builders; no interpolated query strings; allowlisted ORDER BY; prepared-statement spot checks |
| `Unit/Validators/RequestValidatorsTest.php` | shape/format/enum validation |
| `Unit/Services/Leave/*`, `Unit/Services/Attendance/*` | business-rule validation (dates, transitions, balances) |

### Mass assignment (Phase 7)
`Unit/Services/MassAssignmentTest.php` — protected fields (`session_token`,
`login_identifier`, `last_activity`, `salary`, `profile_token`,
`profile_image_url`, `is_active` on update, rogue columns) never reach the
repositories on create/update/profile paths; plaintext password never leaves
the service.

### File security (Phase 7)
`Unit/Middleware/StreamHeadersTest.php` — inline only for PDF/images;
sandbox + nosniff + no-store; filename CRLF/quote stripping.

## 3. What the negative tests simulate

- **Unauthenticated request → 401** (`isAuthenticated()` false; gate deny path).
- **Cross-site CSRF** from `https://evil.example`.
- **IDOR**: manipulating `{id}` without ownership.
- **Mass assignment**: submitting protected/rogue fields.
- **SQLi**: raw builders, interpolation, un-allowlisted `ORDER BY`.
- **Header injection** via crafted filenames.

## 4. Coverage gaps handed to Phase 8

- True HTTP-level negative tests (`/employees/25` with an employee B session
  → 403) need an isolated DB integration suite (already planned in prior
  phases; `backend/tests/Integration/Api` scaffolding exists).
- Audit-redaction DB round-trip test.
- Upload negative tests require PHP's `$_FILES` machinery in a live FS
  context (unit stubs exist for validation logic only).