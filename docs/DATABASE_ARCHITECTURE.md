# Database Architecture

> **Canonical schema reference for the MUWASCO HR Management System.**
> Generated from a full reconciliation of the live database, every migration,
> the backend code, the frontend API surface, seeders, cron jobs and CI.
>
> **Engine:** MariaDB **10.4.32** (production *and* CI — they are now aligned).
> **Schema source of truth:** `backend/database/migrations/` applied by
> `backend/database/run.php`, tracked in the `migrations` table.

---

## 1. Current state

| Metric | Before (2026-09-29) | After |
|---|---|---|
| Tables | 106 | **87** |
| Database size | 28.70 MB | **17.95 MB** (−37%) |
| Largest table | `security_logs` 66,132 rows / 28.09 MB | `attendance` 5.08 MB |
| Applied migrations | 67 (3 untracked, ledger ≠ schema) | **74, 0 failed** |
| CI engine | `mysql:8.0` (≠ production) | `mariadb:10.4` (matches) |
| Pre-migration backup | none | **required, fails the build** |

**Core data verified untouched:** employees 193, attendance 14,826,
leave_applications 721, users 195, audit_logs 1,011, notifications 2,747,
ai_conversations 22, workplan_objectives 218.

---

## 2. Table inventory (87 tables)

### 2.1 Employees & organisation
`employees`, `departments`, `sections`, `subsections`, `offices`,
`next_of_kin`, `employee_contracts`, `employee_documents`

### 2.2 Leave
`leave_applications`, `leave_types`, `leave_roster`, `leave_transactions`,
`leave_history`, `leave_attachments`, `leave_application_documents`,
`employee_leave_balances`, `financial_years`, `holidays`, `user_consents`

### 2.3 Attendance
`attendance` (14,826 rows — the largest remaining table)

### 2.4 Appraisal, strategy & performance
`employee_appraisals`, `appraisal_cycles`, `appraisal_scores`,
`appraisal_revision_log`, `cycle_indicators`, `performance_contracts`,
`performance_indicators`, `strategic_plan`, `strategic_targets`, `goals`,
`kpis`, `workplan_objectives`, `workplan_logs`, `objectives`, `dependencies`

### 2.5 Meetings
`meetings`, `meeting_invitations`, `meeting_minutes`,
`meeting_minutes_agenda_items`, `meeting_minutes_decisions`,
`meeting_minutes_action_items`, `meeting_minutes_aob_items`

### 2.6 Access control (layered — see §5)
`users`, `roles`, `role_permissions`, `user_page_permissions`, `delegations`

### 2.7 Authentication & sessions
`db_sessions` (authoritative), `refresh_tokens`, `password_resets`

### 2.8 HR policy
`hr_policy_documents`, `hr_policy_sections`, `hr_policy_acknowledgements`,
`hr_policy_bookmarks`, `hr_policy_recent_views`

### 2.9 Notifications
`notifications`, `notification_logs`, `notification_preferences`,
`push_subscriptions`

### 2.10 Observability, audit & security
`audit_logs`, `application_errors`, `error_groups`, `error_group_users`,
`performance_events`, `security_events`, `security_incidents`,
`security_incident_events`, `vulnerabilities`, `vulnerability_events`,
`vulnerability_incidents`, `vulnerability_timeline`

### 2.11 AI assistant
`ai_conversations`, `ai_messages`, `ai_tool_calls`, `ai_usage_logs`,
`ai_feedback`, `ai_prompt_versions`, `ai_knowledge_documents`,
`ai_knowledge_chunks`

### 2.12 Framework placeholders (Laravel scaffolding, currently unused — retained)
`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`,
`personal_access_tokens`, `activities`, `migrations`

> These 9 were created by `0001_01_01_*.php` Laravel-style migrations. The
> application does **not** run Laravel's `Schema` facade or Eloquent, and
> verified `FROM cache` / `INTO jobs` / `FROM sessions` return zero hits. They
> are **retained deliberately**: the project direction is to converge on
> Laravel, and dropping scaffolding only to re-add it is churn. Revisit when
> the Laravel migration path becomes concrete.

---


## 3. Removed tables (19) — all with evidence + backup

Migration `099_remove_confirmed_unused_tables.sql`. Every table was checked
against Model / Repository / Service / Controller / raw SQL / report /
frontend API / migration / FK / security / AI / seeder / config / cron / CLI.

