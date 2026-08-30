# HR System Observability — Error Tracking & Monitoring

> Centralized error tracking, request correlation and system monitoring built
> alongside (never replacing) the existing Audit Logging system.

---

## 1. Architecture

```
                    HR SYSTEM OBSERVABILITY

                           REQUEST
                              │
                       Request ID (req_…)
                              │
          ┌───────────────────┼────────────────────┐
          │                   │                    │
       AUDIT              ERRORS              PERFORMANCE
   (existing)         error_groups         performance_events
   audit_logs         application_errors
   +request_id        error_group_users
          │                   │                    │
          └───────────────────┼────────────────────┘
                              │
                     SYSTEM MONITORING
                 (Settings ▸ System Monitor)
```

**One Request ID binds everything.** A single `req_…` value appears in every
API response header (`X-Request-ID`), every `audit_logs` row (migration 026),
every `application_errors` / `performance_events` row, the technical log line
(`backend/storage/logs/error.log`), the SPA memory, client error reports and
the user-facing reference code on crash screens.

### Component map (backend)

| File | Role |
|---|---|
| `backend/config/observability.php` | All thresholds/toggles (env-overridable). |
| `app/Services/ErrorTracking/RequestIdService.php` | Mints/validates ids; response header emission. |
| `app/Services/ErrorTracking/RequestSanitizer.php` | Recursive redaction → `[REDACTED]`. |
| `app/Services/ErrorTracking/ErrorFingerprint.php` | Grouping key (readable slug + SHA-256). |
| `app/Services/ErrorTracking/SeverityClassifier.php` | Severity/category rules. |
| `app/Services/ErrorTracking/ErrorTrackerService.php` | Capture entry point: pipeline, counters, alerting, retention. |
| `backend/bootstrap.php` | Id init; global exception handler → tracker + envelope; shutdown hooks (fatals, slow requests). |
| `app/Responses/JsonResponse.php` | Emits `X-Request-ID` on every JSON response. |
| `app/Controllers/System/MonitoringController.php` | Dashboard + client-collector REST API. |
| `backend/cron/error_retention.php` | Nightly retention sweep (CLI). |

### Component map (frontend)

| File | Role |
|---|---|
| `src/utils/errorReporting.ts` | Correlation-id store, device context, rate-limited reporter (plain `fetch`). |
| `src/api/client.ts` | Interceptors: stamp id, adopt server id, auto-report 5xx/network only. |
| `src/components/ErrorBoundary.jsx` | Global React crash catcher → friendly reference screen. |
| `src/pages/settings/ErrorMonitoring.tsx` | Dashboard (Overview / Errors / Performance). |
| `src/api/services/errorTrackingService.ts` | Typed API client for the dashboard. |
| `src/components/settings/SettingsLayout.jsx` | RBAC-gated “System Monitor” tab. |

## 2. Automatic interception — zero controller try/catch

No business code logs errors manually. Three global nets cover everything:

1. **Uncaught exceptions** — `set_exception_handler` in `bootstrap.php`
   (controllers, services, middleware, everything below `api.php`). Emits the
   standardized envelope `{success:false, message, error:{code, request_id,
   reference}}` — never stack traces, SQL or paths in production.
2. **PHP fatals** (`E_ERROR/E_PARSE/E_CORE_ERROR/E_COMPILE_ERROR`) —
   shutdown hook converts `error_get_last()` into a captured event.
3. **Slow requests** — same hook measures wall time; only events at/above
   `observability.performance.warning_ms` are persisted.

Explicit entry points exist for non-HTTP contexts:

```php
// cron / background jobs
ErrorTrackerService::getInstance()->captureJobFailure('attendance_reminders', $e, [
    'module' => 'Attendance', 'queue' => 'cron', 'attempt' => 1,
    'payload' => ['date' => '2026-08-25'],   // sanitized before storage
]);

// message-level events without an exception object
ErrorTrackerService::getInstance()->captureMessage('Export queue stalled', [
    'severity' => 'HIGH', 'endpoint' => 'job://exports',
]);
```

### Fail-safety (§28)

Every persistence step is wrapped; a tracker failure degrades to
`error_log('[ErrorTracker][FALLBACK] …')` and returns `null`. A
re-entrancy guard prevents recursive capture storms. The application’s own
response is never affected by monitoring failures (verified by
`ErrorTrackerFailSafeTest`).

## 3. Database schema (migration `031_error_tracking.sql`)

