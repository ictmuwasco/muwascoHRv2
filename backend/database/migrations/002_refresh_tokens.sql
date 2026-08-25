-- Refresh Tokens Table for JWT Authentication
-- This table stores refresh tokens for token rotation and revocation

CREATE TABLE IF NOT EXISTS `refresh_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `token_id` VARCHAR(64) NOT NULL,
    `user_id` INT NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `revoked_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_token_id` (`token_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_expires` (`expires_at`),
    INDEX `idx_revoked` (`revoked_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;