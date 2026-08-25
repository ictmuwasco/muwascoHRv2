-- Meeting Invitations Table
-- Tracks invited employees, their response status, and attendance.
-- Supports two invitation types: HR-invited (from creation) and QR-checkin
-- (walk-in attendees who scanned the meeting QR code).
-- Place: backend/database/migrations/017_create_meeting_invitations_table.sql

CREATE TABLE IF NOT EXISTS meeting_invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meeting_id INT NOT NULL COMMENT 'FK to meetings.id',
    employee_id INT COMMENT 'FK to employees.id — NULL allowed for walk-in attendees not linked to an employee record',
    invited_by INT COMMENT 'User ID of the person who sent the invitation (FK to users.id)',
    invited_at DATETIME COMMENT 'Timestamp when the invitation was sent',
    invitation_type ENUM('hr_invited','qr_checkin') DEFAULT 'hr_invited' COMMENT 'How the attendee was added',
    response_status ENUM('pending','accepted','declined','tentative') DEFAULT 'pending' COMMENT 'Employee response to the invitation',
    responded_at DATETIME NULL COMMENT 'Timestamp when the employee responded',
    attendance_status ENUM('present','absent','excused','not_marked') DEFAULT 'not_marked' COMMENT 'Actual attendance recorded',
    attendance_marked_at DATETIME NULL COMMENT 'Timestamp when attendance was marked',
    attendance_marked_by INT COMMENT 'User ID who marked the attendance (usually the employee self via QR or HR)',
    notes TEXT COMMENT 'Optional notes (e.g., reason for declining)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_invitations_meeting
        FOREIGN KEY (meeting_id) REFERENCES meetings(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_invitations_employee
        FOREIGN KEY (employee_id) REFERENCES employees(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_invitations_invited_by
        FOREIGN KEY (invited_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_invitations_attendance_marked_by
        FOREIGN KEY (attendance_marked_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,

    -- One employee can only be invited once per meeting
    UNIQUE KEY uk_meeting_employee (meeting_id, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexes for query performance
CREATE INDEX idx_invitations_meeting ON meeting_invitations(meeting_id);
CREATE INDEX idx_invitations_employee ON meeting_invitations(employee_id);
CREATE INDEX idx_invitations_response ON meeting_invitations(response_status);
CREATE INDEX idx_invitations_attendance ON meeting_invitations(attendance_status);
CREATE INDEX idx_invitations_type ON meeting_invitations(invitation_type);
CREATE INDEX idx_invitations_meeting_response ON meeting_invitations(meeting_id, response_status);
CREATE INDEX idx_invitations_meeting_attendance ON meeting_invitations(meeting_id, attendance_status);
