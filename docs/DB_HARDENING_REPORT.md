# Database Production Hardening

> **Phase 3 (2026-09-29):** backup integrity, test harness and serialization
> hardening landed. Earlier phases: `DATABASE_ARCHITECTURE.md` (table usage
> audit) and §2 below (indexing).
>
> **Engine:** MariaDB **10.4.32** (production *and* CI — aligned in Phase 1).

---

## 0. Phase 3 — what changed, and what it cost to find

Three defects turned out to be worse than §4 below suggested. Each was found
by measuring rather than reading, and each is now guarded by a test.

### 0.1 A plain `mysqldump` of this database could not be restored

This was carried forward as an unverified caveat. It was real.

`attendance.attendance_date` is
`GENERATED ALWAYS AS (cast(clock_in as date)) STORED`, and 257 rows carry
`clock_in = '0000-00-00'`. On the live server `sql_mode` includes
`NO_ZERO_IN_DATE`/`NO_ZERO_DATE`, so `cast()` yields **NULL** and
`uk_attendance_employee_date` is satisfied — NULLs never collide in a UNIQUE
index. The live table is therefore consistent.

`mysqldump` emits a prologue that **resets the session** to
`SQL_MODE='NO_AUTO_VALUE_ON_ZERO'`, dropping `NO_ZERO_DATE`. On restore,
`cast('0000-00-00' AS date)` then yields the literal date, and the three such
rows belonging to employee 383 collide:

```
ERROR 1062 (23000): Duplicate entry '383-0000-00-00'
    for key 'uk_attendance_employee_date'
```

**Fix:** `backup.sh` patches that prologue back to include
`NO_ZERO_IN_DATE,NO_ZERO_DATE`. **Verified** by restoring into a scratch
database: 87/87 tables, and `attendance` (14,826), `employees` (193),
`leave_applications` (721), `audit_logs` (1,011), `users` (195),
`notifications` (2,747), `ai_messages` (32) and `role_permissions` (480) all
match live row counts exactly.

`restore_test()` now runs as part of every backup. A gzip checksum only proves
the file was not truncated — this dump was perfectly intact and still
unrestorable, which is exactly the failure a checksum cannot see.

> Note: `--skip-generated-columns` is a MySQL 8 option and is **rejected** by
> MariaDB's `mysqldump`. Do not add it.

### 0.2 CI had never run a single test

`backend/tests/` did not exist. Three compounding reasons:

1. `backend/phpunit.xml` pointed at `tests/Unit/Services`,
   `tests/Unit/Repositories` and `tests/Unit/Controllers` — none of which ever
   existed. PHPUnit aborts entirely when a declared directory is missing.
2. `.gitignore:115` ignores `backend/phpunit.xml`, with the comment
   *"phpunit.xml.dist is tracked"* — **but no `.dist` file was ever
   committed**, so on CI the config lookup found nothing at all.
3. The job counted `*Test.php` files with `find`, found zero, printed
   *"PHPUnit skipped — no tests to run"* and **exited 0**.

Every "tests pass" claim in this project's history was therefore vacuous.

**Fixed:** `backend/phpunit.xml.dist` is tracked; the job now exits 1 when no
tests or no config are found. **40 tests, 63 assertions, all passing.**

### 0.3 Malformed JSON was silently discarding HR data

16 bare `json_decode()` calls, none using `JSON_THROW_ON_ERROR`. The failure
mode was invisible: `null` returned, a nearby `is_array()` guard coalesced it
to `[]`, and data disappeared with no exception and no log line.

Worst case, `EmployeeService`: `next_of_kin` and `dependants` arrive as JSON
strings with **no upstream validation**. Malformed JSON decoded to `null`, the
guard fell through, and the employee's next of kin was never saved — the
caller received **200 and no error**.

**Fixed** via `App\Helpers\Json`, which separates the two cases that were
conflated:

| Case | Method | Behaviour |
|---|---|---|
| Our own TEXT columns | `decodeStored()` | Never throws. Logs, returns a safe default. One corrupt row cannot take down a profile page or an authorization check. |
| Request bodies | `decodeRequest()` | Throws; the global handler answers **400 INVALID_JSON**. |

