-- ============================================================================
-- 108_policy_parse_status.sql
--
-- WHY
--   Uploading the MUWASCO HR manual (150+ pages) returns a bare 500 on
--   production with NO entry in backend/storage/logs and NO domain audit row -
--   only the AuditService route safety-net fires. That signature is a PHP
--   FATAL, not an exception: catch (\Throwable) never runs, so nothing logs
--   and nothing cleans up. Production FPM is capped at memory_limit=128M /
--   max_execution_time=30s, while CLI PHP on the same host runs
--   memory_limit=-1 / max_execution_time=0. DocumentParser::ensureParseHeadroom()
--   raises the limits with ini_set()/set_time_limit(), but Plesk locks them
--   with php_admin_value, so the raise is silently ignored. Parsing a full
--   manual therefore dies in the web request on production while the identical
--   file parses fine on a dev machine - the classic "works on localhost" bug.
--
--   The move_uploaded_file() had already succeeded by then, which is why
--   production accumulated orphaned policy-*.pdf files of identical size,
--   one per retry, none ever cleaned up.
--
-- WHAT
--   Section extraction moves OUT of the web request and into a CLI worker,
--   where the limits are genuinely unlimited. The upload now only stores the
--   file and inserts a DRAFT row, then returns 201 immediately. Four columns
--   record where extraction stands:
--
--     parse_status   pending | processing | done | failed
--     parse_error    human-readable reason when failed (null otherwise)
--     parse_attempts retry counter, so a permanently-broken file cannot be
--                    retried forever by the worker
--     parsed_at      when extraction completed
--
--   status stays the PUBLISHING workflow (draft/review/published/archived) and
--   is deliberately NOT overloaded with parse state: a version can be stored
--   and still be unparsed, and publishing must be blocked until parse is done.
--
--   Existing rows are backfilled to parse_status='done' when they already have
--   sections, and to 'failed' when they have none (they can never become a
--   readable policy), so the worker never re-touches historical data.
--
-- IDEMPOTENT: every statement is re-runnable. IF NOT EXISTS guards the columns
--   and the UPDATE only touches rows whose parse_status is still the default.
-- NUMBERING: 107 is email_channel_default_on; 108 follows it.
-- ============================================================================

ALTER TABLE `hr_policy_documents`
    ADD COLUMN IF NOT EXISTS `parse_status`
        ENUM('pending','processing','done','failed')
        NOT NULL DEFAULT 'pending'
        COMMENT 'Section extraction state, separate from publishing status';

ALTER TABLE `hr_policy_documents`
    ADD COLUMN IF NOT EXISTS `parse_error`
        VARCHAR(1000) DEFAULT NULL
        COMMENT 'Why extraction failed (null unless parse_status=failed)';

ALTER TABLE `hr_policy_documents`
    ADD COLUMN IF NOT EXISTS `parse_attempts`
        TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Extraction attempts, so a broken file is not retried forever';

ALTER TABLE `hr_policy_documents`
    ADD COLUMN IF NOT EXISTS `parsed_at`
        DATETIME DEFAULT NULL
        COMMENT 'When section extraction completed successfully';

-- Index the worker's claim query (pending rows, oldest first).
ALTER TABLE `hr_policy_documents`
    ADD INDEX IF NOT EXISTS `idx_hr_policy_parse_status` (`parse_status`, `id`);

-- Backfill: rows that already carry a section tree were parsed successfully
-- under the old synchronous path. Rows with zero sections can never be read,
-- so they are marked failed rather than left to be re-parsed forever.
UPDATE `hr_policy_documents`
   SET `parse_status` = 'done',
       `parsed_at`   = COALESCE(`parsed_at`, `created_at`)
 WHERE `parse_status` = 'pending'
   AND `section_count` > 0;

UPDATE `hr_policy_documents`
   SET `parse_status` = 'failed',
       `parse_error`  = 'Legacy upload predates async section extraction and has no extracted sections. Upload a new version to replace it.'
 WHERE `parse_status` = 'pending'
   AND `section_count` = 0;
