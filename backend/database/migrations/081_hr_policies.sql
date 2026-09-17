-- ============================================================================
-- 081_hr_policies.sql
-- Phase: HR Policy & Procedures Manual module — Database Foundation
--
-- Tables:
--   hr_policy_documents         — versioned policy documents (file + metadata
--                                 + workflow status)
--   hr_policy_sections          — structured section tree for navigation/search
--   hr_policy_acknowledgements  — per-employee "accessed and read" receipts
--   hr_policy_bookmarks         — per-user bookmarks of sections
--   hr_policy_recent_views      — per-user recently viewed sections (pruned)
--
-- VERSIONING / WORKFLOW
--   * status: draft → review → published → archived. Upload NEVER auto-publishes.
--   * Only ONE document is the currently active official policy. The DB-level
--     guarantee is a nullable UNIQUE token (active_token): set to a UUID when
--     the row is the single active version, NULL otherwise (MySQL unique
--     indexes allow multiple NULLs). Publishing is a transaction:
--     new row becomes active, previous active row becomes archived.
--   * Historical versions are NEVER silently deleted — soft delete (deleted_at)
--     only, via an explicit HR admin action.
--
-- SECURITY
--   * Policy files live in PRIVATE storage (backend/storage/policies) — never
--     the webroot — streamed only through permission-checked endpoints.
--     file_hash (SHA-256) pins integrity; stored_name is the server-generated
--     safe filename (client filename is display-only).
--   * Employees may read PUBLISHED documents only; drafts/review/archived are
--     filtered server-side (IDOR-safe: unknown id → 404).
--
-- RBAC: view/acknowledge → every role; manage/publish → hr_manager, super_admin.
--
-- AI KNOWLEDGE LINKAGE: on publish, PolicyService mirrors metadata + section
-- text into ai_knowledge_documents / ai_knowledge_chunks (doc_type='policy').
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + ON DUPLICATE KEY UPDATE seeds.
--
-- Place: backend/database/migrations/081_hr_policies.sql
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. hr_policy_documents — versioned policy documents (one row per version)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hr_policy_documents` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `title`           VARCHAR(200)    NOT NULL COMMENT 'Official document title (e.g. MUWASCO Human Resources Policy & Procedures Manual)',
    `description`     TEXT            DEFAULT NULL COMMENT 'Short internal summary shown in HR administration',
    `version`         VARCHAR(30)     NOT NULL COMMENT 'Policy version label (e.g. 1.0 / 2015 / 2027)',
    `status`          ENUM('draft','review','published','archived') NOT NULL DEFAULT 'draft' COMMENT 'Publishing workflow state',
    `source_type`     ENUM('manual','cba','circular','law','procedure','handbook') NOT NULL DEFAULT 'manual' COMMENT 'AI source hierarchy (manual > CBA > circular > law > procedure)',
    `effective_date`  DATE            DEFAULT NULL,
    `published_at`    DATETIME        DEFAULT NULL,
    `archived_at`     DATETIME        DEFAULT NULL,
    `file_path`       VARCHAR(500)    NOT NULL COMMENT 'Relative path under backend/storage/policies (private, never webroot)',
    `file_name`       VARCHAR(255)    NOT NULL COMMENT 'Original client filename — display/label only, NEVER used for disk access',
    `stored_name`     VARCHAR(255)    NOT NULL COMMENT 'Server-generated safe filename actually written to disk',
    `file_hash`       CHAR(64)        NOT NULL COMMENT 'SHA-256 of the stored file (integrity pin)',
    `mime_type`       VARCHAR(100)    NOT NULL,
    `file_size`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `page_count`      INT UNSIGNED    DEFAULT NULL COMMENT 'Page count of the original manual (informational)',
    `section_count`   INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Number of extracted sections (dashboard card)',
    `uploaded_by`     INT             NOT NULL COMMENT 'users.id of the HR admin who uploaded this version',
    `is_active`       TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = the single currently active official policy',
    `active_token`    CHAR(36)        DEFAULT NULL COMMENT 'DB-level single-active guarantee (UNIQUE); NULL when not active',
    `acknowledgement_message` TEXT    DEFAULT NULL COMMENT 'HR-approved acknowledgement wording for this version',
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME        DEFAULT NULL COMMENT 'Soft delete — historical versions are never silently removed',
    UNIQUE KEY `uq_hr_policy_title_version` (`title`, `version`),
    UNIQUE KEY `uq_hr_policy_active_token`  (`active_token`),
    CONSTRAINT `fk_hr_policy_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    INDEX `idx_hr_policy_status` (`status`, `effective_date`),
    INDEX `idx_hr_policy_source` (`source_type`),
    INDEX `idx_hr_policy_active` (`is_active`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Versioned HR policy documents (private files, workflow-controlled)';

-- ----------------------------------------------------------------------------
-- 2. hr_policy_sections — structured section tree (chapter → subsection)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hr_policy_sections` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `policy_document_id` BIGINT UNSIGNED NOT NULL,
    `parent_id`          BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = top-level chapter',
    `section_number`     VARCHAR(30)     DEFAULT NULL COMMENT 'Official numbering (e.g. 12.7.1) — preserved verbatim',
    `title`              VARCHAR(255)    NOT NULL COMMENT 'Official section title — preserved verbatim',
    `content`            MEDIUMTEXT      DEFAULT NULL COMMENT 'Official policy wording — preserved verbatim (never reworded)',
    `page_start`         INT UNSIGNED    DEFAULT NULL COMMENT 'Page reference in the original manual',
    `page_end`           INT UNSIGNED    DEFAULT NULL,
    `sort_order`         INT             NOT NULL DEFAULT 0 COMMENT 'Document order within the parent chapter',
    `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_hr_section_document` FOREIGN KEY (`policy_document_id`) REFERENCES `hr_policy_documents`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hr_section_parent`   FOREIGN KEY (`parent_id`) REFERENCES `hr_policy_sections`(`id`) ON DELETE CASCADE,
    INDEX `idx_hr_section_doc_order` (`policy_document_id`, `sort_order`),
    INDEX `idx_hr_section_parent` (`parent_id`),
    INDEX `idx_hr_section_number` (`section_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Policy section tree (search + navigation; official wording preserved)';

-- ----------------------------------------------------------------------------
-- 3. hr_policy_acknowledgements — per-employee read receipts
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hr_policy_acknowledgements` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `policy_document_id` BIGINT UNSIGNED NOT NULL,
    `user_id`            INT            NOT NULL,
    `employee_id`        INT            DEFAULT NULL COMMENT 'employees.id (internal PK — NOT the varchar staff number users.employee_id)',
    `acknowledged_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip_address`         VARCHAR(45)     DEFAULT NULL COMMENT 'Captured where appropriate (audit context)',
    `user_agent`         VARCHAR(512)    DEFAULT NULL,
    `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_hr_policy_ack` (`policy_document_id`, `user_id`),
    CONSTRAINT `fk_hr_ack_document` FOREIGN KEY (`policy_document_id`) REFERENCES `hr_policy_documents`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hr_ack_user`     FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hr_ack_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE SET NULL,
    INDEX `idx_hr_ack_user` (`user_id`, `acknowledged_at`),
    INDEX `idx_hr_ack_time` (`acknowledged_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Employee acknowledgements of a policy version (compliance view)';

-- ----------------------------------------------------------------------------
-- 4. hr_policy_bookmarks — per-user bookmarks (never modifies official policy)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hr_policy_bookmarks` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT             NOT NULL,
    `section_id` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_hr_policy_bookmark` (`user_id`, `section_id`),
    CONSTRAINT `fk_hr_bookmark_user`    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hr_bookmark_section` FOREIGN KEY (`section_id`) REFERENCES `hr_policy_sections`(`id`) ON DELETE CASCADE,
    INDEX `idx_hr_bookmark_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='User-specific bookmarks of policy sections';

-- ----------------------------------------------------------------------------
-- 5. hr_policy_recent_views — per-user recently viewed sections (pruned)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hr_policy_recent_views` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT             NOT NULL,
    `section_id` BIGINT UNSIGNED NOT NULL,
    `viewed_at`  DATETIME        NOT NULL,
    UNIQUE KEY `uq_hr_policy_recent` (`user_id`, `section_id`),
    CONSTRAINT `fk_hr_recent_user`    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hr_recent_section` FOREIGN KEY (`section_id`) REFERENCES `hr_policy_sections`(`id`) ON DELETE CASCADE,
    INDEX `idx_hr_recent_user` (`user_id`, `viewed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-user recently viewed policy sections (bounded list)';

-- ----------------------------------------------------------------------------
-- 6. RBAC seeds
--    view + acknowledge → every existing role (all employees).
--    manage + publish   → hr_manager / super_admin only.
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted)
SELECT DISTINCT rp.role, 'hr_policies', 'view', 1
FROM role_permissions rp
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

INSERT INTO role_permissions (role, module, action, is_granted)
SELECT DISTINCT rp.role, 'hr_policies', 'acknowledge', 1
FROM role_permissions rp
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('hr_manager',  'hr_policies', 'manage',  1),
('hr_manager',  'hr_policies', 'publish', 1),
('super_admin', 'hr_policies', 'manage',  1),
('super_admin', 'hr_policies', 'publish', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);