| Group | Tables | Reason |
|---|---|---|
| **A — backups** | `leave_transactions_backup`, `user_page_permissions_backup_015` | Snapshot copies made by migrations 014/015 and the original production dump. 0 rows, 0 references. |
| **C — superseded** | `password_reset_tokens` | Laravel default; `password_resets` is the one `PasswordResetService` actually uses. |
| | `user_notification_preferences` | Category-granular variant abandoned for the channel-granular `notification_preferences` that `NotificationPreferenceRepository` / `NotificationDispatcher` use. 0 rows. |
| | `security_logs` | **28 MB, largest table, zero code references.** Only the baseline schema and 3 `CREATE INDEX` statements in migration 084 mention it. |
| **D — no code** | `jdac_questionnaires`, `jdac_questions`, `jdac_responses` | No controller, service, model, route or frontend page exists. |
| | `salary_bands` | 13 reference rows, no consuming code. |
| | `strategies`, `absent_deductions`, `absent_exemptions`, `employee_offices`, `notification_templates`, `workplan_objective_cycles`, `appraisal_summary_cache` | Empty / abandoned scaffolding. |
| | `employee_devices`, `device_attempt_log`, `employee_otps` | Never wired into any authentication path. `db_sessions` + `refresh_tokens` + `password_resets` are authoritative. |
| **E — history** | `appraisal_score_archive` | 56 rows quarantined by migration 091. All `original_score_id` values are orphans — **the only surviving record** of those scores. Archived, not written to again (091 never re-runs). |
| | `employee_leave_brought_forward` | 41 rows of genuine leave carry-forward history for FY 30. Archived. |

`user_page_permissions_backup` and `user_page_permissions_new` are listed in
migration 099 for completeness but never existed at runtime — migration 014
creates and drops them internally.

### 3.1 Recovery

| Backup | Size | Contents |
|---|---|---|
| `backend/storage/backups/pre_099_FULL_RESTORABLE_20260929.sql` | 27.5 MB | **Full pre-change database.** |
| `backend/storage/backups/groupDE_preserve_20260929.sql` | 20.7 MB | Just the removed Group C/D/E tables. |
| `backend/storage/backups/schema_only_20260929.sql` | 0.13 MB | Schema, no data. |

**The full dump was restore-tested**: loading it into a scratch database
reproduced **106/106 tables with byte-identical row counts** across every
sampled table before a single `DROP` was issued.

> ⚠️ **Note on `security_logs`.** The data is unique, not duplicated:
> it spans **2026-01-20 → 2026-08-02** (64,520 rows — `login_success`,
> `login_credentials_validated`, `login_failed`, `csrf_validation_failed`),
> while `audit_logs` only begins **2026-09-11** and contains **zero rows** in
> that window. `security_events` is a different, vulnerability-oriented
> schema. To restore just this history:
>
> ```bash
> mariadb -u root -p muwasco \
>   < backend/storage/backups/groupDE_preserve_20260929.sql
> ```
>
> (Restore into a scratch database first if you only want the data.)

---


## 4. Foreign key map (29 constraints)

| Child | → Parent | ON DELETE | Rationale |
|---|---|---|---|
| `appraisal_cycles` | `financial_years` | RESTRICT | Period integrity |
| `appraisal_scores` | `employee_appraisals`, `performance_indicators` | RESTRICT | Appraisal history is retained |
| `employee_appraisals` | `employees` (x2), `appraisal_cycles` | RESTRICT | **Never cascade** — appraisal history must survive |
| `employee_contracts` | `employees` | CASCADE | Contract has no meaning without the employee |
| `leave_roster` | `employees` | CASCADE | Derived from approved leave |
| `next_of_kin` | `employees` | CASCADE | Pure dependent record |
| `meetings` | `users` (created_by) | CASCADE | Meeting owned by its creator |
| `meeting_invitations` | `meetings` | CASCADE | True child of the meeting |
| `meeting_invitations` | `employees`, `users` (x2) | SET NULL | **History preserved** when a person leaves |
| `hr_policy_*` | parents / `users` | CASCADE | Owned artefacts |
| `hr_policy_acknowledgements` | `employees` | SET NULL | Acknowledgement retained after departure |
| `hr_policy_sections` | `hr_policy_sections` (parent) | SET NULL | Self-referencing tree |
| `kpis` | `performance_contracts` | CASCADE | True child |
| `kpis` | `users` (x2) | SET NULL | Actor may be removed |
| `strategic_targets` | `strategic_plan`, `goals` | CASCADE | Owned by the plan |
| `performance_contracts` | `strategic_targets` | SET NULL | Contract survives target removal |
| `workplan_objectives` | `performance_contracts` | CASCADE | Owned |
| `workplan_objectives` | `sections`, `subsections` | SET NULL | Org reassignment must not delete the objective |
| `ai_knowledge_chunks` | `ai_knowledge_documents` | CASCADE | True child |

