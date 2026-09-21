-- ============================================================================
-- 034_meeting_minutes.sql
-- ----------------------------------------------------------------------------
-- Professional Meeting Minutes Management for the Meetings module.
-- Fully additive / backward compatible: no existing tables or columns are
-- changed, only new tables + permission seed rows are added.
--
-- Structure (relational, mirrors the existing schema conventions):
--   meetings
--     └── meeting_minutes
--           ├── meeting_minutes_agenda_items
--           ├── meeting_minutes_decisions
--           ├── meeting_minutes_action_items
--           └── meeting_minutes_aob_items
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + idempotent permission inserts.
-- Place: backend/database/migrations/034_meeting_minutes.sql
-- Apply: php backend/database/run_migration_034.php
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Index helper (idempotent)
-- ----------------------------------------------------------------------------
-- The tables below are created with CREATE TABLE IF NOT EXISTS, so on a
-- database that already contains them (e.g. built from 0000_baseline_schema.sql,
-- which ships every meeting_minutes_* table *with* its indexes) the bare
-- CREATE INDEX statements that used to follow would abort the migration with
-- "Duplicate key name 'idx_minutes_status'". Route every index through this
-- helper, which only issues CREATE INDEX when information_schema says the
-- index is missing — safe on a fresh database and on a re-run.
DROP PROCEDURE IF EXISTS mm_create_index_if_missing;

