-- ============================================================================
-- 042_performance_instrumentation.sql
--
-- Phase 2 performance instrumentation: extend performance_events with the
-- per-phase breakdown columns written by ErrorTrackerService::recordPerformance
-- (PerfTiming report). Guarded ALTERs (information_schema check + PREPARE/
-- EXECUTE) so the migration is idempotent and safe on MySQL 5.7/8.0.
--
-- All columns are nullable and metadata-only: durations (ms) and counts.
-- No SQL text, tokens, prompts, headers or bodies are ever stored.
-- ============================================================================

-- query_count: total SQL executions on the shared connection this request
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME   = 'performance_events'
                     AND COLUMN_NAME  = 'query_count');
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `performance_events` ADD COLUMN `query_count` INT UNSIGNED NULL AFTER `memory_kb`',
    'SELECT ''performance_events.query_count already present''');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- query_ms: accumulated SQL execution time (ms)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME   = 'performance_events'
                     AND COLUMN_NAME  = 'query_ms');
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `performance_events` ADD COLUMN `query_ms` INT UNSIGNED NULL AFTER `query_count`',
    'SELECT ''performance_events.query_ms already present''');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- max_query_ms: slowest single query this request
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME   = 'performance_events'
                     AND COLUMN_NAME  = 'max_query_ms');
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `performance_events` ADD COLUMN `max_query_ms` INT UNSIGNED NULL AFTER `query_ms`',
    'SELECT ''performance_events.max_query_ms already present''');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- auth_ms: security + authentication gate (SecurityMiddleware + AuthenticationMiddleware)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME   = 'performance_events'
                     AND COLUMN_NAME  = 'auth_ms');
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `performance_events` ADD COLUMN `auth_ms` INT UNSIGNED NULL AFTER `max_query_ms`',
    'SELECT ''performance_events.auth_ms already present''');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- authorization_ms: accumulated permission resolution (AuthorizationService)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME   = 'performance_events'
                     AND COLUMN_NAME  = 'authorization_ms');
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `performance_events` ADD COLUMN `authorization_ms` INT UNSIGNED NULL AFTER `auth_ms`',
    'SELECT ''performance_events.authorization_ms already present''');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- controller_ms: controller work (marker to shutdown; includes serialization)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME   = 'performance_events'
                     AND COLUMN_NAME  = 'controller_ms');
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `performance_events` ADD COLUMN `controller_ms` INT UNSIGNED NULL AFTER `authorization_ms`',
    'SELECT ''performance_events.controller_ms already present''');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- serialization_ms: json_encode time in the ApiResponse envelope
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME   = 'performance_events'
                     AND COLUMN_NAME  = 'serialization_ms');
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `performance_events` ADD COLUMN `serialization_ms` INT UNSIGNED NULL AFTER `controller_ms`',
    'SELECT ''performance_events.serialization_ms already present''');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ai_provider_ms: accumulated AI provider completion latency
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME   = 'performance_events'
                     AND COLUMN_NAME  = 'ai_provider_ms');
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `performance_events` ADD COLUMN `ai_provider_ms` INT UNSIGNED NULL AFTER `serialization_ms`',
    'SELECT ''performance_events.ai_provider_ms already present''');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ai_provider_calls: number of provider attempts (incl. retries/fallbacks)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME   = 'performance_events'
                     AND COLUMN_NAME  = 'ai_provider_calls');
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `performance_events` ADD COLUMN `ai_provider_calls` SMALLINT UNSIGNED NULL AFTER `ai_provider_ms`',
    'SELECT ''performance_events.ai_provider_calls already present''');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ai_tool_calls: number of executed AI tool invocations this turn
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME   = 'performance_events'
                     AND COLUMN_NAME  = 'ai_tool_calls');
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `performance_events` ADD COLUMN `ai_tool_calls` SMALLINT UNSIGNED NULL AFTER `ai_provider_calls`',
    'SELECT ''performance_events.ai_tool_calls already present''');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- external_http_ms: outbound HTTP latency (AI transport, SMS, etc.)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME   = 'performance_events'
                     AND COLUMN_NAME  = 'external_http_ms');
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `performance_events` ADD COLUMN `external_http_ms` INT UNSIGNED NULL AFTER `ai_tool_calls`',
    'SELECT ''performance_events.external_http_ms already present''');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;