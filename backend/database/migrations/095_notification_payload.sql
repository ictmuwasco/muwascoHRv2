-- ============================================================================
-- 095: Carry the rendered payload on the queue row.
--
-- Why: 094 turned notification_logs into an event queue, but a queue row only
-- recorded WHO and WHAT TYPE - not the message. A worker that drains the queue
-- therefore has no way to render the email, and would have to re-derive the text
-- from the type alone, which is exactly the re-implementation that drifts.
--
-- The payload is stored already-rendered at dispatch time. That is deliberate:
-- the wording is captured where the business context exists (the leave service
-- knows the dates and the type; the worker does not), so "what the recipient was
-- told" is a permanent record rather than something re-derived later.
--
-- Only non-sensitive display content is stored. Secrets never belong here.
--
-- Idempotent: re-running is a no-op.
-- ============================================================================

ALTER TABLE `notification_logs`
    ADD COLUMN `payload` TEXT NULL DEFAULT NULL
        COMMENT 'JSON: {title, body, link, type} captured at dispatch time'
        AFTER `dedupe_key`;
