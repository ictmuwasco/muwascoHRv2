# PHASE 7 — Security Audit & Hardening Report

> **Branch:** `feature/phase5-backend-domain-architecture`
> **Date:** 2026-09-01
> **Scope:** full repository audit + remediation + regression testing of the
> MUWASCO HR Management System.
> **Companion docs:** this directory (`SECURITY_ARCHITECTURE.md`,
> `AUTHENTICATION.md`, `AUTHORIZATION.md`, `API_SECURITY.md`,
> `FILE_SECURITY.md`, `AUDIT_LOGGING.md`, `SECURITY_TESTING.md`,
> `PRODUCTION_SECURITY.md`, `INCIDENT_RESPONSE.md`).

---

## 1. Executive summary

The system inherits a strong Phase 1–5 baseline: signed HS256 JWT with
fail-safe secret enforcement, a single authentication gate with per-request
`is_active` revalidation, Argon2ID/bcrypt hashing, deny-over-grant RBAC with
privilege-escalation guards, parameterized SQL throughout, a secret-scan CI
gate, and an audited, PII-redacted logging layer. **No SQL injection,
authentication bypass, or encryption weaknesses were found in the current
runtime.**

Phase 7 focused on the areas the mandate prioritizes that were **not yet
hardened**. Confirmed findings: **2 critical** (dead-but-live unvalidated
upload action + web-executable uploads tree), **1 high** (CSRF origin checks
never invoked), **1 high** (rate-limiting coverage gaps), **2 medium**
(document-streaming headers, production-config posture), **2 low**
(frontend debug residue, dependency advisories). All fixable findings were
remediated with regression tests; test coverage grew from **158 to 201
backend tests (688 → 816 assertions)** with **0 failures**, plus the 42-test
frontend suite.

**Nothing in this phase changed legitimate HR workflows.** Leave state
machine, roster rules, attendance clock rules, meeting visibility and report
semantics are preserved; authorization was tightened *around* them.

---

## 2. Vulnerabilities found & resolved

Severities: CRITICAL · HIGH · MEDIUM · LOW · INFORMATIONAL.

### F-01 · CRITICAL — Dead-but-live unvalidated upload action + public storage

- **Affected:** `backend/app/Controllers/Employee/EmployeeController.php`
  `uploadDocumentAction()`; `backend/public/uploads/employee_documents/`.
- **Risk:** the method accepted any file (no extension/MIME/size checks) and
  wrote it to a **web-executable** directory (mkdir 0777). It was not routed
  at audit time, but as a loaded controller method a single future route
  registration (or a parameter-echo bug) became remote code execution. Real
  legacy files with time-derived `uniqid()` names were already fetchable by
  URL guess within a window.
- **Evidence:** controller source; directory listing; root `.htaccess` had no
  uploads protection; `grep` confirmed zero call sites.
- **Fix:** deleted the method (zero-caller verified, same convention as Phase 5
  P5-3); created `backend/public/uploads/.htaccess` denying all direct access
  and disabling script execution; legacy files now serve only through the
  authorized streaming endpoint (owner/HR gate).
- **Verification:** uploads `.htaccess` asserted by
  `SecurityPolicyConfigurationTest::test_uploads_tree_is_hardened...`.
- **Regression test:** `backend/tests/Unit/Security/SecurityPolicyConfigurationTest.php`.

### F-02 · CRITICAL — Legacy uploaded HR documents exposed by direct URL

- **Affected:** files under `backend/public/uploads/employee_documents/`
  (e.g. `6a759dc27aea2_employee_report_2026-07-28.pdf`).
- **Risk:** unauthenticated download of employee-sensitive documents and
  potential XSS if a hostile file were stored and served inline.
- **Evidence:** real files present and served by Apache with no auth.
- **Fix:** the uploads tree `.htaccess` deny-all + no-execution render direct
  URLs inert; authorized streaming endpoints (with sandbox CSP + nosniff +
  no-store) are the only delivery path.
- **Verification:** header + .htaccess regression tests.
- **Regression test:** same as F-01; plus `StreamHeadersTest`.

### F-03 · HIGH — CSRF protection never invoked

