-- 029_create_workplan_logs_table.sql
-- ====================================================================
-- Creates the `workplan_logs` audit trail table dedicated to
-- progress updates and status changes on workplan objectives.
--
-- Unlike the general-purpose `audit_logs` table (which captures
-- every module action), `workplan_logs` stores the chronological
-- progression of a single objective: each progress_percent update,
-- status change and evidence upload is recorded here so the history
-- is always queryable in order for a single objective.
--
-- Apply (one time):  mysql -u root -p muwasco < this file
-- ====================================================================

CREATE TABLE IF NOT EXISTS workplan_logs (
    id                 INT(11) NOT NULL AUTO_INCREMENT,
    objective_id       INT(11) NOT NULL,
    user_id            INT(10) UNSIGNED NULL,
    action_type        VARCHAR(50) NOT NULL COMMENT 'progress_update|status_change|evidence_upload|objective_update',
    old_values         JSON NULL COMMENT 'Snapshot of changed fields before the update',
    new_values         JSON NULL COMMENT 'Snapshot of changed fields after the update',
    progress_percent   TINYINT(3) UNSIGNED NULL,
    status             VARCHAR(50) NULL,
    evidence_path      TEXT NULL,
    description        TEXT NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_wpl_objective    (objective_id),
    INDEX idx_wpl_user         (user_id),
    INDEX idx_wpl_action_type  (action_type),
    INDEX idx_wpl_created_at   (created_at),

    CONSTRAINT fk_wpl_objective
        FOREIGN KEY (objective_id)
        REFERENCES workplan_objectives(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
