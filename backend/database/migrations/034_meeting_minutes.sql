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

CREATE INDEX idx_minutes_status ON meeting_minutes(status);
CREATE INDEX idx_minutes_prepared_by ON meeting_minutes(prepared_by);
CREATE INDEX idx_minutes_published_by ON meeting_minutes(published_by);
CREATE INDEX idx_minutes_created_at ON meeting_minutes(created_at);

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

CREATE INDEX idx_agenda_minutes ON meeting_minutes_agenda_items(minutes_id);

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

CREATE INDEX idx_decisions_minutes ON meeting_minutes_decisions(minutes_id);
CREATE INDEX idx_decisions_due_date ON meeting_minutes_decisions(due_date);
CREATE INDEX idx_decisions_status ON meeting_minutes_decisions(status);
CREATE INDEX idx_decisions_responsible ON meeting_minutes_decisions(responsible_id);

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

CREATE INDEX idx_actions_minutes ON meeting_minutes_action_items(minutes_id);
CREATE INDEX idx_actions_assigned_to ON meeting_minutes_action_items(assigned_to);
CREATE INDEX idx_actions_due_date ON meeting_minutes_action_items(due_date);
CREATE INDEX idx_actions_status ON meeting_minutes_action_items(status);

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

CREATE INDEX idx_aob_minutes ON meeting_minutes_aob_items(minutes_id);

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