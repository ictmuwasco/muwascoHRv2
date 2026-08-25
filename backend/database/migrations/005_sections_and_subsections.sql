-- Sections and Subsections Tables
-- The employees table has section_id and subsection_id foreign keys, and
-- the codebase joins on `sections` and `subsections` in several repositories.
-- This migration creates both tables with a basic schema that satisfies
-- the foreign keys and the simple SELECTs/LEFT JOINs in the existing code.
--
-- Place: backend/database/migrations/005_sections_and_subsections.sql

CREATE TABLE IF NOT EXISTS sections (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id BIGINT(20) UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    head_employee_id BIGINT(20) UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_sections_department (department_id),
    INDEX idx_sections_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subsections (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_id BIGINT(20) UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    head_employee_id BIGINT(20) UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_subsections_section (section_id),
    INDEX idx_subsections_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
