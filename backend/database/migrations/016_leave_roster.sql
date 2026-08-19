-- Leave Roster (annual leave planning) Migration (Phase 016)
-- ==========================================================
-- Creates the `leave_roster` table used by LeaveRosterController
-- (/api/leave/roster*) and the Leave Roster / Leave Oversight pages.
-- One roster entry per employee per financial year.

CREATE TABLE IF NOT EXISTS leave_roster (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NOT NULL,
    financial_year_id INT UNSIGNED NOT NULL,
    scheduled_month VARCHAR(20) NOT NULL,
    scheduled_year SMALLINT NOT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_leave_roster_employee_fy (employee_id, financial_year_id),
    KEY idx_leave_roster_month (scheduled_month, scheduled_year),
    KEY idx_leave_roster_fy (financial_year_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