- **Affected:** `SecurityMiddleware::addCsrfGuard()/validateCsrfToken()` were
  dead; cookie-auth SPA meant state-changing POST/PUT/DELETE had no
  cross-site defense beyond SameSite=Lax.
- **Risk:** cross-site request forgery from an attacker page that can send
  cookie-authenticated writes (Lax blocks most, but not all, cross-site
  sends).
- **Evidence:** grep showed the guard was only referenced in docs.
- **Fix:** implemented Origin/Referer enforcement
  (`SecurityMiddleware::enforceStateChangeOrigin()`, wired into `run()`):
  state-changing requests must present an allowlisted origin or the API's own
  origin; non-browser clients (no Origin/Referer) pass by design;
  `CSRF_ORIGIN_ENFORCE` kill-switch with documented exceptions.
- **Verification:** `Subjects` unit matrix — same-origin passes, evil origin
  rejected, look-alike host rejected, Referer fallback, kill-switch.
- **Regression test:** `backend/tests/Unit/Middleware/CsrfOriginTest.php` (10 tests).

### F-04 · HIGH — Rate limiting gaps on sensitive routes

- **Affected:** exports, user/employee writes, permission overrides, uploads,
  approval decisions and clock endpoints had no throttle (P5-18 debt).
- **Risk:** a compromised HR/admin session could scrape the entire dataset or
  churn the leave/workflow ledger without friction.
- **Evidence:** route inventory vs. limiter call sites.
- **Fix:** router gained server-defined throttle metadata (`max:windowSeconds`)
  enforced per user+IP after the permission gate; governance list
  `backend/config/rate_limits.php`; 27 sensitive routes annotated
  (exports `20:300`, identity writes `30:300`, admin password set `10:900`,
  privilege changes `30:300`, uploads `20:300`, leave decisions `120:300`,
  clock `10:300`, FY allocation `10:300`).
- **Verification:** `RoutePermissionMapTest` extended — governance presence +
  format tests; full suite green.
- **Regression test:** `RoutePermissionMapTest::testSensitiveRoutesDeclareAThrottle`
  and `testThrottleMetadataFormatIsValid`.
### F-05 · MEDIUM — Document streaming headers unsafe for hostile files

- **Affected:** `viewProfileDocumentAction`, leave `viewDocumentAction`,
  leave `downloadAttachment`.
- **Risk:** files were served inline based on stored/client-supplied names
  with no sandbox CSP; an allowlist regression would turn any stored file
  into an XSS vector; PII cached for an hour (`Cache-Control: private,
  max-age=3600`).
- **Evidence:** header inspection of the three streaming endpoints.
- **Fix:** `SecurityMiddleware::applyStreamHeaders()` — CSP sandbox (no
  `allow-same-origin`), nosniff, `private, no-store`, inline only for
  PDF/images, attachment for everything else / leave attachments
  (behavior preserved). CRLF/quote-stripped filenames.
- **Verification:** `StreamHeadersTest` (5 tests) incl. header-injection
  filename.
- **Regression test:** `backend/tests/Unit/Middleware/StreamHeadersTest.php`.

### F-06 · MEDIUM — Production posture drift

- **Affected:** `.env` (`APP_ENV=development`, `SESSION_SECURE_COOKIE=false`);
  `.env.example` `APP_DEBUG=true`; `.htaccess` HTTP→HTTPS + HSTS commented.
- **Risk:** accidental prod deploy with debug output, insecure cookies.
- **Fix:** added `.env.production.example` (production + debug off + secure
  cookies + prod CORS + trusted proxies + CSRF flag), clarified `.htaccess`
  HTTPS/HSTS guidance with deployment conditions. Dev `.env` untouched.
- **Verification:** template review; secret-scan still passes.
- **Regression test:** configuration intent asserted in
  `SecurityPolicyConfigurationTest` (CSP/headers/CORS).

### F-07 · MEDIUM — Mass-assignment surface on user/employee writes

- **Affected:** `UserService::createUser/updateUser`,
  `EmployeeService::createEmployee/updateEmployee/updateEmployeeProfile`.
- **Risk:** repositories build column lists from array keys; an unfiltered
  payload could write `users.session_token`/`login_identifier`/`last_activity`
  (authentication state), `employees.salary`, `profile_token` or `is_active`
  on update.
