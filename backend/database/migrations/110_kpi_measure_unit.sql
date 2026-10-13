-- ============================================================================
-- 110_kpi_measure_unit.sql
--
-- WHY
--   A KPI (performance_indicators) describes HOW something is measured and what
--   it is worth: measure name, an optional unit (Number, Percentage, ...), the
--   max marks, the people who own it and the activities it is linked to. The
--   workplan no longer carries the measure/unit (that now lives on the KPI), so
--   the KPI needs its own unit field.
--
-- WHAT
--   Adds performance_indicators.measure_unit varchar(50) NULL. NULL/empty means
--   "no unit" (a dimensionless score). Written idempotently, exactly like
--   backend/database/migrations/103_additive_query_indexes.sql.
--
-- PURELY ADDITIVE. Drops nothing, mutates no data, removes no column. Existing
-- KPIs keep NULL until edited — this migration cannot lose data.
--
-- NUMBERING: 109 is backfill_users_role_from_employee_type; 110 follows it.
--
-- ENGINE: MariaDB 10.4 (identical to CI and production).
-- ROLLBACK: ALTER TABLE performance_indicators DROP COLUMN measure_unit
--
-- NOTE: a "--" comment line must not contain a semicolon or an odd number of
-- apostrophes - the migration runner splits statements naively.
-- ============================================================================

SET @s := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'performance_indicators'
       AND COLUMN_NAME = 'measure_unit') = 0,
  'ALTER TABLE `performance_indicators` ADD COLUMN `measure_unit` VARCHAR(50) NULL DEFAULT NULL AFTER `name`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
