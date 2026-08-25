-- 024_notification_preferences.sql
-- ------------------------------------------------------------------
-- Per-user notification preferences.
--
-- Defaults are organisation-managed: rows may be absent (treated as
-- enabled) and an explicit row records the employee's own choice.
-- `reminders_mandated` mirrors the org policy switch: when the
-- organisation marks attendance reminders as mandatory, employee
-- opt-out only silences non-mandatory channels (see docs).
-- Run: php backend/database/run_migration.php 024_notification_preferences.sql
-- ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `notification_preferences` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id`             INT NOT NULL,
    `push_enabled`        TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Web Push attendance reminders',
    `sms_enabled`         TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'SMS attendance reminders',
    `email_enabled`       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Future channel - reserved',
    `reminders_mandated`  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Org policy: cannot be changed by employee',
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY `uq_pref_user` (`user_id`),
    CONSTRAINT `fk_pref_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