| Table | Purpose | Notes |
|---|---|---|
| `error_groups` | One row per unique fingerprint; lifecycle owner. | UNIQUE `fingerprint_hash`; readable `fingerprint`; counters; assignment/resolution; `last_notified_at` cooldown. |
| `application_errors` | Individual occurrences (server/client/job). | `error_uuid` ERR-YYYYMMDD-XXXXXX; FK→group; `request_id`; RESTRICTED columns (`stack_trace`, `request_payload/query/headers`); client context (`url`, `component`, `browser*`, `device_type`, `screen_size`). |
| `error_group_users` | Exact distinct affected users per group. | UNIQUE(group,user) + `INSERT IGNORE` ⇒ O(1) new-user detection. |
| `performance_events` | Slow requests/cron only (≥ warning_ms). | `duration_ms`, `threshold_level`, `request_id`, memory. |
| `audit_logs` | *(existing)* gained `request_id` in migration 026 — the correlation bridge. |

Indexes cover every dashboard path: `request_id`,
`(error_group_id, created_at)`, `(fingerprint_hash, created_at)`,
`created_at`, `severity`, `(source, created_at)`, endpoint prefix,
`(user_id, created_at)`.

## 4. Error lifecycle (§9)

```
NEW → ACKNOWLEDGED → INVESTIGATING → FIXED → VERIFIED → RESOLVED
                        │                                 │
                        └────────── IGNORED ◄─────────────┘
                                  (any recurrence reopens to NEW)
```

- Transitions: `POST /system/errors/groups/{id}/manage`.
- Each field transition is permission-checked individually.
- **Auto-reopen:** recurrence on a RESOLVED/FIXED/VERIFIED/IGNORED group
  flips it back to NEW (notes preserved as history).
- **Every change is audited** through the existing `AuditService::log()`
  (module `system_errors`, old/new values, actor, request id).

## 5. Severity & category rules (first match wins)

1. Infrastructure exceptions (`PDOException`, `mysqli_sql_exception`,
   `RedisException`…) → **CRITICAL / DATABASE_ERROR**
2. Auth failures (401 / JWT classes) → LOW client-caused, HIGH server-side / AUTHENTICATION
3. Authorization failures → LOW / AUTHORIZATION
4. External service errors → HIGH / EXTERNAL_SERVICE
5. Unexpected 5xx on business-critical modules (Attendance, Leave, Payroll,
   Employees, Authentication) → **CRITICAL / SYSTEM_ERROR**
6. Any other unexpected 5xx → HIGH / SYSTEM_ERROR
7. Expected 4xx family (capture OFF by default): 400/422 VALIDATION ·
   401 AUTHENTICATION · 403 AUTHORIZATION · 404 NOT_FOUND · else BUSINESS_RULE
8. Message escalation (“database connection failed”, “gone away”, deadlock…)
   upgrades to CRITICAL/DATABASE_ERROR. Escalation is monotonic.

## 6. Fingerprinting / grouping (§8)

Readable key `{module}.{exception-bucket}.{normalized-message}`
(`PDOException` → `pdo`, `mysqli_sql_exception` → `database`) with dynamic
content stripped (ids/uuids/numbers/quoted strings), so “Employee #4821 not
found” and “#9912” share a group. The stored hash is SHA-256 over module +
class + normalized message + originating file — distinct throw-sites never
merge. Counters are incremental: occurrences always bump; affected-user count
recomputes only when a genuinely new user appears.

## 7. Permissions (RBAC, §31)

Module `system_errors` added to `backend/config/permissions.php` and seeded
in migration 031:

| Action | Grants | super_admin | hr_manager |
|---|---|---|---|
| `view` | Dashboards, lists, detail pages | ✅ | ✅ |
| `manage` | Status / severity changes | ✅ | ✅ |
| `assign` | Assign a developer | ✅ | — |
| `resolve` | Resolution notes, fixed version, resolving statuses | ✅ | ✅ |
| `view_sensitive` | Stack traces + sanitized payloads/headers | ✅ | — |

Without `view_sensitive`, RESTRICTED fields are returned as `[RESTRICTED]`
(server-side masking in `MonitoringController::show()`). The SPA tab itself is
hidden from non-admin roles; the API enforces the same rules independently.

## 8. Retention (§26)

`backend/cron/error_retention.php` (nightly) deletes per config:

