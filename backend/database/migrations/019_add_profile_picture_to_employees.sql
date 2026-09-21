-- Add profile_image_url column to employees table
-- Stores the file path/name of the employee's profile picture
-- Place: backend/database/migrations/019_add_profile_picture_to_employees.sql
--
-- IDEMPOTENT: the column is already declared by 0000_baseline_schema.sql, so the
-- step is guarded against information_schema (safe on fresh + legacy databases).

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'
               AND COLUMN_NAME = 'profile_image_url');
SET @sql := IF(@col = 0,
    'ALTER TABLE employees ADD COLUMN profile_image_url VARCHAR(500) NULL COMMENT ''Profile picture file path stored in public/uploads/profile_images/'' AFTER employee_type',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;