**Deliberate design note:** `audit_logs.user_id` and `notifications.user_id`
have **no FK**. They are audit/snapshot records that must outlive the user —
adding an FK would let a user deletion cascade into the audit trail. The
resulting orphans (172 audit rows, 32 notifications) are expected, not defects.

---

## 5. Authorization model (layered, not duplicated)

`role_permissions` (483 rows) + `user_page_permissions` + `roles` +
`delegations` are **not** duplicates. Precedence, per
`App\Helpers\AuthorizationService`:

1. Unauthenticated → **DENY**
2. `super_admin` → **ALLOW** (never overridden)
3. Explicit user override (`allow`/`deny`) in `user_page_permissions`
4. Self-service own-profile exception
5. Role permission in `role_permissions`
6. Active, time-bound `delegations` snapshot
7. No rule matched → **DEFAULT DENY**

**IDOR protection verified:** `AiConversationService::findOwnedConversation()`
filters `WHERE id = ? AND user_id = ?` and validates the UUID format, so
`GET /ai/conversations/{id}` cannot expose another user's conversation.

---

## 6. Index plan

### Added (migration 101) — each backed by a measured EXPLAIN

| Index | Query | Before | After |
|---|---|---|---|
| `leave_applications(leave_type_id)` | `WHERE leave_type_id=3` | `type=ALL`, `key=NULL`, **729 rows** | `type=ref`, **4 rows**, `Using index` |
| `audit_logs(user_id, created_at)` | `WHERE user_id=5 ORDER BY created_at DESC` | `ref` + **`Using filesort`** | `ref`, **filesort eliminated** |

`leave_type_id` appears **122 times** across `backend/app` and is a JOIN key
in `LeaveController`, `LeaveRepository` (x2), `ReportsController` (x2),
`DashboardController` and `Models\LeaveRequest`.

### Redundancy identified but intentionally NOT removed

`attendance` carries 9 indexes. `idx_attendance_date_emp(attendance_date,
employee_id)` is a leftmost-prefix duplicate of
`idx_attendance_date_emp_status(attendance_date, employee_id, status)`, and
`uk_attendance_employee_date(employee_id, attendance_date)` overlaps
`idx_attendance_employee_date(employee_id, clock_in)`. **Which index the

---

## 7. Schema drift repaired

**`ai_knowledge_documents` / `ai_knowledge_chunks` were referenced by live
code but did not exist.** `PolicyService::mirrorToKnowledgeBase()` runs on
every HR-policy publish and writes to both; it is wrapped in
`try/catch (\Throwable)`, so **publishing silently failed to mirror policy
content into the AI knowledge layer**. Migration 100 re-creates both tables
with the DDL copied verbatim from migration 041.

Root cause: `run.php` used `multi_query()` and recorded a migration as
`completed` without checking the result chain, so a file that failed part-way
was still marked done. **Fixed** — `run.php` now walks `next_result()`,
checks `$conn->errno` after every statement and throws, so a partial failure
records `failed`.

---

## 8. Retired: payroll module

`PayrollController` and its 4 routes are removed. The module had **no backing
store** — `payroll_periods` and `payroll_records` were created at *runtime*
inside the request handler (`CREATE TABLE IF NOT EXISTS` in the controller),
existed in no migration, and therefore never existed in any database built
from the migration history. The endpoints always returned "No payroll periods
yet" on a clean environment.

Removed: the controller, both route registrations, the `payroll` key in
`backend/config/permissions.php`, and the 3 orphaned `role_permissions` rows
(migration 102).

`AuditService`, `NotificationService::notifyPayrollRelease()` and the
`observability.php` "Payroll" module name were intentionally **left in
place** — they are generic, not payroll-controller-specific.