```
retention.occurrence_days        = 90   # raw server occurrences
retention.client_days            = 30   # browser-reported occurrences
retention.performance_days       = 30   # slow-request events
retention.resolved_group_months  = 12   # aged-out RESOLVED/IGNORED groups
```

Crontab: `30 2 * * * php /path/to/hrdemo/backend/cron/error_retention.php`

## 9. API endpoints

| Method & path | Permission | Purpose |
|---|---|---|
| `GET  /api/system/errors/stats` | view | Overview cards, 24h series, by-severity/module, top endpoints, spikes. |
| `GET  /api/system/errors/groups` | view | Paginated groups; filters: severity, status, module, environment, assigned_to, search, date range; sortable. |
| `GET  /api/system/errors/{uuid\|groupId}` | view | Occurrence detail + group + siblings + correlated audit events + performance event. Accepts occurrence uuid OR numeric group id. |
| `POST /api/system/errors/groups/{id}/manage` | manage/assign/resolve | Lifecycle transitions; each change audited. |
| `POST /api/system/client-errors` | public (any user) | SPA error collector; re-sanitized server-side; never rejects the client. |
| `GET  /api/system/performance` | view | Slow-request events + 24h summary + thresholds. |
| `GET  /api/system/health` | view | DB latency, hourly error pressure, open criticals, deployment identity. |

Standard envelope everywhere:
```json
{ "success": false,
  "message": "An unexpected error occurred.",
  "error": { "code": "INTERNAL_SERVER_ERROR", "request_id": "req_01K3X7J8Q2A9…" } }
```

## 10. Frontend integration

- **Request ID**: axios request interceptor stamps `X-Request-ID`;
  response interceptor adopts the server’s authoritative id.
- **Auto-reporting**: network failures and HTTP ≥ 500 are reported to
  `/api/system/client-errors` with endpoint/status/reference context.
  4xx responses are expected behaviour and are NOT reported (§34).
- **ErrorBoundary**: wraps the whole app (`App.jsx`); crashes render
  “Something went wrong.” + Reference code + Reload button (§33).
- **Rate limiting / dedupe**: max 20 reports/min; identical signature
  suppressed for 15 s; reporter uses plain `fetch` so it can never recurse
  through interceptors.

## 11. Deployment / version tracking (§17)

Set during deploys (`.env`):

```
APP_VERSION=2.8.1
GIT_COMMIT=abc1234def
DEPLOYMENT_ID=deploy-2026-08-25-1
```

Every error/perf row stores environment+version+commit(+deployment); the
dashboard header shows current values, and the detail page flags
“occurred on vX” mismatches so post-deploy spikes are attributable at a
glance. Alert log lines include version + request id.

## 12. Notifications & spike detection (§24/§25)

CRITICAL groups alert on first occurrence, then cooldown
(`notifications.cooldown_minutes`, default 60 min) while counters keep
updating. Each alert line includes last-hour count vs lifetime hourly average;
spikes ≥ +300 % (configurable) additionally surface as dashboard cards that
jump straight into the filtered group list.

## 13. Troubleshooting quick reference

| Symptom | Where to look |
|---|---|
| “Employee couldn’t clock in” report | Dashboard ▸ Errors ▸ search module `Attendance`; open group → Request section → copy Request ID → audit trail rows share it. |
| Error spike after deploy | Overview spikes panel + version badge mismatch on detail (“occurred on v…”). |
| Tracker suspected broken | `storage/logs/error.log` lines `[ErrorTracker][FALLBACK]` / `[ErrorTracker] capture failed`. App keeps working regardless. |
| Sensitive data worry | All captures pass `RequestSanitizer`; keys matching password/token/secret/api-key/etc. become `[REDACTED]`; headers reduced to an allow-list; unit-tested. |

## 14. Tests

```bash
# Backend (25 tests, 52 assertions)
cd backend && php ../vendor/bin/phpunit tests/Unit/Services/ErrorTracking

# Frontend (13 tests: reporter utils + ErrorBoundary)
cd frontend && npx vitest run src/__tests__/utils/errorReporting.test.ts \
                          src/__tests__/components/ErrorBoundary.test.jsx
```

Coverage highlights: redaction of nested sensitive fields & header allow-list,
bearer scrubbing, fingerprint stability/uniqueness, severity matrix incl.
business-critical bumping, message escalation, request-id format/adoption/
injection rejection, tracker fail-safety under persistence failure,
client dedupe/rate limiting, boundary fallback rendering without leaking stacks.
