-- Meetings Migration (Phase 017)
-- ==============================
-- Creates the `meetings` and `meeting_invitations` tables used by
-- MeetingController (/api/meetings*, /api/my-meetings) and the
-- Meetings Dashboard / My Meetings / Create Meeting pages.

CREATE TABLE IF NOT EXISTS meetings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    agenda TEXT DEFAULT NULL,
    meeting_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    location VARCHAR(255) NOT NULL,
    status ENUM('scheduled', 'ongoing', 'completed', 'cancelled') NOT NULL DEFAULT 'scheduled',
    created_by INT UNSIGNED DEFAULT NULL,
    attendance_token VARCHAR(64) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_meetings_date (meeting_date),
    KEY idx_meetings_status (status),
    KEY idx_meetings_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meeting_invitations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    meeting_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    invited_by INT UNSIGNED DEFAULT NULL,
    invited_at DATETIME DEFAULT NULL,
    invitation_type VARCHAR(30) NOT NULL DEFAULT 'required',
    response_status ENUM('pending', 'confirmed', 'declined') NOT NULL DEFAULT 'pending',
    responded_at DATETIME DEFAULT NULL,
    attendance_status ENUM('pending', 'present', 'absent') NOT NULL DEFAULT 'pending',
    attendance_marked_at DATETIME DEFAULT NULL,
    attendance_marked_by INT UNSIGNED DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_meeting_employee (meeting_id, employee_id),
    KEY idx_meeting_invitations_employee (employee_id),
    CONSTRAINT fk_meeting_invitations_meeting
        FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
