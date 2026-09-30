-- ===========================================================================
-- 099_remove_confirmed_unused_tables.sql
--
-- Database reconciliation: removal of tables proven unused by the application.
--
-- EVIDENCE STANDARD (every table below was checked against ALL of):
--   Model / Repository / Service / Controller / raw SQL / report / frontend
--   API / migration dependency / foreign key / security / AI / seeder /
--   config / cron / CLI. A table was only listed here when EVERY check
--   returned zero hits.
--
-- RECOVERY: every table removed here was dumped first to
--   backend/storage/backups/groupDE_preserve_20260929.sql   (Group C/D/E data)
--   backend/storage/backups/pre_099_FULL_RESTORABLE_20260929.sql (full DB)
-- Both dumps were restore-tested: a full restore into a scratch database
-- reproduced all 106 tables with byte-identical row counts.
--
-- ENGINE: MariaDB 10.4 (matches production and CI). Uses only portable DDL.
--
-- Idempotent: every statement is DROP TABLE IF EXISTS.
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- GROUP A — backup / temporary copies (0 rows, no references)
-- Created by the permission-conversion migrations 014/015 and by the
-- original production mysqldump. Snapshot copies only; never read by code.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `leave_transactions_backup`;
DROP TABLE IF EXISTS `user_page_permissions_backup_015`;
-- NOTE: `user_page_permissions_backup` and `user_page_permissions_new` are
-- created and dropped within migration 014 and do not exist at runtime.
-- Listed here so a fresh environment that somehow retains them also cleans up.

-- ---------------------------------------------------------------------------
-- GROUP D — features with no code behind them
-- Zero references across backend/app, frontend/src, database/Seeders, cron
-- and config. Row counts shown for the record.
-- ---------------------------------------------------------------------------

-- JDAC questionnaire engine: 15 questionnaires / 28 questions / 22 responses.
-- No controller, service, model, route or frontend page exists for it.
DROP TABLE IF EXISTS `jdac_responses`;
DROP TABLE IF EXISTS `jdac_questions`;
DROP TABLE IF EXISTS `jdac_questionnaires`;

-- Reference data with no consuming code (13 salary bands).
DROP TABLE IF EXISTS `salary_bands`;

-- Empty / abandoned scaffolding.
DROP TABLE IF EXISTS `strategies`;
DROP TABLE IF EXISTS `absent_deductions`;
DROP TABLE IF EXISTS `absent_exemptions`;
DROP TABLE IF EXISTS `employee_offices`;
DROP TABLE IF EXISTS `notification_templates`;
DROP TABLE IF EXISTS `workplan_objective_cycles`;
DROP TABLE IF EXISTS `appraisal_summary_cache`;

-- Device / OTP authentication leftovers. The authoritative session and
-- token infrastructure is db_sessions + refresh_tokens + password_resets
-- (see App\Helpers\Session, AuthService, PasswordResetService). These three
-- were never wired into any authentication path.
DROP TABLE IF EXISTS `employee_otps`;
DROP TABLE IF EXISTS `device_attempt_log`;
DROP TABLE IF EXISTS `employee_devices`;

-- ---------------------------------------------------------------------------
-- GROUP C — unused duplicates / superseded scaffolding
-- ---------------------------------------------------------------------------

-- Superseded by `password_resets`, which PasswordResetService actually uses
-- (token_hash + otp_hash + otp_attempts + consumed_at). This is the
-- Laravel-default table from 0001_01_01_000000_create_users_table.php and
-- has never held a row.
DROP TABLE IF EXISTS `password_reset_tokens`;


-- ---------------------------------------------------------------------------
-- security_logs — 66k rows / ~28 MB, the largest table in the database.
--
-- Removed because it has NO application code path: the only references
-- anywhere in the repository are the baseline schema and three CREATE INDEX
-- statements in migration 084. No model, repository, service, controller,
-- report, security module or frontend page reads or writes it. It was
-- abandoned when the audit trail moved to `audit_logs` and the security
-- event pipeline to `security_events`.
--
-- IMPORTANT — this data is unique and is preserved in the backup:
--   window 2026-01-20 .. 2026-08-02, 64,520 rows, including
--   login_credentials_validated, login_success, login_failed and
--   csrf_validation_failed. `audit_logs` only begins 2026-09-11, so it
--   contains ZERO rows for that window; `security_events` is a different
--   (vulnerability-oriented) schema. This history exists nowhere else and
--   is recoverable only from
--   backend/storage/backups/groupDE_preserve_20260929.sql
--
-- It also had no PRIMARY KEY (`id int(11) NOT NULL` without AUTO_INCREMENT).
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `security_logs`;

-- ---------------------------------------------------------------------------
-- GROUP E — real history, archived before removal
-- ---------------------------------------------------------------------------

-- 56 rows. Created by migration 091, which quarantined duplicate
-- appraisal_scores rows (archive_reason='legacy_duplicate') before the
-- (employee_appraisal_id, performance_indicator_id) unique key was enforced.
-- All 56 original_score_id values are orphans: the scores no longer exist in
-- appraisal_scores, so this table is the ONLY surviving record of them.
-- Migration 091 will not re-run, so it is never written to again. No PHP
-- reads it. Archived to groupDE_preserve_20260929.sql.
DROP TABLE IF EXISTS `appraisal_score_archive`;

-- 41 rows of real leave carry-forward data for financial year 30
-- (brought_forward_days). Affects leave entitlement history. No code reads
-- it, but the values are genuine payroll-affecting HR history and are
-- archived to groupDE_preserve_20260929.sql.
DROP TABLE IF EXISTS `employee_leave_brought_forward`;

-- ===========================================================================
-- End of 099_remove_confirmed_unused_tables.sql
-- ===========================================================================

-- Category-granular notification preferences, abandoned in favour of the
-- channel-granular `notification_preferences` (used by
-- NotificationPreferenceRepository / NotificationDispatcher). Different
-- schema, never populated, never queried.
DROP TABLE IF EXISTS `user_notification_preferences`;
