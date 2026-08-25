-- 023_push_subscriptions.sql
-- ------------------------------------------------------------------
-- Web Push subscriptions (one row per device/browser).
--
-- An employee may register multiple devices (Android phone, laptop,
-- tablet) - each browser PushSubscription endpoint gets its own row.
-- The raw endpoint URL is stored for sending; its SHA-256 hash is
-- UNIQUE so re-subscribing the same browser upserts instead of
-- duplicating. Revoked rows are kept for audit and cleaned up by the
-- reminder job when push endpoints answer 410 Gone.
-- Run: php backend/database/run_migration.php 023_push_subscriptions.sql
-- ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT NOT NULL COMMENT 'Authenticated user (users.id)',
    `endpoint_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 of endpoint URL (unique upsert key)',
    `endpoint`     TEXT NOT NULL COMMENT 'Push service endpoint URL',
    `p256dh_key`   TEXT NOT NULL COMMENT 'Client public key (base64url)',
    `auth_key`     TEXT NOT NULL COMMENT 'Auth secret (base64url)',
    `device_name`  VARCHAR(120) DEFAULT NULL COMMENT 'Friendly device label supplied by the employee',
    `platform`     VARCHAR(60) DEFAULT NULL COMMENT 'Browser platform hint (android/windows/...)',
    `user_agent`   VARCHAR(500) DEFAULT NULL COMMENT 'User agent at registration time',
    `last_used_at` DATETIME DEFAULT NULL COMMENT 'Last successful send attempt',
    `revoked_at`   DATETIME DEFAULT NULL COMMENT 'Set when unsubscribed or endpoint invalid (410)',
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY `uq_push_endpoint_hash` (`endpoint_hash`),
    INDEX `idx_push_user_active` (`user_id`, `revoked_at`),
    CONSTRAINT `fk_push_sub_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
