-- ===========================================================================
-- 105_employee_vault.sql
--
-- Two-layer data protection:
--   PART A - Private Vault (zero-knowledge, client-side encryption of the
--            employee most sensitive profile data). The server stores ONLY
--            ciphertext and wrapped keys; it can never read the content.
--   PART B - Encrypted storage (server-side encryption at rest of every file
--            under backend/storage and backend/public/uploads).
--
-- NUMBERING: this file is 105, not 101, because migrations
-- 101_evidence_based_indexes / 102_remove_payroll_module /
-- 103_additive_query_indexes / 104_attendance_device_lock already exist and
-- are recorded as completed in the migrations ledger. Re-using 101 would
-- collide with an applied migration. (Migration 103 records the same trap.)
--
-- ENGINE: MariaDB 10.4 (identical to CI and production).
-- IDEMPOTENT: every statement is guarded by information_schema, so re-running
-- is a no-op.
-- ROLLBACK: see backend/docs/security/PRIVATE_VAULT.md and
--            STORAGE_ENCRYPTION.md. This migration only CREATEs tables and
--            WIDENS column nullability, so a rollback never destroys vault
--            content - it only stops the application from reading it.
--
-- NO DATA IS MOVED HERE. Existing plaintext in employees / next_of_kin /
-- dependants is deliberately left untouched. Each employee migrates their own
-- fields INTO the vault from the browser, at setup time, under their own
-- passphrase. Until they do, the application keeps serving the legacy
-- plaintext and the UI shows "Set up your vault". A server-side bulk
-- migration is impossible by design: the server does not hold the key.
--
-- STYLE: the DDL below carries no inline COMMENT clauses. The DDL is built as
-- a string inside SET @sql := IF(...), and an embedded apostrophe in a COMMENT
-- (e.g. ''base64...'') terminates that outer string early - the remainder of
-- the CREATE TABLE is then parsed as SQL and fails with a syntax error near
-- the next column. Migrations 103/104 avoid this by using short DDL with no
-- embedded quotes. Column semantics are documented in the section headers.
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- SECTION 0 - nullability relaxation (required by PART A)
--
-- Vaulting a field means BLANKING its plaintext column once the employee has
-- encrypted it into the vault; leaving the plaintext behind would defeat the
-- entire feature, because anyone with database access could just read it.
--
-- Two of the in-scope columns are declared NOT NULL with no default, so they
-- cannot be blanked as-is (baseline schema):
--
--   employees.national_id  int(10)      NOT NULL   (line 827)
--   employees.gender       varchar(10)  NOT NULL   (line 826)
--
-- Both are widened to DEFAULT NULL. This is the SAFEST possible direction for
-- a schema change: it widens what the column accepts and can never lose or
-- truncate a stored value. Existing rows keep their data.
--
-- NOTE - national_id duplicate guard: EmployeeRepository::nationalIdExists()
-- runs WHERE national_id = ? to block duplicate national IDs on create and
-- import. Once the value is vaulted and the column blanked, the SERVER can no
-- longer perform that check. This is a real, accepted loss of a server-side
-- integrity control; the browser performs the duplicate check against the
-- vault before submitting. Documented in PRIVATE_VAULT.md.
--
-- gender is included for schema symmetry only: it is read by
-- EmployeeController::profileAction (line 855) and rendered by the frontend,
-- but NO migration ever created the column, so it is already always empty
-- today. Relaxing it changes nothing observable.
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees'
       AND COLUMN_NAME='national_id')='NO',
  'ALTER TABLE `employees` MODIFY COLUMN `national_id` int(10) DEFAULT NULL',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(
  (SELECT IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees'
       AND COLUMN_NAME='gender')='NO',
  'ALTER TABLE `employees` MODIFY COLUMN `gender` varchar(10) DEFAULT NULL',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- SECTION 1 - vault_keys
--
-- One row per user who has set up a vault.
--
--   public_key                    base64 SPKI of the users ECDH P-256 public key
--   wrapped_private_key           base64 AES-256-GCM, KEF = PBKDF2(passphrase)
--   wrapped_data_key_passphrase   base64 AES-256-GCM, KEF = PBKDF2(passphrase)
--   wrapped_data_key_recovery     base64 AES-256-GCM, KEF = PBKDF2(recovery code)
--   kdf_params                    JSON {algorithm, hash, iterations}
--   salt                          PBKDF2 salt
--
-- The data key is wrapped TWICE - by the passphrase and by the one-time
-- recovery code - so neither wrapper alone is enough. Neither is reversible
-- without a secret that never leaves the employees browser.
--
-- Wrapped blobs are base64 of AES-256-GCM ciphertext with the 12-byte IV
-- carried as a prefix (see frontend/src/lib/vault/crypto.ts).
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vault_keys')=0,
  'CREATE TABLE IF NOT EXISTS `vault_keys` (
     `user_id` int(11) NOT NULL,
     `public_key` text NOT NULL,
     `wrapped_private_key` text NOT NULL,
     `wrapped_data_key_passphrase` text NOT NULL,
     `wrapped_data_key_recovery` text DEFAULT NULL,
     `kdf_params` text DEFAULT NULL,
     `salt` varbinary(32) NOT NULL,
     `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
     `rotated_at` timestamp NULL DEFAULT NULL,
     PRIMARY KEY (`user_id`),
     KEY `idx_vault_keys_rotated` (`rotated_at`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- SECTION 2 - vault_items
--
-- The encrypted vault payload, one row per (employee, field_group).
--
-- field_group is a CONFIG KEY, not a column name - the mapping lives in
-- backend/config/vault.php so the scope can change without a migration.
-- Current groups: personal, next_of_kin, dependants, documents, salary.
--
-- The AAD binds the ciphertext to employee_id:field_group:v<version>, so a row
-- cannot be replayed under a different employee, group or version. version
-- increments on every write and is the optimistic-concurrency token, so a
-- stale client re-encrypting an old value cannot silently clobber a newer one.
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vault_items')=0,
  'CREATE TABLE IF NOT EXISTS `vault_items` (
     `id` int(11) NOT NULL AUTO_INCREMENT,
     `employee_id` int(11) NOT NULL,
     `field_group` varchar(32) NOT NULL,
     `ciphertext` longtext NOT NULL,
     `iv` varbinary(12) NOT NULL,
     `aad` varbinary(255) NOT NULL,
     `version` int(11) NOT NULL DEFAULT 1,
     `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
     PRIMARY KEY (`id`),
     UNIQUE KEY `uk_vault_items_employee_group` (`employee_id`,`field_group`),
     KEY `idx_vault_items_employee` (`employee_id`),
     KEY `idx_vault_items_updated` (`updated_at`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- SECTION 3 - vault_grants
--
-- A grant is the employees decision to let ONE named user decrypt their data
-- key, for a bounded window. The wrapped data key is produced in the EMPLOYEES
-- browser, addressed to the grantees public key (ECDH then HKDF then
-- AES-256-GCM). The server never holds the unwrapped key.
--
-- Read path: a grantee may fetch ciphertext ONLY while status=active AND
-- expires_at is in the future. The cron job
-- (backend/cron/vault_grant_expiry.php) flips lapsed rows to expired, but the
-- read query ALSO checks expires_at independently, so a missed cron run can
-- never widen access.
--
-- Index (grantee_user_id, status) serves the requesters own "what can I read"
-- lookup; (status, expires_at) serves the expiry sweep.
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vault_grants')=0,
  'CREATE TABLE IF NOT EXISTS `vault_grants` (
     `id` int(11) NOT NULL AUTO_INCREMENT,
     `employee_id` int(11) NOT NULL,
     `grantee_user_id` int(11) NOT NULL,
     `wrapped_data_key` text NOT NULL,
     `reason` varchar(500) DEFAULT NULL,
     `status` enum(''active'',''revoked'',''expired'') NOT NULL DEFAULT ''active'',
     `expires_at` datetime NOT NULL,
     `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
     `revoked_at` timestamp NULL DEFAULT NULL,
     PRIMARY KEY (`id`),
     KEY `idx_vault_grants_employee_status` (`employee_id`,`status`),
     KEY `idx_vault_grants_grantee_status` (`grantee_user_id`,`status`),
     KEY `idx_vault_grants_status_expires` (`status`,`expires_at`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- SECTION 4 - vault_requests
--
-- An HR/admin access request: who wants in, why, and the employees answer.
-- Approval does NOT itself grant access - approving arms the employees browser
-- to mint a vault_grants row. Keeping the two steps separate means a request
-- can be approved and the employee can still decline to wrap the key.
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vault_requests')=0,
  'CREATE TABLE IF NOT EXISTS `vault_requests` (
     `id` int(11) NOT NULL AUTO_INCREMENT,
     `employee_id` int(11) NOT NULL,
     `requester_user_id` int(11) NOT NULL,
     `reason` varchar(500) NOT NULL,
     `status` enum(''pending'',''approved'',''denied'',''cancelled'') NOT NULL DEFAULT ''pending'',
     `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
     `decided_at` timestamp NULL DEFAULT NULL,
     PRIMARY KEY (`id`),
     KEY `idx_vault_requests_employee_status` (`employee_id`,`status`),
     KEY `idx_vault_requests_requester_status` (`requester_user_id`,`status`),
     KEY `idx_vault_requests_created` (`created_at`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- SECTION 5 - file_encryption  (PART B metadata)
--
-- A SIDE table rather than extra columns on employee_documents /
-- leave_application_documents / hr_policies. Two reasons:
--   1. Part B must not couple itself to three unrelated table schemas, each of
--      which would need its own ALTER and its own rollback.
--   2. Master-key rotation becomes one indexed scan over this table instead of
--      three separate UPDATEs.
--
--   file_key_wrapped  base64 AES-256-GCM of the per-file key,
--                     AAD binds table_name:record_id so a wrapped key cannot
--                     be replayed against a different row
--   nonce             96-bit IV for that wrap
--   key_version       which master key did the wrapping
--   plaintext_sha256  sha256 hex of the ORIGINAL plaintext. This is what makes
--                     the one-time migration script safe: a file is only
--                     unlinked after it has been decrypted and re-hashed back
--                     to this exact value.
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='file_encryption')=0,
  'CREATE TABLE IF NOT EXISTS `file_encryption` (
     `id` int(11) NOT NULL AUTO_INCREMENT,
     `table_name` varchar(64) NOT NULL,
     `record_id` int(11) NOT NULL,
     `file_key_wrapped` text NOT NULL,
     `nonce` varbinary(12) NOT NULL,
     `key_version` int(11) NOT NULL DEFAULT 1,
     `algorithm` varchar(32) NOT NULL DEFAULT ''AES-256-GCM-CHUNKED'',
     `plaintext_sha256` char(64) NOT NULL,
     `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
     PRIMARY KEY (`id`),
     UNIQUE KEY `uk_file_encryption_target` (`table_name`,`record_id`),
     KEY `idx_file_encryption_version` (`key_version`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- SECTION 6 - audit_log index
--
-- Vault events are high-value and are queried by the security dashboard
-- ("who has been reading employee vaults?"). audit_logs already has
-- (user_id, created_at) and module/action columns; this composite makes the
-- module-scoped sweep an index scan rather than a filtered table scan.
-- ---------------------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs'
       AND INDEX_NAME='idx_audit_module_action_created')=0,
  'ALTER TABLE `audit_logs` ADD INDEX `idx_audit_module_action_created` (`module`,`action`,`created_at`)',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ===========================================================================
-- End of 105_employee_vault.sql
-- ===========================================================================