---

## 9. Safety controls

| Control | Status |
|---|---|
| Pre-migration DB backup | ✅ CI dumps and **aborts the build** if the dump is empty |
| Destructive-migration detection | ✅ Partial failures now recorded as `failed` |
| CI engine parity | ✅ `mariadb:10.4` = production 10.4.32 |
| Migration skip list | ✅ Emptied — no migration is silently skipped anywhere |
| Restore-tested backup | ✅ 106/106 tables, matching row counts |

**Rollback:** restore
`backend/storage/backups/pre_099_FULL_RESTORABLE_20260929.sql`, then delete
rows `>= '099'` from the `migrations` table.

---

## 10. Known issues — NOT fixed in this pass (deliberately)

These were identified during the audit and are documented rather than changed,
because each needs a product decision or production evidence:

| Issue | Why not changed |
|---|---|
| `ComplaintController` still creates `complaints` at runtime | Complaints **is** a live, routed feature. It needs a proper migration, not deletion. |
| `employees.national_id` is `INT` | Should be `VARCHAR` for a national identifier. Type change = destructive rewrite of PII. Needs a data-migration plan. |
| `audit_logs.old_values/new_values/metadata` are `LONGTEXT` | Candidates for native `JSON`, but the application writes PHP-serialized arrays; needs a serialization migration first. |
| `SELECT *` on sensitive tables (5 sites) | Exposes `password`, `national_id`, `salary`. Needs per-endpoint column allow-listing. |
| No automated test suite | `composer.json` references PHPUnit but `backend/tests/` and `phpunit.xml` do not exist. CI skips PHPUnit when no `*Test.php` is found. |
| 18 dynamic `ORDER BY $` sites | Each needs an individual whitelist review. |
| Data orphans (30 `employee_leave_balances`, 1 `ai_conversations`) | Reported, not deleted — silently deleting HR records is not safe. |

---

## 11. ERD (text)

```
departments ─┬─< sections ──< subsections
             └─< employees >─┬─ departments
employees ───┤               ├─ sections / subsections / offices
            │                ├─< attendance
            │                ├─< leave_applications >─ leave_types
            │                ├─< employee_leave_balances
            │                ├─< employee_appraisals >─ appraisal_cycles >─ financial_years
            │                │        └─< appraisal_scores >─ performance_indicators
            │                ├─< employee_contracts
            │                ├─< employee_documents
            │                ├─< next_of_kin
            │                ├─< leave_roster
            │                └─< meeting_invitations >─ meetings
users ───────┴─< db_sessions | refresh_tokens | password_resets
            ├─< role_permissions (per role)
            ├─< user_page_permissions (per-user override)
            ├─< notifications ─< notification_logs
            └─< push_subscriptions
users ──< delegations (delegator / delegatee, time-bound)

strategic_plan ──< strategic_targets >── goals
              └─< performance_contracts >── employees
                    └─< kpis
                    └─< workplan_objectives >─ sections / subsections
                             └─< workplan_logs
ai_conversations (UUID, owner=user_id) ──< ai_messages ──< ai_tool_calls
                                            └─< ai_feedback
ai_knowledge_documents ──< ai_knowledge_chunks

meetings ──< meeting_invitations
        └─< meeting_minutes ──< agenda_items | decisions | action_items | aob_items

hr_policy_documents ──< hr_policy_sections (self-referencing)
                     └─< hr_policy_acknowledgements / bookmarks / recent_views

audit_logs | security_events | security_incidents | vulnerabilities | application_errors
```

---

*Last updated: 2026-09-29. Generated by full schema/code/frontend reconciliation.*

optimiser prefers depends on real cardinality**, so removal requires
production `EXPLAIN` evidence, not theory.

`attendance.attendance_date` is a `GENERATED ALWAYS AS (cast(clock_in as
date)) STORED` column. It contains 257 NULLs (rows with no `clock_in`);
those are allowed because MySQL/MariaDB treat NULLs as distinct in a UNIQUE
index.

> ⚠️ **Operational note.** Because `attendance_date` is a generated column,
> a plain `mysqldump` of this database **cannot be restored** — NULLs export
> as `0000-00-00` and collide on `uk_attendance_employee_date`. The backup in
> `backend/storage/backups/` was corrected for this. Any future backup
> tooling must do the same.