Applied to delegation permissions (authorization-critical — a corrupt value
must not silently become an empty scope), notification payloads, employee
next-of-kin, workplan dependencies, AI source metadata, security-AI tool
calls, rate-limiter state, cache entries and the shared request-body reader.

Three existing behaviours are **preserved and documented**, not "fixed":
`AiToolExecutor` returns `null` as its DENY sentinel; `WorkplanService::
decodeJsonField` falls back to the raw string; `SecurityMiddleware` and
`BaseValidator` already checked `json_last_error()`.

### 0.4 Genuine orphans found (reported, not deleted)

The new tests surfaced real data problems the earlier audit had not reached.

| Table.column | Orphans | Detail | Action |
|---|---|---|---|
| `employee_leave_balances.employee_id` | 30 | Employees 441/500/503/505 gone; all FY 30 | **Not deleted** — leave balances affect entitlement history |
| `notifications.user_id` | 32 | `user_id` 0 and 2 gone | **Not deleted** — inert; every read path filters `WHERE user_id = ?` |

Both are recorded as a **ratchet baseline** in `SchemaIntegrityTest`: the test
passes at or below the baseline, so any **new** orphan fails immediately.

> **A false positive worth recording.** `users.employee_id` joins to
> `employees.employee_id` (a `varchar` staff code like `'MOW12'`), *not*
> `employees.id`. Joining on `employees.id` reports **193 phantom orphans**
> that do not exist. This was hit during the audit; acting on it would have
> destroyed valid data. The test now documents the correct key.

### 0.5 Corrections to the earlier report

- **§4.4 understated it:** 16 `json_decode` sites, not 12.
- **§4.5 was worse than "dead column":** `users.session_token` holds **166
  non-null plaintext values**. Still untouched pending sign-off.
- **§4.1 collation counts were slightly off:** measured 48
  `utf8mb4_general_ci` / 36 `utf8mb4_unicode_ci` / 3 `utf8_general_ci`.
- **`attendance.accuracy` is correctly a `float`** — a GPS radius in metres,
  not money. Now asserted explicitly so nobody "corrects" it. All money is
  `DECIMAL`.

---

## 1. What was done in the indexing phase

Migration **`103_additive_query_indexes.sql`** — **12 indexes added, purely
additive.** No column dropped, no row deleted, no data mutated.

**Status: 75 migrations completed, 0 failed. All data verified intact.**

---

## 2. Measured before / after

Every index below was justified by a measured `EXPLAIN` showing a **full
table scan** (`type=ALL, key=NULL`) before the change.

| Table | Query | Before | After | Index used |
|---|---|---|---|---|
| `users` | `WHERE email=?` | `ALL`, 194 rows | **`ref`, 1 row** | `idx_users_email` |
| `employees` | `WHERE department_id=? AND employee_status=?` | `ALL`, 157 rows | **`ref`, 24 rows** | `idx_employees_status_dept` |
| `employees` | `WHERE national_id=?` | `ALL`, 157 rows | **`ref`, 1 row**, `Using index` | `idx_employees_national_id` |
| `employee_leave_balances` | `WHERE employee_id=? AND financial_year_id=? AND leave_type_id=?` | `ALL`, **2,692 rows** | **`ref`, 1559** | `idx_elb_emp_fy_type` / `idx_elb_fy` |

The optimiser correctly selected `idx_employees_status_dept` for the combined
org-scope predicate — confirming the composite column order (low-cardinality
`employee_status` leading) was right.

### Indexes added

