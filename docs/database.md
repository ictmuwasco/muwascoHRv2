# Database Reference

> Status: **Schema overview.** Source of truth is `backend/database/migrations/` plus the base schema; this page maps the domains, conventions and relationships. The directory currently contains 87 SQL migration files, including migration 113. Column-level detail lives in the migration files themselves.

## Conventions

- Migrations are plain SQL files numbered `NNN_name.sql` under `backend/database/migrations/`, applied in order. Some are shipped with dedicated runner scripts (e.g. `backend/database/run_migration_034.php`) and are written to be **idempotent** where re-running matters.
- **Duplicate numbers exist by history**: two `005_*` files (dependants column; sections/subsections), `030` / `030b`, and two `031_*` (appraisal cycles; error tracking). Order of application follows filename sort — new migrations should continue from the highest number used.
- Every table uses InnoDB with foreign keys; child rows that are owned by a parent (e.g. minutes) use `ON DELETE CASCADE`; cross-module references (e.g. `employee_id`) generally restrict deletion.
- Audit columns (`created_at`, `updated_at`, and where applicable actor fields) are standard; sensitive state changes additionally write to `audit_logs` (migration 013) via `AuditService`.

## Domain map

| Domain | Tables (introduced by) |
|---|---|
| Employees & org | `employees` (+ dependants 005, contract dates 009, profile picture 019), `departments`, `sections`, `subsections` (005b) |
| Users & access | `users`, `refresh_tokens` (002), `user_page_permissions` (003), `role_permissions` (004), hybrid permissions (014), permission overrides (015), strategy/performance permissions (027) |
| Attendance | `attendance` (optimised 006; `attendance_date` 020; location nullable 021; ip address 022; audit fields 026; report indexes 033) |
| Leave | leave applications + balances + types (base schema), attachments (001), application documents (011), delegates (012), `financial_years` (007), leave roster (018), report indexes (032) |
| Holidays | `holidays` (010) |
| Meetings & minutes | `meetings` (016), `meeting_invitations` (017), `meeting_minutes` + 4 child tables (034) |
| Strategy & performance | strategic plans / workplans (027–030b, 113), appraisal cycles ↔ financial year (031) |
| Notifications | push subscriptions (023), notification preferences (024), notification logs (025) |
| Observability | `audit_logs` (013), error tracking (031) |
| Other | dependants (005), consent versioning (008) |

## Key relationships

- `users` ↔ `employees`: a user account is the login identity; the employee record carries HR data. Modules resolve the employee from the authenticated user.
- `departments → sections → subsections`: org tree; employees attach at one level.
- `financial_years` scope leave balances (007) and appraisal cycles (031).
- `meetings → meeting_invitations → meeting_minutes (+ child tables)` cascade on meeting delete (016/017/034).
- `leave_applications` → documents/attachments (001/011) and delegates (012); `leave_roster` (018) is derived from approved leave.
- `push_subscriptions` (023), `notification_preferences` (024) and `notification_logs` (025) form the notification pipeline documented in `NOTIFICATIONS.md`.

## Working with migrations

- Apply order = filename order; idempotent migrations are safe to re-run.
- Migration 034 ships `backend/database/run_migration_034.php` as a standalone runner (used during the meeting-minutes rollout).
- When adding tables: follow the existing naming (`singular_entity` / plural for collections), add FKs with explicit ON DELETE behaviour, and index every column that appears in a `WHERE`/`ORDER BY` of a list or report query (pattern: 032/033).

## Selected migration index

The rows below are selected history, not a complete inventory of the migration directory. In particular, see the Workplan-related additions from 087 onward below; always use the migration files as the source of truth.