- **Evidence:** repository `update()` used `array_keys($data)`.
- **Fix:** explicit allowlists (`USER_WRITABLE_FIELDS`,
  `EMPLOYEE_WRITABLE_FIELDS`) with `filterWritable()` applied at the service
  boundary; `is_active` is create-only (audited toggle-status endpoint for
  changes). `next_of_kin`/`dependants` preserved (legitimate table columns /
  child-table persistence).
- **Verification:** `MassAssignmentTest` (5 tests) — protected + rogue fields
  never reach the repositories; plaintext password never leaves the service.
- **Regression test:** `backend/tests/Unit/Services/MassAssignmentTest.php`.

### F-08 · LOW — Frontend debug logging residue

- **Affected:** `Departments.jsx`, `LeaveApplication.jsx`
  (`console.log` of response payloads and employee lists).
- **Risk:** noisy logs; PII in browser devtools of HR users.
- **Fix:** removed the debug `console.log` calls (safe `console.error`
  unchanged). No sensitive token storage exists in the SPA (httpOnly cookie).
- **Verification:** `grep` clean for console.log outside test/build assets.
- **Regression test:** n/a (manual; candidate for a frontend lint rule in
  Phase 8).

### F-09 · LOW/INFO — Dependency advisories

- **Affected:** `composer.lock`, `package-lock.json`.
- **Findings:** `composer audit` — **0 security advisories**; 6 abandoned
  transitive `web-token/*` packages (used via minishlink/web-push).
  `npm audit --omit=dev` — **2 moderate** react-router CVE-2025-68470 bypass
  advisories; **no non-breaking fix** (react-router 7.x is a breaking major).
  14 dev-only advisories (jsdom/vitest chain) are not shipped.
- **Decision:** no blind major upgrade (mandate rule). Documented upgrade
  paths in `PRODUCTION_SECURITY.md` §7. `npm audit fix` will re-run in Phase 8.
- **Verification:** audit runs recorded in this phase.

---

## 3. Verified non-findings (checked, no action needed)

| Mandate item | Status | Evidence |
|---|---|---|
| SQL injection | Protected | prepared statements only; raw builders absent (regression-tested) |
| Password storage | Strong | Argon2ID/bcrypt cost 12; plaintext never persisted or logged |
| Token security | Signed | HS256 JWT; signature/exp/alg/issuer verified; httpOnly cookie storage |
| `.env` tracking | Ignored | `git check-ignore .env` → ignored; only `.env.example`/`.env.production.example` tracked |
| CORS wildcard | Absent | exact-origin allowlist, regression-tested |
| XSS in API output | Protected | React escapes by default; API JSON is data, not markup; streaming sandbox added |
| Audit tampering | Restricted | writes only via AuditService; reads permission-gated |

---

## 4. Security improvements (summary)

- Web-executable upload surface eliminated; uploads tree deny + no-exec.
- CSRF Origin/Referer enforcement for every state-changing API request.
- 27 sensitive routes throttled server-side with route-level governance
  (permission → throttle → dispatch).
- Mass-assignment allowlists for users and employees (auth-state and salary
  fields blocked).
- Streaming endpoints hardened (sandbox CSP, nosniff, no-store, inline-only
  for PDF/images).
- Production environment template; clearer HTTPS/HSTS provisioning notes.
- Frontend debug logging removed.
- 43 new regression tests across authentication, authorization, IDOR, SQLi
  surface, mass assignment, file security and policy configuration.
## 5. Remaining risks (accepted / deferred)

1. **Git history secret rotation (operational).** The Phase 1 DB password and
   the two admin default passwords (values on record in the `scripts/ci/secret_scan.php`
   known-leak list and in `docs/SECURITY_AUDIT.md` §9) remain in Git history.
   **Action required:** rotate the production DB account and any account still
   using a default admin password; approve history rewrite or amputation. Live
   `.env` uses different dev values and the JWT secret is a strong random, so
   no *active* credential reuses the leaked values.
   strong random, so no *active* credential reuses the leaked values.
2. **Legacy webroot documents.** Files under `backend/public/uploads/` are
   only reachable via authorized endpoints, but should be migrated to
   `STORAGE_PATH/uploads/documents` and the webroot tree deleted.
3. **MFA/TOTP not implemented** (`FEATURE_MFA=false`). Recommended before
   public rollout for admin/HR roles.
