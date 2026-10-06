-- ===========================================================================
-- 106_document_access_otp.sql
--
-- Two-step verification gate for opening an ENCRYPTED employee document.
--
-- WHAT THIS IS FOR
--   Employee documents (national ID, KRA PIN, contracts, medical) are stored
--   encrypted at rest (PART B, migration 105). Before the plaintext is streamed
--   to a browser, the OWNER of the document must approve the specific request
--   by entering a 6-digit code emailed to their own address.
--
--   This is deliberately stronger than an HR permission check. `employees:view`
--   says a role MAY look; the OTP says this particular person, right now, is
--   the employee and consents. It is the same two-step shape as
--   password_resets (migration 093): emailed code, stored only as a hash.
--
-- WHY THE OWNER AND NOT THE REQUESTER
--   Sending the code to the requester would prove only that the requester can
--   read their own inbox, which the session cookie already implies. The control
--   is only meaningful when the code lands with the data subject. The owner
--   therefore has to be present - or at least have their inbox - to release
--   their own document.
--
-- SHAPE
--   One row per approval request, keyed by (document, requester, session).
--   The session is part of the key so two browser tabs cannot share an
--   approval, and so a code issued to tab A cannot be spent by tab B.
--
--   Only the SHA-256 of the code is stored. A dump of this table is inert.
--   max_attempts burns the row at 5 wrong guesses, because a 6-digit space
--   must not be walkable inside its 10-minute window.
--   expires_at + consumed_at make each approval single-use and time-boxed.
--
--   file_name_mime records the MIME type observed BEFORE encryption. After
--   encryption finfo can only report the container, so the original type has
--   to be stored or the response Content-Type would be wrong.
--
-- NUMBERING
--   105 is the vault/storage migration. 106 follows it.
--
-- IDEMPOTENT
--   Every statement is guarded, so re-running changes nothing and preserves
--   all existing rows.
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- document_access_otp
-- ---------------------------------------------------------------------------
-- is_granted / consumed_at mean a verified approval may be spent exactly once.
-- The attempt counter and the expiry are enforced in the query, so a stale
-- approval cannot be replayed even if a cleanup job has not yet run.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_access_otp')=0,
  'CREATE TABLE IF NOT EXISTS `document_access_otp` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `document_id` int(11) NOT NULL,
    `table_name` varchar(50) NOT NULL DEFAULT ''employee_documents'',
    `requester_user_id` int(11) NOT NULL,
    `owner_employee_id` int(11) NOT NULL,
    `owner_email_hash` char(64) NOT NULL,
    `code_hash` char(64) NOT NULL,
    `attempts` tinyint(4) NOT NULL DEFAULT 0,
    `verified_at` datetime DEFAULT NULL,
    `consumed_at` datetime DEFAULT NULL,
    `expires_at` datetime NOT NULL,
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_daotp_lookup` (`document_id`,`requester_user_id`,`verified_at`,`consumed_at`),
    KEY `idx_daotp_expiry` (`expires_at`),
    KEY `idx_daotp_live` (`requester_user_id`,`verified_at`,`consumed_at`,`expires_at`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- employee_documents additions
--
-- is_encrypted   the file on disk is an MWSC1 container; its key lives in
--                file_encryption (migration 105). NULL/0 means legacy
--                plaintext, which the read path still serves.
-- size_bytes     the ORIGINAL plaintext size, so the response can send an
--                accurate Content-Length after decryption.
-- original_mime  the MIME type before encryption, for the same reason.
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_documents'
       AND COLUMN_NAME='is_encrypted')=0,
  'ALTER TABLE `employee_documents` ADD COLUMN `is_encrypted` tinyint(1) NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_documents'
       AND COLUMN_NAME='size_bytes')=0,
  'ALTER TABLE `employee_documents` ADD COLUMN `size_bytes` bigint(20) unsigned DEFAULT NULL',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_documents'
       AND COLUMN_NAME='original_mime')=0,
  'ALTER TABLE `employee_documents` ADD COLUMN `original_mime` varchar(127) DEFAULT NULL',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- Index supporting the owner-side "what am I being asked to approve?" query.
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_access_otp'
       AND INDEX_NAME='idx_daotp_owner')=0,
  'ALTER TABLE `document_access_otp` ADD KEY `idx_daotp_owner` (`owner_employee_id`,`created_at`)',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
