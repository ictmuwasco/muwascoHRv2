# Audit Logging

Phase 7 (2026-09). How MUWASCO HR provides auditable HR records without
storing secrets or person-identifiable noise.

## 1. AuditService

The single audit entry point is `App\Services\AuditService::log(module,
action, description, options)`. Every call persists one row in `audit_logs`
and never throws.

| Standard field | Source |
|---|---|
| actor `user_id` | resolved from authenticated session (never payload) |
| actor name/role snapshot | session values captured at write time |
| `action` / `module` | `AuditService::ACTION_*` / `MODULE_*` constants |
| `target_type` / `target_id` / `target_name` | resource being changed |
| `old_values` / `new_values` | before/after snapshots (sensitive-filtered) |
| `metadata` | non-sensitive extra context |
| `status` | `SUCCESS` / `FAILED` / `DENIED` |
| `ip_address`, `created_at` | server-derived |

## 2. Sensitive-field redaction

`AuditService::SENSITIVE_FIELDS` — keys matching (case/separator-insensitive)
`password`, `password_confirmation`, `current_password`, `new_password`,
`token`, `access_token`, `refresh_token`, `jwt`, `authorization`, `secret`,
`api_key`, `apikey`, `client_secret`, `cookie`, `session_id` are replaced with
`[REDACTED]` in `old_values`/`new_values` before persistence. Verified against
the actual call sites:

- `UserController::storeAction/updateAction` pass the client payload as
  `new_values` — the plaintext password is hashed on a copy in the service and
  the audit snapshot is redacted by `filterSensitive()`.
- Error logs go through the structured logger whose context strips the same
  keys (`SecurityMiddleware::logSecurityEvent`).

## 3. Coverage of security-relevant events

| Event | Action constant / logger event |
|---|---|
| Login / logout / failed login | `ACTION_LOGIN`, `ACTION_LOGOUT`, `ACTION_LOGIN_FAILED` |
| Password change (self / admin) | `ACTION_PASSWORD_CHANGE` |
| User create / update / delete / status | `ACTION_CREATE/UPDATE/DELETE`, `ACTION_STATUS_CHANGE` |
| Permission overrides | `ACTION_PERMISSION_CHANGE` (module `permission_overrides`) |
| Employee changes | `ACTION_CREATE/UPDATE/DELETE` on module `employees` |
| Leave decision | approve/reject/invalidate/cancel (controller audit + `logHistory`) |
| Clock in/out | `ACTION_CLOCK_IN` / `ACTION_CLOCK_OUT` |
| Meeting decisions | `ACTION_*` minutes + confirm/decline/cancel |
| Report/export | `ACTION_EXPORT` |
| Document download | audit on leave attachment download |
| Security events | structured logger (`auth.*`, `CSRF origin check failed`, `auth.bruteforce_blocked`, `auth.session_expired`) |

## 4. Tamper-resistance & access

- Writes go exclusively through `AuditService` (no generic INSERT path exposed).
- Reads are permission-gated: `GET /audit`, `GET /audit/{id}`,
  `GET /audit/statistics`, `GET /audit/filters` require `audit:view`;
  `GET /audit/export` requires `audit:export` and is rate-limited
  (`exports` group, `20:300`).
- Normal users have no write or delete capability at the application layer.

## 5. PII & retention

- Logged identity is the actor **id**, name snapshot and role snapshot —
  not raw email/phone beyond what business descriptions require.
- `logSecurityEvent()` caps user-agent length and redacts `authorization`/
  `token`/`email` keys.
- `observability` redaction placeholder applies at error-tracker ingestion.
- Retention is governed by the records schedule; audit exports are
  rate-limited to prevent bulk harvesting.

## 6. Verification

`backend/tests/Unit` coverage of redaction is asserted indirectly via the
`AuditService` source scan in `SecurityPolicyConfigurationTest` and the
end-to-end user-path tests in `MassAssignmentTest` (plaintext password never
leaves the service unhashed). A dedicated audit-redaction unit test is in the
Phase 8 backlog alongside DB-integration coverage.