| # | Migration | Purpose |
|---|---|---|
| 001 | leave_attachments | Supporting file storage for leave applications |
| 002 | refresh_tokens | Refresh-token table for auth |
| 003 | user_page_permissions | Per-user page-level permissions |
| 004 | role_permissions | Role → permission matrix |
| 005 | add_dependants_column | Dependants field on employees |
| 005 | sections_and_subsections | Org sub-structure tables |
| 006 | attendance_optimization | Attendance table + query optimisation |
| 007 | financial_years | Financial-year scoping for balances |
| 008 | consent_version | Versioned consent |
| 009 | contract_dates | Employee contract dates |
| 010 | holidays | Public holidays |
| 011 | leave_application_documents | Per-application document records |
| 012 | add_delegate_to_leave_applications | Delegate on leave applications |
| 013 | audit_logs | Central audit trail |
| 014 | hybrid_user_permissions | Hybrid role/user permission model |
| 015 | hybrid_permission_overrides | Per-user permission overrides |
| 016 | create_meetings_table | Meetings |
| 017 | create_meeting_invitations_table | Invitations (RSVP + attendance) |
| 018 | leave_roster | Team leave roster |
| 019 | add_profile_picture_to_employees | Profile pictures |
| 020 | attendance_attendance_date_column | Business-date column |
| 021 | attendance_location_nullable | Optional geo location |
| 022 | attendance_ip_address | Clock-in IP capture |
| 023 | push_subscriptions | Web-push endpoint registry |
| 024 | notification_preferences | Per-user channel preferences |
| 025 | notification_logs | Delivery log |
| 026 | attendance_audit_fields | Actor columns on attendance corrections |
| 027 | strategy_performance_permissions | Strategy/workplan permission family |
| 028 | workplan_tracking_fields | Workplan progress/status tracking |
| 029 | create_workplan_logs_table | Workplan progress history |
| 030 | workplan_cascade_fields | Cascade bookkeeping |
| 030b | workplan_objectives_auto_increment | Auto-increment fix |
| 031 | appraisal_cycles_financial_year | Appraisal cycles tied to financial years |
| 031 | error_tracking | Error-tracking tables (observability) |
| 032 | leave_report_indexes | Leave report query indexes |
| 033 | attendance_report_indexes | Attendance report query indexes |
| 034 | meeting_minutes | Minutes + 4 child tables + permissions |
| 087 | single_active_financial_year | Ensures only the latest-starting financial year remains active |
| 089 | commercial_workplan_cascade_relation | Repairs the Commercial workplan cascade relation |
| 113 | workplan_level_backfill | Backfills missing Workplan cascade levels without replacing existing labels |

> **Note:** the selected migrations above record changes after the baseline schema (employees, departments, users, leave and attendance core tables pre-date the numbered series). For column-level detail, read the migration file or `DESCRIBE` the live table.

## Workplan level backfill and tier scope

Migration [`113_workplan_level_backfill.sql`](../backend/database/migrations/113_workplan_level_backfill.sql) updates `workplan_objectives.level` only when the current value is `NULL` or empty:

| Row assignment at migration time | Backfilled `level` |
|---|---|
| `subsection_id IS NOT NULL` | `subsection` |
| No subsection, `section_id IS NOT NULL` | `section` |
| No section or subsection, `performance_contract_id IS NOT NULL` | `department` |
| No unit IDs, no performance contract, and a `goal_id` or `strategic_target_id` is present | `organisation` |
| Any other unlabelled row | Left unchanged (`NULL`/empty) |
| Any row with a non-empty level already | Left unchanged |

The update order is most-specific first. Each statement rechecks `level IS NULL OR level = ''`, so repeating this backfill does not overwrite a level it has already populated. This migration does not create missing columns, change performance-contract financial years, or alter non-empty level values.

Workplan tier reads additionally infer a missing/empty level from the row's unit IDs at query time (`subsection_id` → `subsection`, else `section_id` → `section`, else `department`). This runtime fallback is separate from the migration: the migration may set `organisation` for qualifying unit-less rows, while a still-unlabelled unit-less row resolves to `department` in the tier SQL.

### Rollout precautions

1. Back up the target database and verify that migrations through the schema change that introduced `workplan_objectives.level` have already run.
2. Apply migration 113 using the deployment's approved database migration procedure. It is plain SQL; do not assume that application startup runs it automatically.
3. Before and after applying it, record counts for `level IS NULL`, `level = ''`, and each stored level. Also check a sample of rows with subsection IDs, section IDs, department contracts, organisation alignment, and pre-existing non-empty levels.
4. Confirm the scope predicates against the target database and intended employee/org assignments. The unit tests verify generated predicates and bound parameter order only; they do not establish production row counts.
5. Treat any separate financial-year reassignment as a separate reviewed change. Migration 113 does not move contracts or objectives between financial years.

## Related pages

- [meeting-minutes.md](meeting-minutes.md) — schema walkthrough for the newest tables (034)
- [NOTIFICATIONS.md](NOTIFICATIONS.md) — how the notification tables are used
- [SECURITY_AUDIT.md](SECURITY_AUDIT.md) — audit log semantics
