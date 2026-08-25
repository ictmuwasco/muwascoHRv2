-- Leave Application Documents Table
-- Stores supporting documents for leave applications
-- Place: backend/database/migrations/011_leave_application_documents.sql

CREATE TABLE IF NOT EXISTS leave_application_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    leave_application_id INT NOT NULL COMMENT 'Reference to leave_applications.id',
    document_type VARCHAR(50) NOT NULL COMMENT 'Controlled document type',
    original_filename VARCHAR(255) NOT NULL COMMENT 'Original uploaded filename',
    stored_filename VARCHAR(255) NOT NULL COMMENT 'Secure server-side filename',
    file_path VARCHAR(500) NOT NULL COMMENT 'Full path to stored file',
    mime_type VARCHAR(100) NOT NULL COMMENT 'File MIME type',
    file_size BIGINT NOT NULL COMMENT 'File size in bytes',
    uploaded_by INT NOT NULL COMMENT 'users.id who uploaded',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign key to leave_applications
    CONSTRAINT fk_leave_doc_application
        FOREIGN KEY (leave_application_id)
        REFERENCES leave_applications(id)
        ON DELETE CASCADE,

    -- Foreign key to users
    CONSTRAINT fk_leave_doc_uploader
        FOREIGN KEY (uploaded_by)
        REFERENCES users(id)
        ON DELETE RESTRICT,

    -- Index for faster lookups
    INDEX idx_leave_application_id (leave_application_id),
    INDEX idx_uploaded_by (uploaded_by),
    INDEX idx_document_type (document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;