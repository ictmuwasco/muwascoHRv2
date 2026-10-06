-- ===========================================================================
-- 107_email_channel_default_on.sql
--
-- WHY
--   Every in-house (in-app) notification is now ALSO delivered by email.
--   The event platform (NotificationDispatcher) gates the email channel on
--   notification_preferences.email_enabled, and that column shipped as
--     `email_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Future channel - reserved'
--   (migration 024). The preferences API/UI NEVER exposed an email toggle -
--   PUT /api/notification-preferences accepts push_enabled and sms_enabled
--   only - so no user has ever made a deliberate choice about this column.
--   Every existing 0 is therefore an artifact of the column default, and with
--   the old default the mirror would be silently skipped ('Recipient has
--   email notifications switched off') for every employee who had ever saved
--   any preference: the classic "looks configured, never sends" failure.
--
-- WHAT
--   1. ALTER the column default 0 -> 1, so rows created by
--      NotificationPreferenceRepository::save() (which does not list the
--      column) inherit email ON.
--   2. Flip existing 0 rows to 1, because they are defaults, not choices.
--
-- OPT-OUT
--   An explicit 0 remains the opt-out and is still respected by the
--   dispatcher. Until a toggle exists in the UI/API, opting out is:
--     UPDATE notification_preferences SET email_enabled = 0 WHERE user_id = ?;
--
-- IDEMPOTENT: both statements are re-runnable no-ops once applied.
-- NUMBERING: 106 is document access OTP; 107 follows it.
-- ===========================================================================

ALTER TABLE `notification_preferences`
    ALTER COLUMN `email_enabled` SET DEFAULT 1;

UPDATE `notification_preferences`
SET `email_enabled` = 1
WHERE `email_enabled` = 0;