| # | Index | Table | Justification |
|---|---|---|---|
| 1 | `idx_users_email` | `users` | Login path — hottest lookup, was unindexed |
| 2 | `idx_users_login_identifier` | `users` | Staff-number login key (AuthService) |
| 3 | `idx_users_active_role` | `users` | `DelegationService:2250`, `FinancialYearService:430`, `PolicyService:684` |
| 4 | `idx_employees_department` | `employees` | `LeaveRepository:283`, `ReportsController:302` |
| 5 | `idx_employees_section` | `employees` | `WorkplanService:252`, section-scoped views |
| 6 | `idx_employees_subsection` | `employees` | Sectional workplan views |
| 7 | `idx_employees_office` | `employees` | Office-scoped attendance/reporting |
| 8 | `idx_employees_status_dept` | `employees` | `AppraisalWorkflowService:848,892` |
| 9 | `idx_employees_email` | `employees` | HR directory search |
| 10 | `idx_employees_national_id` | `employees` | `EmployeeRepository:460` duplicate guard |
| 11 | `idx_elb_emp_fy_type` | `employee_leave_balances` | Leave balance screen + deduction path |
| 12 | `idx_elb_fy` | `employee_leave_balances` | FY rollover / per-FY reporting |

---

## 3. Duplicate-data pre-flight (blocks any future UNIQUE)

| Check | Result | UNIQUE viable? |
|---|---|---|
| `users.email` | ⚠️ **1 duplicate group — 3 rows share one address** | ❌ **NO** |
| `users.login_identifier` | 0 duplicates | Yes, but rule unconfirmed |
| `employees.national_id` | 0 duplicates | Yes, after type change |
| `employee_leave_balances` (emp, type, FY) | 0 duplicates | Yes, needs sign-off |

**No UNIQUE constraint was added.** The `users.email` duplicate must be
reconciled first. This is why indexes 1 and 9 are deliberately non-unique.

---

## 4. Findings NOT acted on (need your decision)

Investigated and **documented, not changed**.

### 4.1 `workplan_objectives`, `next_of_kin`, `strategic_targets` are `utf8` (3-byte)

Only these 3 tables remain on `utf8_general_ci`; all others are `utf8mb4`.
**Emoji and 4-byte characters will be rejected or truncated** in a
next-of-kin name, a workplan objective, or a strategic target.

There is also a **collation split**: 34 tables `utf8mb4_unicode_ci`,
50 tables `utf8mb4_general_ci`. Mixed collations can raise
"illegal mix of collations" on joins.

**Conversion is a table rebuild** — the highest-risk item in this phase.
Held for explicit approval.

### 4.2 Database user is `root` with `GRANT ALL PRIVILEGES ON *.* WITH GRANT OPTION`

`SHOW GRANTS` confirms it. The application has full administrative privilege
including the ability to create users and grant privileges. A least-privilege
`muwasco_app` user should be created. **Not done** — wrong grants would break
the application, and `.env` must be updated in the same step.

### 4.3 Timezone policy is undefined

`@@global.time_zone = SYSTEM`, `@@session.time_zone = SYSTEM`. There is no
documented storage-vs-reporting timezone strategy (UTC storage / EAT
reporting). Changing this affects `AppTime` and every report.

### 4.4 `json_decode()` without error handling — 12 sites

None use `JSON_THROW_ON_ERROR`, so malformed JSON silently becomes `null`.
Notable: `WorkplanController:935` decodes `workplan_objectives.dependencies`,
`LeaveController:979` decodes a leave field.

### 4.5 `users.session_token` — dead column, 166 non-null plaintext values

The only reference in the entire backend is a *comment* (`UserService:28`);
`db_sessions` is authoritative. Nulling or dropping it is a destructive data
change belonging in its own migration with a backup.

### 4.6 `audit_logs` / `attendance` index overlap

`audit_logs` has single-column indexes leftmost-prefix covered by composites
(`idx_user_id`, `idx_module`, `idx_target_type`). `attendance` has
`idx_attendance_date_emp` covered by `idx_attendance_date_emp_status`.
**Not removed** — which index the optimiser prefers depends on real
cardinality, so this needs production `EXPLAIN` evidence per query path.

---

## 5. Two real bugs found and fixed in the migration runner

While debugging a failed migration, two genuine defects surfaced in
`backend/database/run.php`. Both are now fixed.

### 5.1 A failed migration could never be retried