4. **Dependency upgrades:** react-router 7.x (breaking) to clear the two
   moderate advisories; abandoned `web-token/*` replacement via minishlink's
   supported stack.
5. **DB integration test coverage** (isolated-schema HTTP negative tests)
   remains grouped for Phase 8 — unit-level enforcement pins exist today.
6. **Rate-limit storage on shared filesystems** — flock counters assume a
   single-writer app server; multi-node deployments should move to Redis.

## 6. Production security checklist

See `PRODUCTION_SECURITY.md` — the full operational checklist covering
environment, transport, API surface, database, storage, web server,
dependencies and post-deploy verification commands.

## 7. Security test results (Phase 7 close)

| Suite | Result |
|---|---|
| Backend PHPUnit | 201 tests / 816 assertions / 0 failures (1 skipped) |
| Frontend Vitest | 42 tests / 8 files / 0 failures |
| `scripts/ci/secret_scan.php` | PASSED (519 files scanned, 0 secrets) |
| `composer audit` | 0 advisories (6 abandoned transitive) |
| `npm audit --omit=dev` | 2 moderate (no non-breaking fix) |

New Phase 7 tests: 43 total →
`CsrfOriginTest` (10), `StreamHeadersTest` (5), `MassAssignmentTest` (5),
`AuthenticationGateTest` (5), `SqlInjectionSurfaceTest` (4),
`SecurityPolicyConfigurationTest` (9), `IdorOwnershipEnforcementTest` (5,
incl. 2 new RoutePermissionMap governance tests).

## 8. Definition-of-done traceability

| # | Phase 7 item | Status |
|---|---|---|
| 1 | Full repository security audit | ✅ (this report; per-area docs) |
| 2 | Authentication hardened | ✅ verified/hardened (F-03, lifecycle) |
| 3 | RBAC verified | ✅ (RoutePermissionMap + AuthorizationService tests) |
| 4 | Policies implemented/verified | ✅ route-permission metadata + service scope checks |
| 5 | IDOR/BOLA addressed | ✅ F-07 + IdorOwnershipEnforcementTest |
| 6 | Mass assignment protected | ✅ F-07 + MassAssignmentTest |
| 7 | Request validation reviewed | ✅ existing validators + schema-ENUM whitelists |
| 8 | SQL injection risks eliminated | ✅ parameterized only + SqlInjectionSurfaceTest |
| 9 | XSS risks addressed | ✅ React escapes; streaming sandbox CSP |
| 10 | CSRF/CORS reviewed | ✅ F-03 + CORS regression test |
| 11 | Rate limiting implemented | ✅ F-04 (27 routes + auth) |
| 12 | File upload security | ✅ F-01/F-02 |
| 13 | File download authorization | ✅ F-05 + authorized streaming only |
| 14 | Sensitive HR data protected | ✅ F-07 (salary/tokens) + resource maps |
| 15 | API responses hardened | ✅ envelope; debug off; redaction |
| 16 | Production debug exposure eliminated | ✅ F-06 template |
| 17 | Secrets scanned | ✅ CI gate + git-history review |
| 18 | Credentials rotated where required | ⚠️ documented — production rotation action |
| 19 | Audit logs protected | ✅ AuditService-only writes; redaction; gated reads |
| 20 | Approval workflows secured | ✅ leave decision throttles + state machine (Phase 5) |
| 21 | Attendance security verified | ✅ clock throttles; server-determined identity |
| 22 | Leave security verified | ✅ cancel ownership, approvals, throttles |
| 23 | Meeting security verified | ✅ invite/minutes scope (Phase 5) + visibility docs |
| 24 | Report/export security verified | ✅ reports:export gate + export throttles |
| 25 | React security reviewed | ✅ F-08; no token storage; UX-only UI hiding confirmed |
| 26 | Database security reviewed | ✅ least-privilege guidance; FKs reviewed (Phase 4) |
| 27 | Dependencies audited | ✅ F-09 |
| 28 | Security tests created | ✅ 43 new tests |
| 29 | Security regression tests created | ✅ per-finding suites |
| 30 | Security documentation completed | ✅ backend/docs/security/* |
| 31 | Phase 7 security report generated | ✅ this file |