CREATE PROCEDURE mm_create_index_if_missing(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_cols  VARCHAR(255)
)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = p_table
      AND INDEX_NAME   = p_index;

    IF v_exists = 0 THEN
        SET @sql = CONCAT('CREATE INDEX ', p_index, ' ON ', p_table, ' (', p_cols, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END;

CREATE TABLE IF NOT EXISTS meeting_minutes (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    meeting_id          INT NOT NULL COMMENT 'FK to meetings.id (one minutes set per meeting)',
    reference_number    VARCHAR(50) NOT NULL COMMENT 'Official minutes reference, e.g. MMS-{meeting_id}-{year}',
    meeting_date        DATE NULL COMMENT 'Snapshot of meeting.meeting_date at creation',
    start_time          TIME NULL COMMENT 'Snapshot of meeting.start_time',
    end_time            TIME NULL COMMENT 'Snapshot of meeting.end_time',
    venue               VARCHAR(255) NULL COMMENT 'Snapshot of meeting.location',
    chairperson_id      INT NULL COMMENT 'FK to employees.id',
    secretary_id        INT NULL COMMENT 'FK to employees.id',
    status              ENUM('draft','published') NOT NULL DEFAULT 'draft' COMMENT 'Lifecycle: draft -> published (immutable until reopened)',
    version             INT NOT NULL DEFAULT 1 COMMENT 'Version number; bumped on reopen/amend',
    amendment_reason    TEXT NULL COMMENT 'Why the minutes were reopened/amended',
    aob                 TEXT NULL COMMENT 'Any-other-business catch-all text',
    next_meeting_date   DATE NULL,
    next_meeting_time   TIME NULL,
    next_meeting_venue  VARCHAR(255) NULL,
    next_meeting_notes  TEXT NULL,
    prepared_by         INT NULL COMMENT 'FK to users.id (minutes author)',
    prepared_at         DATETIME NULL,
    reviewed_by         INT NULL COMMENT 'FK to users.id',
    reviewed_at         DATETIME NULL,
    approved_by         INT NULL COMMENT 'FK to users.id',
    approved_at         DATETIME NULL,
    published_by        INT NULL COMMENT 'FK to users.id',
    published_at        DATETIME NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_minutes_meeting
        FOREIGN KEY (meeting_id) REFERENCES meetings(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_minutes_chairperson
        FOREIGN KEY (chairperson_id) REFERENCES employees(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_minutes_secretary
        FOREIGN KEY (secretary_id) REFERENCES employees(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_minutes_prepared_by
        FOREIGN KEY (prepared_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_minutes_published_by
        FOREIGN KEY (published_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,

    UNIQUE KEY uk_minutes_meeting (meeting_id),
    UNIQUE KEY uk_minutes_reference (reference_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL mm_create_index_if_missing('meeting_minutes', 'idx_minutes_status', 'status');
CALL mm_create_index_if_missing('meeting_minutes', 'idx_minutes_prepared_by', 'prepared_by');
CALL mm_create_index_if_missing('meeting_minutes', 'idx_minutes_published_by', 'published_by');
CALL mm_create_index_if_missing('meeting_minutes', 'idx_minutes_created_at', 'created_at');

CREATE TABLE IF NOT EXISTS meeting_minutes_agenda_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    minutes_id      INT NOT NULL COMMENT 'FK to meeting_minutes.id',
    position        INT NOT NULL DEFAULT 1 COMMENT 'Agenda ordering (1-based)',
    agenda_number   VARCHAR(20) NULL COMMENT 'e.g. 1.0, 2.1',
    title           VARCHAR(255) NOT NULL,
    presenter_id    INT NULL COMMENT 'FK to employees.id',
    discussion      TEXT NULL,
    decision        TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_agenda_minutes
        FOREIGN KEY (minutes_id) REFERENCES meeting_minutes(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_agenda_presenter
        FOREIGN KEY (presenter_id) REFERENCES employees(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL mm_create_index_if_missing('meeting_minutes_agenda_items', 'idx_agenda_minutes', 'minutes_id');

CREATE TABLE IF NOT EXISTS meeting_minutes_decisions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    minutes_id      INT NOT NULL COMMENT 'FK to meeting_minutes.id',
    decision_number VARCHAR(20) NULL COMMENT 'e.g. D-01',
    resolution      TEXT NOT NULL,
    responsible_id  INT NULL COMMENT 'FK to employees.id',
    department_id   INT NULL COMMENT 'FK to departments.id',
    due_date        DATE NULL,
    status          ENUM('pending','in_progress','completed','deferred','cancelled') NOT NULL DEFAULT 'pending',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_decisions_minutes
        FOREIGN KEY (minutes_id) REFERENCES meeting_minutes(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_decisions_responsible
        FOREIGN KEY (responsible_id) REFERENCES employees(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_decisions_department
        FOREIGN KEY (department_id) REFERENCES departments(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL mm_create_index_if_missing('meeting_minutes_decisions', 'idx_decisions_minutes', 'minutes_id');
CALL mm_create_index_if_missing('meeting_minutes_decisions', 'idx_decisions_due_date', 'due_date');
CALL mm_create_index_if_missing('meeting_minutes_decisions', 'idx_decisions_status', 'status');
CALL mm_create_index_if_missing('meeting_minutes_decisions', 'idx_decisions_responsible', 'responsible_id');

CREATE TABLE IF NOT EXISTS meeting_minutes_action_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    minutes_id      INT NOT NULL COMMENT 'FK to meeting_minutes.id',
    action          TEXT NOT NULL,
    assigned_to     INT NULL COMMENT 'FK to employees.id',
    department_id   INT NULL COMMENT 'FK to departments.id',
    due_date        DATE NULL,
    priority        ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    status          ENUM('pending','in_progress','completed','overdue','deferred','cancelled') NOT NULL DEFAULT 'pending',
    remarks         TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_actions_minutes
        FOREIGN KEY (minutes_id) REFERENCES meeting_minutes(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_actions_assigned_to
        FOREIGN KEY (assigned_to) REFERENCES employees(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_actions_department
        FOREIGN KEY (department_id) REFERENCES departments(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL mm_create_index_if_missing('meeting_minutes_action_items', 'idx_actions_minutes', 'minutes_id');
CALL mm_create_index_if_missing('meeting_minutes_action_items', 'idx_actions_assigned_to', 'assigned_to');
CALL mm_create_index_if_missing('meeting_minutes_action_items', 'idx_actions_due_date', 'due_date');
CALL mm_create_index_if_missing('meeting_minutes_action_items', 'idx_actions_status', 'status');

CREATE TABLE IF NOT EXISTS meeting_minutes_aob_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    minutes_id      INT NOT NULL COMMENT 'FK to meeting_minutes.id',
    item            VARCHAR(255) NOT NULL,
    discussion      TEXT NULL,
    decision        TEXT NULL,
    action          TEXT NULL,
    responsible_id  INT NULL COMMENT 'FK to employees.id',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_aob_minutes
        FOREIGN KEY (minutes_id) REFERENCES meeting_minutes(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_aob_responsible
        FOREIGN KEY (responsible_id) REFERENCES employees(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL mm_create_index_if_missing('meeting_minutes_aob_items', 'idx_aob_minutes', 'minutes_id');

-- Cleanup the temporary helper procedure
DROP PROCEDURE IF EXISTS mm_create_index_if_missing;

-- RBAC seed rows (idempotent) - Hybrid permission overrides still apply on
-- top of these via user_page_permissions.
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
    ('super_admin', 'meetings', 'minutes.create', 1),
    ('super_admin', 'meetings', 'minutes.view',   1),
    ('super_admin', 'meetings', 'minutes.update', 1),
    ('super_admin', 'meetings', 'minutes.publish',1),
    ('super_admin', 'meetings', 'minutes.amend',  1),
    ('hr_manager',  'meetings', 'minutes.create', 1),
    ('hr_manager',  'meetings', 'minutes.view',   1),
    ('hr_manager',  'meetings', 'minutes.update', 1),
    ('hr_manager',  'meetings', 'minutes.publish',1),
    ('hr_manager',  'meetings', 'minutes.amend',  1),
    ('dept_head',   'meetings', 'minutes.view',   1),
    ('section_head','meetings', 'minutes.view',   1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);