`migrations.uk_migration` is `UNIQUE` on the filename, and both the success
and failure paths used a plain `INSERT`. Once a migration was recorded
`failed`, re-running it threw `Duplicate entry ... for key 'uk_migration'`
and **crashed the runner**, so a corrected migration was permanently
unrunnable.

**Fix:** both paths now use `INSERT ... ON DUPLICATE KEY UPDATE`. Re-running a
corrected migration is now a normal operation.

### 5.2 Comments could silently corrupt a migration

`mysqli::multi_query()` splits on `;` with no SQL awareness. A `;` or an odd
number of apostrophes inside a `--` comment splits a statement in half or
opens a string literal that swallows the rest of the file. The symptom is a
misleading `syntax error near 'SET @s'` pointing at the wrong line.

**Fix:** `run.php` now scans each file first and throws a clear, line-numbered
error naming the actual cause.

---

## 6. Security posture

| Item | Status |
|---|---|
| `serialize()` / `unserialize()` in codebase | ✅ **Zero occurrences** — no object-injection risk |
| SQL injection | ✅ Prepared statements throughout |
| IDOR on AI conversations | ✅ `findOwnedConversation()` filters `user_id` + UUID format |
| Credentials in git | ✅ Secret scan passes (707 files) |
| Engine parity (CI vs prod) | ✅ Both MariaDB 10.4 |
| Pre-migration backup | ✅ CI dumps and aborts on empty |
| DB user least privilege | ⚠️ **Still `root`** — see §4.2 |
| `json_decode` error handling | ⚠️ 12 sites — see §4.4 |


## 7. Backup

`backend/storage/backups/pre_103_indexing_20260929.sql` (7.27 MB, 87 tables)
was taken **before** this phase and verified non-empty.

> ⚠️ **Restore caveat (carried forward from Phase 1):** because
> `attendance.attendance_date` is a `GENERATED ... STORED` column, a plain
> `mysqldump` of this database **cannot be restored** — NULLs export as
> `0000-00-00` and collide on `uk_attendance_employee_date`. The Phase 1 full
> dump was corrected for this; this smaller Phase 2 dump was **not** (use it
> for schema, not as a full restore). The corrected full backup is
> `pre_099_FULL_RESTORABLE_20260929.sql`.

---

## 8. Rollback

Migration 103 is fully reversible — it created indexes and nothing else:

```sql
ALTER TABLE `users` DROP INDEX `idx_users_email`;
ALTER TABLE `users` DROP INDEX `idx_users_login_identifier`;
ALTER TABLE `users` DROP INDEX `idx_users_active_role`;
ALTER TABLE `employees` DROP INDEX `idx_employees_department`;
ALTER TABLE `employees` DROP INDEX `idx_employees_section`;
ALTER TABLE `employees` DROP INDEX `idx_employees_subsection`;
ALTER TABLE `employees` DROP INDEX `idx_employees_office`;
ALTER TABLE `employees` DROP INDEX `idx_employees_status_dept`;
ALTER TABLE `employees` DROP INDEX `idx_employees_email`;
ALTER TABLE `employees` DROP INDEX `idx_employees_national_id`;
ALTER TABLE `employee_leave_balances` DROP INDEX `idx_elb_emp_fy_type`;
ALTER TABLE `employee_leave_balances` DROP INDEX `idx_elb_fy`;
```

Plus `DELETE FROM migrations WHERE migration='103_additive_query_indexes.sql';`
to re-queue it.

---

## 9. Open questions

1. **Duplicate account** — 3 rows share `robertthuku924@gmail.com`. Merge, rename, or leave?
2. **utf8 → utf8mb4 conversion** — include now, or as a follow-up? (Table rebuild.)
3. **Dedicated `muwasco_app` DB user** — create in this phase?
4. **Timezone policy** — UTC storage with EAT reporting, or EAT throughout?
5. **`json_decode` hardening** — throw on malformed, or log-and-continue?

---

*Phase 2 complete: additive indexing landed and measured. Phase 3 complete:
backup restore fixed and proven, CI now runs real tests, malformed JSON can no
longer silently discard HR data. Destructive work (§4.1–4.6) deliberately
held pending the decisions above.*

