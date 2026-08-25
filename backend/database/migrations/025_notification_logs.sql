-- 025_notification_logs.sql
-- ------------------------------------------------------------------
-- Notification delivery log / idempotency ledger.
--
-- UNIQUE (user_id, business_date, notification_type, channel, stage)
-- is THE duplicate-prevention guarantee: two cron runs, queue retries
-- or double clicks can never produce two identical notifications -
-- the second INSERT violates the unique key and is rejected.
-- business_date is the organisation-timezone attendance day the
-- notification belongs to (NOT the UTC send timestamp).
-- Run: php backend/database/run_migration.php 025_notification_logs.sql
-- ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `notification_logs` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id`             INT NOT NULL COMMENT 'Recipient user (users.id)',
    `employee_id`         INT UNSIGNED DEFAULT NULL COMMENT 'Denormalised employees.id for reporting',
    `notification_type`   VARCHAR(60)  NOT NULL DEFAULT 'attendance_clock_in_reminder',
    `channel`             VARCHAR(20)  NOT NULL COMMENT 'web_push | sms | email | in_app ...',
    `stage`               VARCHAR(30)  NOT NULL DEFAULT 'reminder_1' COMMENT 'reminder_1 | sms_fallback | reminder_2 ...',
    `business_date`       DATE NOT NULL COMMENT 'Org-timezone attendance day',
    `status`              VARCHAR(30)  NOT NULL DEFAULT 'pending' COMMENT 'pending|sent|failed|failed_permanent|retrying|skipped|revoked',
    `recipient`           VARCHAR(200) DEFAULT NULL COMMENT 'E.164 phone (SMS) or endpoint host+hash prefix (push)',
    `provider_message_id` VARCHAR(100) DEFAULT NULL COMMENT 'SMS provider message id / request id echo',
    `failure_reason`      VARCHAR(500) DEFAULT NULL,
    `attempts`            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `scheduled_at`        DATETIME DEFAULT NULL,
    `sent_at`             DATETIME DEFAULT NULL,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY `uq_notification_once` (`user_id`, `business_date`, `notification_type`, `channel`, `stage`),
    INDEX `idx_nl_business_date` (`business_date`),
    INDEX `idx_nl_status_date` (`status`, `business_date`),
    INDEX `idx_nl_user_date` (`user_id`, `business_date`),
    INDEX `idx_nl_provider_msg` (`provider_message_id`),
    CONSTRAINT `fk_nl_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
