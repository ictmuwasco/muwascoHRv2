-- ===========================================================================
-- 100_repair_ai_knowledge_base.sql
--
-- WHY THIS MIGRATION EXISTS (schema drift repair, not a new feature)
--
-- Migration 041_ai_assistant.sql defines BOTH `ai_knowledge_documents` and
-- `ai_knowledge_chunks`, and the `migrations` ledger records 041 as
-- 'completed'. On the running database NEITHER TABLE EXISTS.
--
-- Cause: run.php executes each migration with mysqli::multi_query() and then
-- records the file as 'completed' without inspecting the result set for
-- errors. When 041 was first executed it failed part-way (the two knowledge
-- tables were appended to the file after that first run), so the ledger and
-- the actual schema diverged and never reconciled.
--
-- Live impact: backend/app/Services/HrPolicy/PolicyService.php writes to
-- these tables on every policy publish/archive:
--     mirrorToKnowledgeBase()  -> SELECT/INSERT/UPDATE ai_knowledge_documents,
--                                  DELETE/INSERT ai_knowledge_chunks
--     demoteKnowledgeBase()    -> UPDATE ai_knowledge_documents SET status
-- That call is wrapped in try/catch (\Throwable) and logged, so publishing an
-- HR policy has been SILENTLY failing to mirror into the AI knowledge layer.
--
-- This migration re-creates the two tables with EXACTLY the DDL declared in
-- 041 so the drift is closed and the code path starts working. The DDL is
-- copied verbatim from 041_ai_assistant.sql lines 209-240 — no new columns,
-- no behaviour change, no data to migrate (both tables were always empty).
--
-- ENGINE: MariaDB 10.4. Idempotent (CREATE TABLE IF NOT EXISTS).
-- ===========================================================================

-- ----------------------------------------------------------------------------
-- AI knowledge base — controlled policy document store + chunk index.
-- restricted_roles is a JSON array of role keys; NULL = any authenticated user.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_knowledge_documents` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `title`            VARCHAR(200) NOT NULL,
    `doc_type`         ENUM('policy','handbook','procedure','faq') NOT NULL DEFAULT 'policy',
    `version`          VARCHAR(20)  NOT NULL DEFAULT '1.0',
    `file_path`        VARCHAR(500) NOT NULL COMMENT 'Private storage path — never webroot',
    `file_hash`        CHAR(64)     NOT NULL COMMENT 'SHA-256 of the stored file (integrity + dedupe)',
    `mime_type`        VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
    `status`           ENUM('processing','active','archived') NOT NULL DEFAULT 'processing',
    `restricted_roles` TEXT         DEFAULT NULL COMMENT 'JSON array of role keys allowed to see this document; NULL = all authenticated',
    `uploaded_by`      INT          DEFAULT NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `uk_ai_kdoc_hash_version` UNIQUE (`file_hash`, `version`),
    INDEX `idx_ai_kdoc_status` (`status`, `doc_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Approved HR policy documents for the AI knowledge layer';

CREATE TABLE IF NOT EXISTS `ai_knowledge_chunks` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `document_id`     INT UNSIGNED  NOT NULL,
    `chunk_index`     INT UNSIGNED  NOT NULL,
    `content`         TEXT          NOT NULL COMMENT 'Sanitized chunk text (plain, no markup)',
    `embedding`       BLOB          DEFAULT NULL COMMENT 'Packed float32 vector from the configured embedding model',
    `embedding_model` VARCHAR(100)  DEFAULT NULL,
    `token_count`     INT UNSIGNED  NOT NULL DEFAULT 0,
    `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_ai_kchunk_doc` FOREIGN KEY (`document_id`) REFERENCES `ai_knowledge_documents`(`id`) ON DELETE CASCADE,
    CONSTRAINT `uk_ai_kchunk_doc_index` UNIQUE (`document_id`, `chunk_index`),
    INDEX `idx_ai_kchunk_doc` (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Chunked + embedded knowledge index (Phase 7 ingestion)';

-- ===========================================================================
-- End of 100_repair_ai_knowledge_base.sql
-- ===========================================================================
