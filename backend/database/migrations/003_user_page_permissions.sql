-- User Page Permissions Table for Hybrid Authorization System
-- This table stores user-specific permission overrides (allow/deny)
-- Only overrides are stored here, not role permissions

CREATE TABLE IF NOT EXISTS `user_page_permissions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `page_id` VARCHAR(100) NOT NULL,
    `permission_type` ENUM('allow', 'deny') NOT NULL,
    `granted_by` INT DEFAULT NULL,
    `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `notes` TEXT DEFAULT NULL,
    UNIQUE KEY `uq_user_page` (`user_id`, `page_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_page_id` (`page_id`),
    INDEX `idx_permission_type` (`permission_type`),
    INDEX `idx_granted_by` (`granted_by`),
    INDEX `idx_active` (`active`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`granted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

