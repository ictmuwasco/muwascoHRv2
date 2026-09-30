-- ============================================================================
-- 094: Generalise the notification queue for EVENT-driven notifications.
--
-- Why: 025_notification_logs.sql deduplicates on
--   UNIQUE (user_id, business_date, notification_type, channel, stage)
-- which is correct for the SCHEDULED reminders it was built for, but
-- collides for EVENT notifications (leave applied, appraisal status change,
-- meeting created). Two leave approvals for the same person on the same day
-- over the same channel would be the same tuple, so the second would be
-- silently swallowed as a "duplicate" and never sent.
--
-- Fix: a caller-supplied `dedupe_key` becomes the identity of a notification
-- ("leave:412:approved", "appraisal:88:completed:3"). The old tuple is
-- backfilled into it, so every row written before this migration keeps
-- exactly the dedupe behaviour it had - nothing re-sends, nothing changes.
--
-- Also repairs queue bookkeeping that was inert:
--   * `attempts` was inserted as 0 and NEVER incremented, so
--     reapStalePending()'s `attempts > 0` filter never matched and rows
--     stranded by a crashed process were never reaped.
--   * `idx_nl_pending` lets the worker find due rows without a table scan.
--
-- Idempotent: re-running is a no-op.
-- ============================================================================

ALTER TABLE `notification_logs`
    ADD COLUMN `dedupe_key` VARCHAR(191) NULL DEFAULT NULL
        COMMENT 'Caller-supplied identity, e.g. leave:412:approved'
        AFTER `stage`;

-- Backfill BEFORE the new unique key exists, so no row is momentarily
-- unprotected. Mirrors the old tuple exactly (channel is deliberately left
-- out because the unique key is per user+dedupe_key and callers include the
-- channel in the key when it matters).
UPDATE `notification_logs`
SET `dedupe_key` = CONCAT(
    DATE_FORMAT(`business_date`, '%Y-%m-%d'), '|',
    `notification_type`, '|',
    `stage`
)
WHERE `dedupe_key` IS NULL;

-- Anything that predates this migration must never end up NULL, or the
-- NOT NULL below would fail on a partially-filled table.
UPDATE `notification_logs`
SET `dedupe_key` = CONCAT('legacy:', `id`)
WHERE `dedupe_key` IS NULL OR `dedupe_key` = '';

-- A legacy row and a new event row can legitimately share a user, so the
-- key must be unique per user, not globally.
ALTER TABLE `notification_logs`
    DROP INDEX `uq_notification_once`,
    ADD UNIQUE KEY `uq_notification_once` (`user_id`, `dedupe_key`);

-- Worker lookups: "give me due rows of these types".
ALTER TABLE `notification_logs`
    ADD INDEX `idx_nl_pending` (`status`, `scheduled_at`),
    ADD INDEX `idx_nl_type_date` (`notification_type`, `business_date`);
