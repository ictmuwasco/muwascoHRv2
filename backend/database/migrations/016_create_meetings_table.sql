-- Meetings Table
-- Stores meeting/schedule records created by HR users.
-- Place: backend/database/migrations/016_create_meetings_table.sql

CREATE TABLE IF NOT EXISTS meetings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL COMMENT 'Meeting title / subject',
    description TEXT COMMENT 'Detailed description of the meeting',
    agenda TEXT COMMENT 'Meeting agenda items',
    meeting_date DATE NOT NULL COMMENT 'Date of the meeting',
    start_time TIME NOT NULL COMMENT 'Scheduled start time',
    end_time TIME NOT NULL COMMENT 'Scheduled end time',
    location VARCHAR(255) COMMENT 'Meeting location or virtual link',
    status ENUM('scheduled','ongoing','completed','cancelled') DEFAULT 'scheduled' COMMENT 'Meeting lifecycle status',
    created_by INT NOT NULL COMMENT 'User ID who created the meeting (FK to users.id)',
    attendance_token VARCHAR(64) COMMENT 'Unique token for QR-code attendance check-in',
    notification_sent_at DATETIME NULL COMMENT 'Timestamp when email notifications were sent (deferred feature)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_meetings_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexes for query performance
CREATE INDEX idx_meetings_date ON meetings(meeting_date);
CREATE INDEX idx_meetings_status ON meetings(status);
CREATE INDEX idx_meetings_created_by ON meetings(created_by);
CREATE INDEX idx_meetings_attendance_token ON meetings(attendance_token);
CREATE INDEX idx_meetings_date_status ON meetings(meeting_date, status);
