-- 042a: Index leave_applications for approval-queue workload (E4).
--
-- The approval flow queries leave_applications by financial_year + status
-- (or by approved_by / approved_at for the review queue). The current
-- indexes cover (status, start_date, end_date) but not the queue-facing
-- pair. Adding this composite index makes the approve/reject pipeline
-- use a covering index instead of a filesort.
--
-- Idempotent: guards on information_schema.

SET @db = DATABASE();

-- ----------------------------------------------------------------
-- 1. Diagnostic: current index count on the target columns.
-- ----------------------------------------------------------------
SELECT '=== 042a DIAGNOSTIC ===' AS step;
SELECT
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'leave_applications'
        AND INDEX_NAME IN ('idx_leave_appr_fy_status','idx_leave_appr_approved')) AS target_indexes;

-- ----------------------------------------------------------------
-- 2. Index: (financial_year_id, status, approved_at) for the approval
--    queue on a financial year, ordered by last-approved-at.
-- ----------------------------------------------------------------
SET @idx_target = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'leave_applications'
       AND INDEX_NAME = 'idx_leave_appr_fy_status'
);
SET @sql = IF(@idx_target = 0,
    'CREATE INDEX idx_leave_appr_fy_status
       ON leave_applications (financial_year_id, status, approved_at)',
    'SELECT 1 AS idx_leave_appr_fy_status_already_exists');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------
-- 3. Index: (approved_by, approved_at) for the reviewer queue
--    (who still needs to review this HR/MD block).
-- ----------------------------------------------------------------
SET @idx_reviewer = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'leave_applications'
       AND INDEX_NAME = 'idx_leave_appr_approved'
);
SET @sql = IF(@idx_reviewer = 0,
    'CREATE INDEX idx_leave_appr_approved
       ON leave_applications (approved_by, approved_at)',
    'SELECT 1 AS idx_leave_appr_approved_already_exists');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------
-- 4. Verification: final state.
-- ----------------------------------------------------------------
SELECT '=== 042a COMPLETE ===' AS step;
SELECT
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_applications'
        AND INDEX_NAME IN ('idx_leave_appr_fy_status','idx_leave_appr_approved')) AS target_indexes_created;

-- ===================================================================
