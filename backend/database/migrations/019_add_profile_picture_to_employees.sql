-- Add profile_image_url column to employees table
-- Stores the file path/name of the employee's profile picture
-- Place: backend/database/migrations/019_add_profile_picture_to_employees.sql

ALTER TABLE employees
    ADD COLUMN profile_image_url VARCHAR(500) NULL COMMENT 'Profile picture file path stored in public/uploads/profile_images/' AFTER employee_type;