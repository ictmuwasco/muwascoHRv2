-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: muwasco
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `absent_deductions`
--

DROP TABLE IF EXISTS `absent_deductions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `absent_deductions` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `deduction_date` date NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `days_deducted` decimal(5,2) NOT NULL DEFAULT 1.00,
  `deducted_by` int(11) NOT NULL,
  `deducted_at` datetime NOT NULL,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `absent_exemptions`
--

DROP TABLE IF EXISTS `absent_exemptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `absent_exemptions` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `exemption_date` date NOT NULL,
  `reason` text DEFAULT NULL,
  `exempted_by` int(11) NOT NULL,
  `exempted_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `activities`
--

DROP TABLE IF EXISTS `activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activities` (
  `id` int(11) NOT NULL,
  `strategy_id` int(11) NOT NULL,
  `activity` text NOT NULL,
  `kpi` varchar(255) DEFAULT NULL,
  `target` varchar(255) DEFAULT NULL,
  `Y1` decimal(10,2) DEFAULT NULL,
  `Y2` decimal(10,2) DEFAULT NULL,
  `Y3` decimal(10,2) DEFAULT NULL,
  `Y4` decimal(10,2) DEFAULT NULL,
  `Y5` decimal(10,2) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ai_conversations`
--

DROP TABLE IF EXISTS `ai_conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_conversations` (
  `id` char(36) NOT NULL COMMENT 'Opaque conversation id (UUIDv4 generated server-side)',
  `user_id` int(11) NOT NULL COMMENT 'Owner — the ONLY user who may read/write this conversation',
  `title` varchar(120) DEFAULT NULL COMMENT 'Short label derived from the first user message',
  `message_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_message_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ai_conv_user` (`user_id`,`last_message_at`),
  CONSTRAINT `fk_ai_conv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='AI assistant chat threads — owner-scoped';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ai_feedback`
--

DROP TABLE IF EXISTS `ai_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_feedback` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `message_id` bigint(20) unsigned NOT NULL,
  `conversation_id` char(36) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT 'Must be the conversation owner (enforced again in the service layer)',
  `rating` enum('helpful','not_helpful') NOT NULL,
  `comment` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ai_feedback_message_user` (`message_id`,`user_id`),
  KEY `fk_ai_feedback_conv` (`conversation_id`),
  KEY `fk_ai_feedback_user` (`user_id`),
  KEY `idx_ai_feedback_rating` (`rating`,`created_at`),
  CONSTRAINT `fk_ai_feedback_conv` FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_feedback_msg` FOREIGN KEY (`message_id`) REFERENCES `ai_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_feedback_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Per-message AI feedback (one vote per user, re-voting updates)';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ai_messages`
--

DROP TABLE IF EXISTS `ai_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` char(36) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT 'Denormalized owner for cheap owner-scoped queries',
  `role` enum('user','assistant','system') NOT NULL,
  `content` mediumtext NOT NULL COMMENT 'Sanitized plain text; never HTML, never raw provider payloads',
  `sources` text DEFAULT NULL COMMENT 'JSON array of source chips, e.g. [{"type":"data","label":"..."}]',
  `tools_used` text DEFAULT NULL COMMENT 'JSON array of controlled tool names used for this turn',
  `provider` varchar(50) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `status` enum('ok','error') NOT NULL DEFAULT 'ok',
  `error_code` varchar(50) DEFAULT NULL COMMENT 'Sanitized failure code (e.g. TIMEOUT) — never provider internals',
  `latency_ms` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ai_msg_conv` (`conversation_id`,`id`),
  KEY `idx_ai_msg_user` (`user_id`,`id`),
  CONSTRAINT `fk_ai_msg_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_msg_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=83 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='AI chat turns — sanitized content only';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ai_prompt_versions`
--

DROP TABLE IF EXISTS `ai_prompt_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_prompt_versions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL COMMENT 'Logical prompt name, e.g. muwasco_hr_assistant',
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Exactly one active row per name is enforced by the service layer',
  `content` mediumtext NOT NULL COMMENT 'System prompt text (no secrets, no PII, no provider details)',
  `description` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ai_prompt_name_version` (`name`,`version`),
  KEY `idx_ai_prompt_active` (`name`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Versioned AI system prompts (the active row drives every chat)';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ai_tool_calls`
--

DROP TABLE IF EXISTS `ai_tool_calls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_tool_calls` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `message_id` bigint(20) unsigned NOT NULL,
  `conversation_id` char(36) NOT NULL,
  `user_id` int(11) NOT NULL,
  `tool_name` varchar(100) NOT NULL COMMENT 'Registered controlled-tool name — never free-form SQL',
  `arguments` text DEFAULT NULL COMMENT 'JSON of VALIDATED arguments (after authorization + scoping)',
  `result_status` enum('ok','denied','error') NOT NULL DEFAULT 'ok',
  `result_summary` varchar(500) DEFAULT NULL COMMENT 'Non-sensitive summary (counts / labels only)',
  `latency_ms` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_ai_tool_conv` (`conversation_id`),
  KEY `idx_ai_tool_msg` (`message_id`),
  KEY `idx_ai_tool_name` (`tool_name`,`created_at`),
  KEY `idx_ai_tool_user` (`user_id`,`created_at`),
  CONSTRAINT `fk_ai_tool_conv` FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_tool_msg` FOREIGN KEY (`message_id`) REFERENCES `ai_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_tool_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Controlled HR data tool invocations performed for AI turns';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ai_usage_logs`
--

DROP TABLE IF EXISTS `ai_usage_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_usage_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL COMMENT 'SET NULL so usage history survives user deletion',
  `conversation_id` char(36) DEFAULT NULL,
  `provider` varchar(50) NOT NULL COMMENT 'Driver label from config (local | nvidia_nim | openai_compatible)',
  `model` varchar(100) DEFAULT NULL,
  `status` varchar(30) NOT NULL COMMENT 'AiCompletionResult status (SUCCESS, TIMEOUT, ...)',
  `attempts` int(10) unsigned NOT NULL DEFAULT 1,
  `http_status` int(10) unsigned DEFAULT NULL,
  `prompt_chars` int(10) unsigned NOT NULL DEFAULT 0,
  `response_chars` int(10) unsigned NOT NULL DEFAULT 0,
  `error_code` varchar(50) DEFAULT NULL,
  `request_id` varchar(64) DEFAULT NULL COMMENT 'X-Request-ID correlation with audit/error tracking',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ai_usage_user` (`user_id`,`created_at`),
  KEY `idx_ai_usage_provider` (`provider`,`status`,`created_at`),
  KEY `idx_ai_usage_created` (`created_at`),
  CONSTRAINT `fk_ai_usage_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='AI completion telemetry — metadata only, never message content';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `application_errors`
--

DROP TABLE IF EXISTS `application_errors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `application_errors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `error_uuid` char(36) NOT NULL,
  `error_group_id` bigint(20) unsigned NOT NULL,
  `fingerprint` varchar(191) NOT NULL,
  `fingerprint_hash` char(64) NOT NULL,
  `request_id` varchar(64) DEFAULT NULL COMMENT 'Correlation id shared with audit_logs + response headers',
  `frontend_error_id` varchar(64) DEFAULT NULL COMMENT 'Browser-side generated id for client errors',
  `source` varchar(10) NOT NULL DEFAULT 'server' COMMENT 'server|client',
  `environment` varchar(20) NOT NULL DEFAULT 'production',
  `application_version` varchar(50) DEFAULT NULL,
  `git_commit` varchar(40) DEFAULT NULL,
  `severity` varchar(20) NOT NULL DEFAULT 'MEDIUM',
  `status` varchar(20) NOT NULL DEFAULT 'NEW',
  `category` varchar(50) NOT NULL DEFAULT 'SYSTEM_ERROR',
  `exception_class` varchar(191) DEFAULT NULL,
  `error_code` varchar(100) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `file` varchar(255) DEFAULT NULL,
  `line` int(10) unsigned DEFAULT NULL,
  `stack_trace` mediumtext DEFAULT NULL COMMENT 'RESTRICTED - requires system_errors:view_sensitive',
  `http_method` varchar(10) DEFAULT NULL,
  `endpoint` varchar(255) DEFAULT NULL,
  `route_name` varchar(100) DEFAULT NULL,
  `status_code` smallint(5) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `employee_id` int(10) unsigned DEFAULT NULL,
  `office_id` int(10) unsigned DEFAULT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `request_payload` mediumtext DEFAULT NULL COMMENT 'Sanitized JSON - RESTRICTED',
  `request_query` mediumtext DEFAULT NULL COMMENT 'Sanitized JSON - RESTRICTED',
  `request_headers` text DEFAULT NULL COMMENT 'Sanitized subset - RESTRICTED',
  `response_metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response_metadata`)),
  `url` varchar(500) DEFAULT NULL,
  `component` varchar(255) DEFAULT NULL,
  `browser` varchar(100) DEFAULT NULL,
  `browser_version` varchar(50) DEFAULT NULL,
  `operating_system` varchar(100) DEFAULT NULL,
  `device_type` varchar(50) DEFAULT NULL,
  `screen_size` varchar(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_error_uuid` (`error_uuid`),
  KEY `idx_request_id` (`request_id`),
  KEY `idx_frontend_error_id` (`frontend_error_id`),
  KEY `idx_group_created` (`error_group_id`,`created_at`),
  KEY `idx_fingerprint_created` (`fingerprint_hash`,`created_at`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_severity` (`severity`),
  KEY `idx_source_created` (`source`,`created_at`),
  KEY `idx_endpoint` (`endpoint`(191)),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_department` (`department_id`),
  KEY `idx_status_code` (`status_code`),
  CONSTRAINT `fk_apperr_group` FOREIGN KEY (`error_group_id`) REFERENCES `error_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=185 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `appraisal_cycles`
--

DROP TABLE IF EXISTS `appraisal_cycles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appraisal_cycles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `financial_year_id` int(11) NOT NULL,
  `status` enum('active','inactive','completed') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ac_fy` (`financial_year_id`),
  CONSTRAINT `fk_ac_financial_year` FOREIGN KEY (`financial_year_id`) REFERENCES `financial_years` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `appraisal_revision_log`
--

DROP TABLE IF EXISTS `appraisal_revision_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appraisal_revision_log` (
  `id` int(11) NOT NULL,
  `original_appraisal_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `appraisal_cycle_id` int(11) NOT NULL,
  `appraiser_id` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `decision` text NOT NULL,
  `reviewer_comment` text DEFAULT NULL,
  `final_status` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `appraisal_scores`
--

DROP TABLE IF EXISTS `appraisal_scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appraisal_scores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_appraisal_id` int(11) NOT NULL,
  `performance_indicator_id` int(11) NOT NULL,
  `score` decimal(10,4) DEFAULT NULL,
  `appraiser_comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=177 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `appraisal_summary_cache`
--

DROP TABLE IF EXISTS `appraisal_summary_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appraisal_summary_cache` (
  `id` int(11) NOT NULL,
  `appraisal_cycle_id` int(11) NOT NULL,
  `quarter` varchar(10) NOT NULL,
  `total_completed` int(11) DEFAULT 0,
  `average_score` decimal(5,2) DEFAULT 0.00,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `office_id` int(11) NOT NULL,
  `clock_in_office_id` int(11) DEFAULT NULL,
  `clock_out_office_id` int(11) DEFAULT NULL,
  `clock_in` datetime DEFAULT NULL,
  `clock_out` datetime DEFAULT NULL,
  `lat` decimal(10,8) DEFAULT NULL,
  `lng` decimal(11,8) DEFAULT NULL,
  `accuracy` float DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `is_late` tinyint(1) DEFAULT 0,
  `auto_clocked_out` tinyint(1) DEFAULT 0,
  `device_fingerprint` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'clocked_in',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `attendance_date` date GENERATED ALWAYS AS (cast(`clock_in` as date)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_attendance_employee_date` (`employee_id`,`attendance_date`),
  KEY `idx_attendance_employee_active` (`employee_id`,`clock_out`),
  KEY `idx_attendance_employee_date` (`employee_id`,`clock_in`),
  KEY `idx_attendance_clock_in_date` (`clock_in`),
  KEY `idx_attendance_office` (`office_id`,`clock_in`),
  KEY `idx_attendance_date_emp` (`attendance_date`,`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12898 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL COMMENT 'Authenticated actor user id',
  `employee_id` int(10) unsigned DEFAULT NULL COMMENT 'Affected employee (employees.id)',
  `office_id` int(10) unsigned DEFAULT NULL COMMENT 'Office id recorded against the action',
  `office_name` varchar(255) DEFAULT NULL COMMENT 'Office name at time of event',
  `latitude` decimal(10,8) DEFAULT NULL COMMENT 'GPS latitude (only when device provided a fix)',
  `longitude` decimal(11,8) DEFAULT NULL COMMENT 'GPS longitude (only when device provided a fix)',
  `location_accuracy` int(10) unsigned DEFAULT NULL COMMENT 'GPS accuracy in metres (NULL when no fix)',
  `location_source` varchar(20) DEFAULT NULL COMMENT 'GPS|OFFICE|USER_SELECTED|UNVERIFIED|IP|UNKNOWN',
  `request_id` varchar(64) DEFAULT NULL COMMENT 'Correlation/request id for end-to-end tracing',
  `channel` varchar(50) DEFAULT NULL COMMENT 'WEB|MOBILE_WEB|DESKTOP|ADMIN_PORTAL|API|SYSTEM|BACKGROUND_JOB',
  `device_type` varchar(50) DEFAULT NULL COMMENT 'mobile|tablet|desktop|bot|unknown',
  `browser` varchar(100) DEFAULT NULL COMMENT 'Best-effort browser family parsed from user agent',
  `operating_system` varchar(100) DEFAULT NULL COMMENT 'Best-effort OS parsed from user agent',
  `user_name_snapshot` varchar(255) DEFAULT NULL COMMENT 'Display name of the actor at time of event',
  `user_role_snapshot` varchar(100) DEFAULT NULL COMMENT 'Role of the actor at time of event',
  `action` varchar(100) NOT NULL COMMENT 'Standardized action (LOGIN, CREATE, UPDATE, ...)',
  `module` varchar(100) NOT NULL COMMENT 'Module affected (Employees, Leave, Authentication, ...)',
  `description` text DEFAULT NULL COMMENT 'Human readable description',
  `target_type` varchar(100) DEFAULT NULL COMMENT 'Type of target record (Employee, LeaveRequest, ...)',
  `target_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Primary key of the target record',
  `target_name` varchar(255) DEFAULT NULL COMMENT 'Display name of the target record',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'Request IP (captured on the backend)',
  `user_agent` text DEFAULT NULL COMMENT 'Request user agent',
  `location` varchar(255) DEFAULT NULL COMMENT 'Best-effort IP-derived location',
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Snapshot before the change' CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Snapshot after the change' CHECK (json_valid(`new_values`)),
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional non-sensitive metadata' CHECK (json_valid(`metadata`)),
  `status` varchar(50) NOT NULL DEFAULT 'SUCCESS' COMMENT 'SUCCESS | FAILED',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_module` (`module`),
  KEY `idx_module_action` (`module`,`action`),
  KEY `idx_target_type` (`target_type`),
  KEY `idx_target_id` (`target_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_status` (`status`),
  KEY `idx_user_action` (`user_id`,`action`),
  KEY `idx_audit_employee_id` (`employee_id`),
  KEY `idx_audit_office_id` (`office_id`),
  KEY `idx_audit_location_source` (`location_source`),
  KEY `idx_audit_channel` (`channel`),
  KEY `idx_audit_request_id` (`request_id`),
  KEY `idx_audit_target_type_id` (`target_type`,`target_id`)
) ENGINE=InnoDB AUTO_INCREMENT=894 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cycle_indicators`
--

DROP TABLE IF EXISTS `cycle_indicators`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cycle_indicators` (
  `id` int(11) NOT NULL,
  `cycle_id` int(11) NOT NULL,
  `indicator_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `max_score` int(11) NOT NULL,
  `section_id` int(11) DEFAULT NULL,
  `subsection_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `db_sessions`
--

DROP TABLE IF EXISTS `db_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `db_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `device_fp` char(64) NOT NULL COMMENT 'SHA-256 device fingerprint',
  `session_token` char(64) NOT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(512) NOT NULL DEFAULT '',
  `last_activity` datetime NOT NULL,
  `displaced_at` datetime DEFAULT NULL COMMENT 'Set when a newer login displaces this session',
  `displaced_by` varchar(120) DEFAULT NULL COMMENT 'IP of the displacing device',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `persistent_device_id` varchar(64) DEFAULT NULL COMMENT 'Persistent device identifier (generated once and reused across sessions)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_device` (`user_id`,`device_fp`),
  KEY `idx_token` (`session_token`),
  KEY `idx_user` (`user_id`),
  KEY `idx_activity` (`last_activity`),
  KEY `idx_persistent_device` (`persistent_device_id`)
) ENGINE=InnoDB AUTO_INCREMENT=19980 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `delegations`
--

DROP TABLE IF EXISTS `delegations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `delegations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `delegator_user_id` int(11) NOT NULL COMMENT 'The supervisor whose authority is delegated',
  `delegate_user_id` int(11) NOT NULL COMMENT 'The user receiving the temporary authority',
  `delegated_role` varchar(50) NOT NULL COMMENT 'The DELEGATOR''S role whose authority is transferred (role-aware §7)',
  `scope_type` enum('department','section','subsection','organization') NOT NULL COMMENT 'Delegated organizational scope (§8)',
  `scope_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Unit id for the scope (0 for organization-wide)',
  `permissions` text NOT NULL COMMENT 'Explicit JSON snapshot of delegated "module:action" strings (§22)',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `status` enum('pending','approved','active','expired','cancelled','rejected') NOT NULL DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_delegations_delegate` (`delegate_user_id`,`status`,`start_date`,`end_date`),
  KEY `idx_delegations_delegator` (`delegator_user_id`,`status`),
  KEY `idx_delegations_status_window` (`status`,`start_date`,`end_date`),
  CONSTRAINT `fk_delegations_delegate` FOREIGN KEY (`delegate_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_delegations_delegator` FOREIGN KEY (`delegator_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=352 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Temporary delegation / acting authority (never a role change)';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dependencies`
--

DROP TABLE IF EXISTS `dependencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dependencies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `relationship` varchar(100) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `id_no` varchar(50) DEFAULT NULL,
  `contact` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=76 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `device_attempt_log`
--

DROP TABLE IF EXISTS `device_attempt_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `device_attempt_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `device_fingerprint` varchar(64) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `action` varchar(50) NOT NULL DEFAULT 'login' COMMENT 'login | clock_in | clock_out',
  PRIMARY KEY (`id`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_date` (`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_appraisals`
--

DROP TABLE IF EXISTS `employee_appraisals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_appraisals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `employee_department_id` int(11) DEFAULT NULL,
  `appraiser_id` int(11) NOT NULL,
  `appraisal_cycle_id` int(11) NOT NULL,
  `employee_comment` text DEFAULT NULL,
  `employee_satisfied` int(11) NOT NULL,
  `employee_comment_date` timestamp NULL DEFAULT NULL,
  `supervisors_comment` text NOT NULL,
  `supervisors_comment_date` datetime NOT NULL DEFAULT current_timestamp(),
  `submitted_at` timestamp NULL DEFAULT NULL,
  `status` enum('draft','awaiting_employee','submitted','completed','awaiting_submission','pending_dept_approval','under_review','rejected','cancelled') DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `escalated_to_dept_head` tinyint(1) DEFAULT 0,
  `escalation_level` varchar(50) DEFAULT NULL,
  `escalated_date` datetime DEFAULT NULL,
  `dept_head_decision` text DEFAULT NULL,
  `dept_head_comment` text DEFAULT NULL,
  `dept_head_resolution` enum('resolved','further_action') DEFAULT NULL,
  `dept_head_resolution_notes` text DEFAULT NULL,
  `dept_head_review_date` datetime DEFAULT NULL,
  `dept_head_decision_date` datetime DEFAULT NULL,
  `dept_head_reviewer_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_devices`
--

DROP TABLE IF EXISTS `employee_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `device_fingerprint` varchar(64) NOT NULL COMMENT 'Canvas/WebGL/hardware hash',
  `raw_device_fingerprint` varchar(64) DEFAULT NULL COMMENT 'Physical-device fingerprint (no employee ID) — used for cross-employee checks',
  `device_token` varchar(128) NOT NULL COMMENT 'Long-lived token stored in localStorage',
  `registered_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used` datetime DEFAULT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = revoked by HR',
  `device_label` varchar(100) DEFAULT NULL COMMENT 'e.g. Office PC, Work Phone',
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_token` (`device_token`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_token` (`device_token`(32)),
  KEY `idx_employee_device_raw_fp` (`raw_device_fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_documents`
--

DROP TABLE IF EXISTS `employee_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `document_name` varchar(255) NOT NULL,
  `category` varchar(50) DEFAULT 'other',
  `file_name` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1141 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_leave_balances`
--

DROP TABLE IF EXISTS `employee_leave_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_leave_balances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `financial_year_id` int(11) NOT NULL,
  `allocated_days` decimal(5,2) NOT NULL DEFAULT 0.00,
  `used_days` decimal(5,2) NOT NULL DEFAULT 0.00,
  `brought_forward_days` decimal(5,2) NOT NULL DEFAULT 0.00,
  `accumulated_days` decimal(5,2) NOT NULL DEFAULT 0.00,
  `remaining_days` decimal(5,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=18667 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_leave_brought_forward`
--

DROP TABLE IF EXISTS `employee_leave_brought_forward`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_leave_brought_forward` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `financial_year_id` int(11) NOT NULL,
  `brought_forward_days` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_offices`
--

DROP TABLE IF EXISTS `employee_offices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_offices` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `office_id` int(11) DEFAULT NULL,
  `is_primary` tinyint(1) DEFAULT 1,
  `assigned_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_otps`
--

DROP TABLE IF EXISTS `employee_otps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_otps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `otp_code` varchar(6) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=234 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employees`
--

DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `profile_token` char(64) DEFAULT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `surname` varchar(100) DEFAULT NULL,
  `gender` varchar(10) NOT NULL,
  `national_id` int(10) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `designation` varchar(50) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `address` text DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `salary` decimal(10,2) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `employment_type` varchar(20) NOT NULL,
  `employee_type` varchar(20) NOT NULL,
  `profile_image_url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `employee_status` enum('active','inactive','resigned','fired','retired') NOT NULL DEFAULT 'active',
  `scale_id` varchar(10) DEFAULT NULL,
  `next_of_kin` text DEFAULT NULL,
  `dependants` text DEFAULT NULL,
  `subsection_id` int(11) DEFAULT NULL,
  `office_id` int(11) DEFAULT NULL,
  `contract_start_date` date DEFAULT NULL COMMENT 'Start date for contract employees',
  `contract_end_date` date DEFAULT NULL COMMENT 'End date for contract employees',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_employees_employee_id` (`employee_id`),
  KEY `idx_employees_contract_dates` (`contract_end_date`)
) ENGINE=InnoDB AUTO_INCREMENT=617 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `error_group_users`
--

DROP TABLE IF EXISTS `error_group_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `error_group_users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `error_group_id` bigint(20) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `first_seen_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_user` (`error_group_id`,`user_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_errgroupusers_group` FOREIGN KEY (`error_group_id`) REFERENCES `error_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=98 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `error_groups`
--

DROP TABLE IF EXISTS `error_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `error_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fingerprint` varchar(191) NOT NULL COMMENT 'Human readable grouping key e.g. attendance.runtime.database_timeout',
  `fingerprint_hash` char(64) NOT NULL COMMENT 'SHA-256 of canonical fingerprint parts (unique lookup key)',
  `title` varchar(255) NOT NULL COMMENT 'Short display title',
  `module` varchar(100) NOT NULL DEFAULT 'System' COMMENT 'HR module (matches AuditService modules)',
  `category` varchar(50) NOT NULL DEFAULT 'SYSTEM_ERROR',
  `severity` varchar(20) NOT NULL DEFAULT 'MEDIUM' COMMENT 'DEBUG|INFO|LOW|MEDIUM|HIGH|CRITICAL',
  `status` varchar(20) NOT NULL DEFAULT 'NEW' COMMENT 'NEW|ACKNOWLEDGED|INVESTIGATING|FIXED|VERIFIED|RESOLVED|IGNORED',
  `environment` varchar(20) NOT NULL DEFAULT 'production',
  `exception_class` varchar(191) DEFAULT NULL,
  `sample_message` text DEFAULT NULL,
  `sample_endpoint` varchar(255) DEFAULT NULL,
  `sample_http_method` varchar(10) DEFAULT NULL,
  `sample_file` varchar(255) DEFAULT NULL,
  `sample_line` int(10) unsigned DEFAULT NULL,
  `occurrence_count` int(10) unsigned NOT NULL DEFAULT 0,
  `affected_user_count` int(10) unsigned NOT NULL DEFAULT 0,
  `first_seen_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `last_notified_at` datetime DEFAULT NULL COMMENT 'Last alert emission (cooldown control)',
  `assigned_to` int(10) unsigned DEFAULT NULL COMMENT 'users.id of assigned developer/admin',
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` int(10) unsigned DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `fixed_version` varchar(50) DEFAULT NULL COMMENT 'Application version that fixed the issue',
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fingerprint_hash` (`fingerprint_hash`),
  KEY `idx_fingerprint` (`fingerprint`),
  KEY `idx_severity_status` (`severity`,`status`),
  KEY `idx_module` (`module`),
  KEY `idx_last_seen` (`last_seen_at`),
  KEY `idx_first_seen` (`first_seen_at`),
  KEY `idx_assigned_to` (`assigned_to`)
) ENGINE=InnoDB AUTO_INCREMENT=75 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `financial_years`
--

DROP TABLE IF EXISTS `financial_years`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `financial_years` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `year_name` varchar(100) NOT NULL,
  `total_days` int(11) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `goals`
--

DROP TABLE IF EXISTS `goals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `goals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `strategic_plan_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `strategic_plan_id` (`strategic_plan_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `holidays`
--

DROP TABLE IF EXISTS `holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `holidays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `date` date NOT NULL,
  `description` text DEFAULT NULL,
  `is_recurring` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `jdac_questionnaires`
--

DROP TABLE IF EXISTS `jdac_questionnaires`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jdac_questionnaires` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `financial_year_id` int(11) NOT NULL,
  `status` enum('draft','submitted','under_review','approved','rejected') DEFAULT 'draft',
  `employee_comments` text DEFAULT NULL,
  `supervisor_verification_notes` text DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_jdac_unique_emp_fy` (`employee_id`,`financial_year_id`),
  KEY `employee_id` (`employee_id`),
  KEY `financial_year_id` (`financial_year_id`),
  KEY `status` (`status`),
  KEY `fk_jdac_reviewer` (`reviewed_by`),
  KEY `idx_jdac_emp_status` (`employee_id`,`status`),
  KEY `idx_jdac_fy_status` (`financial_year_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `jdac_questions`
--

DROP TABLE IF EXISTS `jdac_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jdac_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section` varchar(100) NOT NULL,
  `section_order` int(11) NOT NULL DEFAULT 0,
  `question_text` text NOT NULL,
  `question_type` enum('text','textarea','select','multiselect','rating','file') DEFAULT 'textarea',
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`options`)),
  `is_required` tinyint(1) DEFAULT 1,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `section` (`section`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `jdac_responses`
--

DROP TABLE IF EXISTS `jdac_responses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jdac_responses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `questionnaire_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `response_value` text DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `questionnaire_id` (`questionnaire_id`),
  KEY `question_id` (`question_id`)
) ENGINE=InnoDB AUTO_INCREMENT=244 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `kpis`
--

DROP TABLE IF EXISTS `kpis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `kpis` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `performance_contract_id` int(11) NOT NULL,
  `kpi_name` varchar(500) NOT NULL,
  `kpi_description` text DEFAULT NULL,
  `target` varchar(255) DEFAULT NULL,
  `unit_of_measure` varchar(100) DEFAULT NULL,
  `data_source` varchar(255) DEFAULT NULL,
  `frequency` varchar(50) DEFAULT NULL,
  `responsible_person` varchar(255) DEFAULT NULL,
  `weight` decimal(5,2) DEFAULT 0.00,
  `y1_target` varchar(100) DEFAULT NULL,
  `y2_target` varchar(100) DEFAULT NULL,
  `y3_target` varchar(100) DEFAULT NULL,
  `y4_target` varchar(100) DEFAULT NULL,
  `y5_target` varchar(100) DEFAULT NULL,
  `y1_score` varchar(100) DEFAULT NULL,
  `y2_score` varchar(100) DEFAULT NULL,
  `y3_score` varchar(100) DEFAULT NULL,
  `y4_score` varchar(100) DEFAULT NULL,
  `y5_score` varchar(100) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `updated_by` (`updated_by`),
  KEY `idx_performance_contract` (`performance_contract_id`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_responsible_person` (`responsible_person`(100))
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leave_application_documents`
--

DROP TABLE IF EXISTS `leave_application_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_application_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `leave_application_id` int(11) NOT NULL COMMENT 'Reference to leave_applications.id',
  `document_type` varchar(50) NOT NULL COMMENT 'Controlled document type',
  `original_filename` varchar(255) NOT NULL COMMENT 'Original uploaded filename',
  `stored_filename` varchar(255) NOT NULL COMMENT 'Secure server-side filename',
  `file_path` varchar(500) NOT NULL COMMENT 'Full path to stored file',
  `mime_type` varchar(100) NOT NULL COMMENT 'File MIME type',
  `file_size` bigint(20) NOT NULL COMMENT 'File size in bytes',
  `uploaded_by` int(11) NOT NULL COMMENT 'users.id who uploaded',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_leave_application_id` (`leave_application_id`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  KEY `idx_document_type` (`document_type`),
  CONSTRAINT `fk_leave_doc_application` FOREIGN KEY (`leave_application_id`) REFERENCES `leave_applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_leave_doc_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leave_applications`
--

DROP TABLE IF EXISTS `leave_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_applications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `financial_year_id` int(11) DEFAULT NULL,
  `employee_id` int(11) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `days_requested` int(11) NOT NULL,
  `reason` text NOT NULL,
  `deduction_details` text DEFAULT NULL COMMENT 'JSON storage of deduction plan',
  `primary_days` int(11) DEFAULT 0 COMMENT 'Days deducted from primary leave type',
  `annual_days` int(11) DEFAULT 0 COMMENT 'Days deducted from annual leave',
  `unpaid_days` int(11) DEFAULT 0 COMMENT 'Days that are unpaid',
  `applied_by_user_id` int(11) DEFAULT NULL,
  `status` enum('pending','pending_section_head','pending_dept_head','pending_managing_director','pending_hr_manager','approved','rejected','pending_bod_chair','pending_subsection_head','pending_manager','invalidated','pending_hr','cancelled') NOT NULL DEFAULT 'pending',
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `section_head_approval` enum('pending','approved','rejected') DEFAULT 'pending',
  `section_head_approved_by` varchar(50) DEFAULT NULL,
  `section_head_approved_at` timestamp NULL DEFAULT NULL,
  `dept_head_approval` enum('pending','approved','rejected') DEFAULT 'pending',
  `dept_head_approved_by` varchar(50) DEFAULT NULL,
  `dept_head_approved_at` timestamp NULL DEFAULT NULL,
  `hr_processed_by` varchar(50) DEFAULT NULL,
  `hr_processed_at` timestamp NULL DEFAULT NULL,
  `hr_comments` text DEFAULT NULL,
  `approver_id` int(11) DEFAULT NULL,
  `section_head_emp_id` int(11) DEFAULT NULL,
  `dept_head_emp_id` int(11) DEFAULT NULL,
  `delegate_emp_id` int(11) DEFAULT NULL,
  `delegate_role` varchar(50) DEFAULT NULL,
  `manager_emp_id` int(50) DEFAULT NULL,
  `days_deducted` int(11) DEFAULT 0,
  `days_from_annual` int(11) DEFAULT 0,
  `managing_director_approved_by` int(11) DEFAULT NULL,
  `hr_approved_by` int(11) DEFAULT NULL,
  `hr_approved_at` datetime DEFAULT NULL,
  `managing_director_approved_at` datetime DEFAULT NULL,
  `md_emp_id` int(11) DEFAULT NULL,
  `subsection_head_emp_id` int(11) DEFAULT NULL,
  `subsection_head_approval` enum('pending','approved','rejected') DEFAULT 'pending',
  `subsection_head_approved_by` int(11) DEFAULT NULL,
  `subsection_head_approved_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_financial_year` (`financial_year_id`),
  KEY `fk_leave_delegate_emp` (`delegate_emp_id`),
  KEY `idx_leave_status_dates` (`status`,`start_date`,`end_date`),
  KEY `idx_leave_emp_date` (`employee_id`,`start_date`),
  CONSTRAINT `fk_leave_delegate_emp` FOREIGN KEY (`delegate_emp_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=719 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leave_history`
--

DROP TABLE IF EXISTS `leave_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `leave_application_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `performed_by` int(11) NOT NULL,
  `delegation_id` bigint(20) unsigned DEFAULT NULL,
  `acted_for_user_id` int(11) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `performed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=724 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leave_roster`
--

DROP TABLE IF EXISTS `leave_roster`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_roster` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `financial_year_id` int(11) NOT NULL,
  `scheduled_month` varchar(20) NOT NULL,
  `scheduled_year` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_employee_financial_year` (`employee_id`,`financial_year_id`),
  UNIQUE KEY `unique_employee_month` (`employee_id`,`scheduled_month`,`scheduled_year`),
  KEY `idx_financial_year` (`financial_year_id`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_scheduled_month` (`scheduled_month`),
  KEY `idx_scheduled_year` (`scheduled_year`),
  CONSTRAINT `fk_leave_roster_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_leave_roster_financial_year` FOREIGN KEY (`financial_year_id`) REFERENCES `financial_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=157 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leave_transactions`
--

DROP TABLE IF EXISTS `leave_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `transaction_date` datetime NOT NULL,
  `transaction_type` enum('deduction','restoration','adjustment') NOT NULL,
  `details` text DEFAULT NULL COMMENT 'JSON storage of transaction details',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=719 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Audit trail for all leave transactions';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leave_transactions_backup`
--

DROP TABLE IF EXISTS `leave_transactions_backup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_transactions_backup` (
  `id` int(11) NOT NULL DEFAULT 0,
  `application_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `transaction_date` datetime NOT NULL,
  `transaction_type` enum('deduction','restoration','adjustment') NOT NULL,
  `details` text DEFAULT NULL COMMENT 'JSON storage of transaction details',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leave_types`
--

DROP TABLE IF EXISTS `leave_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `counts_weekends` tinyint(1) DEFAULT 0,
  `count_holidays` tinyint(1) NOT NULL DEFAULT 0,
  `deducted_from_annual` tinyint(1) DEFAULT 1,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `meeting_minutes`
--

DROP TABLE IF EXISTS `meeting_minutes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `meeting_minutes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `meeting_id` int(11) NOT NULL COMMENT 'FK to meetings.id (one minutes set per meeting)',
  `reference_number` varchar(50) NOT NULL COMMENT 'Official minutes reference, e.g. MMS-{meeting_id}-{year}',
  `meeting_date` date DEFAULT NULL COMMENT 'Snapshot of meeting.meeting_date at creation',
  `start_time` time DEFAULT NULL COMMENT 'Snapshot of meeting.start_time',
  `end_time` time DEFAULT NULL COMMENT 'Snapshot of meeting.end_time',
  `venue` varchar(255) DEFAULT NULL COMMENT 'Snapshot of meeting.location',
  `chairperson_id` int(11) DEFAULT NULL COMMENT 'FK to employees.id',
  `secretary_id` int(11) DEFAULT NULL COMMENT 'FK to employees.id',
  `status` enum('draft','published') NOT NULL DEFAULT 'draft' COMMENT 'Lifecycle: draft -> published (immutable until reopened)',
  `version` int(11) NOT NULL DEFAULT 1 COMMENT 'Version number; bumped on reopen/amend',
  `amendment_reason` text DEFAULT NULL COMMENT 'Why the minutes were reopened/amended',
  `aob` text DEFAULT NULL COMMENT 'Any-other-business catch-all text',
  `next_meeting_date` date DEFAULT NULL,
  `next_meeting_time` time DEFAULT NULL,
  `next_meeting_venue` varchar(255) DEFAULT NULL,
  `next_meeting_notes` text DEFAULT NULL,
  `prepared_by` int(11) DEFAULT NULL COMMENT 'FK to users.id (minutes author)',
  `prepared_at` datetime DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL COMMENT 'FK to users.id',
  `reviewed_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL COMMENT 'FK to users.id',
  `approved_at` datetime DEFAULT NULL,
  `published_by` int(11) DEFAULT NULL COMMENT 'FK to users.id',
  `published_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_minutes_meeting` (`meeting_id`),
  UNIQUE KEY `uk_minutes_reference` (`reference_number`),
  KEY `fk_minutes_chairperson` (`chairperson_id`),
  KEY `fk_minutes_secretary` (`secretary_id`),
  KEY `idx_minutes_status` (`status`),
  KEY `idx_minutes_prepared_by` (`prepared_by`),
  KEY `idx_minutes_published_by` (`published_by`),
  KEY `idx_minutes_created_at` (`created_at`),
  CONSTRAINT `fk_minutes_chairperson` FOREIGN KEY (`chairperson_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_minutes_meeting` FOREIGN KEY (`meeting_id`) REFERENCES `meetings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_minutes_prepared_by` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_minutes_published_by` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_minutes_secretary` FOREIGN KEY (`secretary_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `meeting_minutes_action_items`
--

DROP TABLE IF EXISTS `meeting_minutes_action_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `meeting_minutes_action_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `minutes_id` int(11) NOT NULL COMMENT 'FK to meeting_minutes.id',
  `action` text NOT NULL,
  `assigned_to` int(11) DEFAULT NULL COMMENT 'FK to employees.id',
  `department_id` int(11) DEFAULT NULL COMMENT 'FK to departments.id',
  `due_date` date DEFAULT NULL,
  `priority` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `status` enum('pending','in_progress','completed','overdue','deferred','cancelled') NOT NULL DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_actions_department` (`department_id`),
  KEY `idx_actions_minutes` (`minutes_id`),
  KEY `idx_actions_assigned_to` (`assigned_to`),
  KEY `idx_actions_due_date` (`due_date`),
  KEY `idx_actions_status` (`status`),
  CONSTRAINT `fk_actions_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_actions_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_actions_minutes` FOREIGN KEY (`minutes_id`) REFERENCES `meeting_minutes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `meeting_minutes_agenda_items`
--

DROP TABLE IF EXISTS `meeting_minutes_agenda_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `meeting_minutes_agenda_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `minutes_id` int(11) NOT NULL COMMENT 'FK to meeting_minutes.id',
  `position` int(11) NOT NULL DEFAULT 1 COMMENT 'Agenda ordering (1-based)',
  `agenda_number` varchar(20) DEFAULT NULL COMMENT 'e.g. 1.0, 2.1',
  `title` varchar(255) NOT NULL,
  `presenter_id` int(11) DEFAULT NULL COMMENT 'FK to employees.id',
  `discussion` text DEFAULT NULL,
  `decision` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_agenda_presenter` (`presenter_id`),
  KEY `idx_agenda_minutes` (`minutes_id`),
  CONSTRAINT `fk_agenda_minutes` FOREIGN KEY (`minutes_id`) REFERENCES `meeting_minutes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_agenda_presenter` FOREIGN KEY (`presenter_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `meeting_minutes_aob_items`
--

DROP TABLE IF EXISTS `meeting_minutes_aob_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `meeting_minutes_aob_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `minutes_id` int(11) NOT NULL COMMENT 'FK to meeting_minutes.id',
  `item` varchar(255) NOT NULL,
  `discussion` text DEFAULT NULL,
  `decision` text DEFAULT NULL,
  `action` text DEFAULT NULL,
  `responsible_id` int(11) DEFAULT NULL COMMENT 'FK to employees.id',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_aob_responsible` (`responsible_id`),
  KEY `idx_aob_minutes` (`minutes_id`),
  CONSTRAINT `fk_aob_minutes` FOREIGN KEY (`minutes_id`) REFERENCES `meeting_minutes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_aob_responsible` FOREIGN KEY (`responsible_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `meeting_minutes_decisions`
--

DROP TABLE IF EXISTS `meeting_minutes_decisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `meeting_minutes_decisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `minutes_id` int(11) NOT NULL COMMENT 'FK to meeting_minutes.id',
  `decision_number` varchar(20) DEFAULT NULL COMMENT 'e.g. D-01',
  `resolution` text NOT NULL,
  `responsible_id` int(11) DEFAULT NULL COMMENT 'FK to employees.id',
  `department_id` int(11) DEFAULT NULL COMMENT 'FK to departments.id',
  `due_date` date DEFAULT NULL,
  `status` enum('pending','in_progress','completed','deferred','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_decisions_department` (`department_id`),
  KEY `idx_decisions_minutes` (`minutes_id`),
  KEY `idx_decisions_due_date` (`due_date`),
  KEY `idx_decisions_status` (`status`),
  KEY `idx_decisions_responsible` (`responsible_id`),
  CONSTRAINT `fk_decisions_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_decisions_minutes` FOREIGN KEY (`minutes_id`) REFERENCES `meeting_minutes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decisions_responsible` FOREIGN KEY (`responsible_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- NOTE: The `migrations` tracking table is OWNED by database/run.php (the
-- migration runner), which creates it with the full tracking schema and uses
-- it to record executed migrations. This baseline dump must NOT include it:
-- an earlier version DROP'd + re-CREATE'd it with an outdated 3-column
-- structure mid-run, wiping batch history and breaking the runner's INSERTs
-- (Unknown column 'duration_ms' → migrations silently mis-tracked).

--
-- Table structure for table `next_of_kin`
--

DROP TABLE IF EXISTS `next_of_kin`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `next_of_kin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `relationship` varchar(100) NOT NULL,
  `contact` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_employee_id` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notification_logs`
--

DROP TABLE IF EXISTS `notification_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'Recipient user (users.id)',
  `employee_id` int(10) unsigned DEFAULT NULL COMMENT 'Denormalised employees.id for reporting',
  `notification_type` varchar(60) NOT NULL DEFAULT 'attendance_clock_in_reminder',
  `channel` varchar(20) NOT NULL COMMENT 'web_push | sms | email | in_app ...',
  `stage` varchar(30) NOT NULL DEFAULT 'reminder_1' COMMENT 'reminder_1 | sms_fallback | reminder_2 ...',
  `business_date` date NOT NULL COMMENT 'Org-timezone attendance day',
  `status` varchar(30) NOT NULL DEFAULT 'pending' COMMENT 'pending|sent|failed|failed_permanent|retrying|skipped|revoked',
  `recipient` varchar(200) DEFAULT NULL COMMENT 'E.164 phone (SMS) or endpoint host+hash prefix (push)',
  `provider_message_id` varchar(100) DEFAULT NULL COMMENT 'SMS provider message id / request id echo',
  `failure_reason` varchar(500) DEFAULT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `scheduled_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_once` (`user_id`,`business_date`,`notification_type`,`channel`,`stage`),
  KEY `idx_nl_business_date` (`business_date`),
  KEY `idx_nl_status_date` (`status`,`business_date`),
  KEY `idx_nl_user_date` (`user_id`,`business_date`),
  KEY `idx_nl_provider_msg` (`provider_message_id`),
  CONSTRAINT `fk_nl_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notification_preferences`
--

DROP TABLE IF EXISTS `notification_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_preferences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `push_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Web Push attendance reminders',
  `sms_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'SMS attendance reminders',
  `email_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Future channel - reserved',
  `reminders_mandated` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Org policy: cannot be changed by employee',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pref_user` (`user_id`),
  CONSTRAINT `fk_pref_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notification_templates`
--

DROP TABLE IF EXISTS `notification_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_templates` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `title_template` varchar(500) NOT NULL,
  `message_template` text NOT NULL,
  `type` varchar(100) NOT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'general',
  `trigger_source` varchar(100) NOT NULL,
  `default_priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `is_active` tinyint(1) DEFAULT 1,
  `roles_target` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`roles_target`)),
  `action_url_template` varchar(500) DEFAULT NULL,
  `expires_after_days` int(11) DEFAULT 30,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(100) NOT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'general',
  `trigger_type` enum('action','scheduled') DEFAULT 'action',
  `trigger_source` varchar(100) DEFAULT 'system',
  `is_read` tinyint(1) DEFAULT 0,
  `is_sent` tinyint(1) DEFAULT 1,
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `related_entity` varchar(100) DEFAULT NULL,
  `related_id` int(11) DEFAULT NULL,
  `action_url` varchar(500) DEFAULT NULL,
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `objectives`
--

DROP TABLE IF EXISTS `objectives`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `objectives` (
  `id` bigint(20) unsigned NOT NULL,
  `strategic_plan_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `offices`
--

DROP TABLE IF EXISTS `offices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `offices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `geo_fence_radius` int(11) DEFAULT 50,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `performance_contracts`
--

DROP TABLE IF EXISTS `performance_contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `performance_contracts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `strategic_plan_id` int(11) NOT NULL,
  `goal_id` int(11) NOT NULL,
  `target_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `kra` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `department_id` int(11) NOT NULL,
  `financial_year_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `strategic_plan_id` (`strategic_plan_id`),
  KEY `goal_id` (`goal_id`),
  KEY `fk_performance_contracts_department` (`department_id`),
  KEY `fk_performance_contracts_financial_year` (`financial_year_id`),
  KEY `fk_target_id` (`target_id`)
) ENGINE=InnoDB AUTO_INCREMENT=175 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `performance_events`
--

DROP TABLE IF EXISTS `performance_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `performance_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` varchar(64) DEFAULT NULL,
  `endpoint` varchar(255) DEFAULT NULL,
  `http_method` varchar(10) DEFAULT NULL,
  `duration_ms` int(10) unsigned NOT NULL,
  `threshold_level` varchar(20) NOT NULL DEFAULT 'warning' COMMENT 'warning|slow|critical',
  `status_code` smallint(5) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `memory_kb` int(10) unsigned DEFAULT NULL,
  `query_count` int(10) unsigned DEFAULT NULL,
  `query_ms` int(10) unsigned DEFAULT NULL,
  `max_query_ms` int(10) unsigned DEFAULT NULL,
  `auth_ms` int(10) unsigned DEFAULT NULL,
  `authorization_ms` int(10) unsigned DEFAULT NULL,
  `environment` varchar(20) DEFAULT NULL,
  `application_version` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `controller_ms` int(10) unsigned DEFAULT NULL,
  `serialization_ms` int(10) unsigned DEFAULT NULL,
  `ai_provider_ms` int(10) unsigned DEFAULT NULL,
  `ai_provider_calls` smallint(5) unsigned DEFAULT NULL,
  `ai_tool_calls` smallint(5) unsigned DEFAULT NULL,
  `external_http_ms` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_perf_created` (`created_at`),
  KEY `idx_perf_level_created` (`threshold_level`,`created_at`),
  KEY `idx_perf_request` (`request_id`),
  KEY `idx_perf_endpoint` (`endpoint`(191))
) ENGINE=InnoDB AUTO_INCREMENT=939 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `performance_indicators`
--

DROP TABLE IF EXISTS `performance_indicators`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `performance_indicators` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `max_score` int(11) NOT NULL DEFAULT 5,
  `activity_ids` varchar(255) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `assigned_to_employee_ids` varchar(255) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `section_id` int(11) DEFAULT NULL,
  `subsection_id` int(20) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `is_recurrent` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=112 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `push_subscriptions`
--

DROP TABLE IF EXISTS `push_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `push_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'Authenticated user (users.id)',
  `endpoint_hash` char(64) NOT NULL COMMENT 'SHA-256 of endpoint URL (unique upsert key)',
  `endpoint` text NOT NULL COMMENT 'Push service endpoint URL',
  `p256dh_key` text NOT NULL COMMENT 'Client public key (base64url)',
  `auth_key` text NOT NULL COMMENT 'Auth secret (base64url)',
  `device_name` varchar(120) DEFAULT NULL COMMENT 'Friendly device label supplied by the employee',
  `platform` varchar(60) DEFAULT NULL COMMENT 'Browser platform hint (android/windows/...)',
  `user_agent` varchar(500) DEFAULT NULL COMMENT 'User agent at registration time',
  `last_used_at` datetime DEFAULT NULL COMMENT 'Last successful send attempt',
  `revoked_at` datetime DEFAULT NULL COMMENT 'Set when unsubscribed or endpoint invalid (410)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_push_endpoint_hash` (`endpoint_hash`),
  KEY `idx_push_user_active` (`user_id`,`revoked_at`),
  CONSTRAINT `fk_push_sub_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `refresh_tokens`
--

DROP TABLE IF EXISTS `refresh_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `refresh_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `token_id` varchar(64) NOT NULL,
  `user_id` int(11) NOT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_id` (`token_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_revoked` (`revoked_at`),
  CONSTRAINT `refresh_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role` varchar(50) NOT NULL COMMENT 'Role name (e.g., super_admin, hr_manager, employee)',
  `module` varchar(50) NOT NULL COMMENT 'Module/page name (e.g., employees, attendance, leave)',
  `action` varchar(50) NOT NULL COMMENT 'Action (e.g., view, create, edit, delete)',
  `is_granted` tinyint(1) DEFAULT 1 COMMENT '1 = granted, 0 = denied',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_module_action` (`role`,`module`,`action`),
  KEY `idx_role` (`role`),
  KEY `idx_module` (`module`)
) ENGINE=InnoDB AUTO_INCREMENT=10777 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `salary_bands`
--

DROP TABLE IF EXISTS `salary_bands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `salary_bands` (
  `scale_id` varchar(10) NOT NULL,
  `min_salary` decimal(10,2) DEFAULT NULL,
  `max_salary` decimal(10,2) DEFAULT NULL,
  `house_allowance` decimal(10,2) DEFAULT NULL,
  `commuter_allowance` decimal(10,2) DEFAULT NULL,
  `leave_allowance` decimal(10,2) DEFAULT NULL,
  `Dirty_allowance` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sections`
--

DROP TABLE IF EXISTS `sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `security_events`
--

DROP TABLE IF EXISTS `security_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `security_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(50) NOT NULL COMMENT 'FAILED_LOGIN, BRUTE_FORCE, UNAUTHORIZED_OBJECT_ACCESS, IDOR_ATTEMPT, IDOR_ENUMERATION, PRIVILEGE_ESCALATION, etc.',
  `severity` enum('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'LOW',
  `risk_score` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT '0-100 deterministic risk score',
  `user_id` int(10) unsigned DEFAULT NULL COMMENT 'Authenticated user ID',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(250) DEFAULT NULL,
  `session_id` varchar(128) DEFAULT NULL,
  `request_id` varchar(64) DEFAULT NULL COMMENT 'X-Request-ID correlation',
  `http_method` varchar(10) DEFAULT NULL,
  `route` varchar(255) DEFAULT NULL,
  `resource_type` varchar(50) DEFAULT NULL COMMENT 'employee, leave, attendance, meeting, user',
  `resource_id` varchar(50) DEFAULT NULL,
  `response_status` smallint(6) DEFAULT NULL,
  `action_taken` varchar(50) DEFAULT NULL COMMENT 'BLOCKED, DENIED, RATE_LIMITED, LOGGED, ALERTED',
  `description` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional structured metadata (PII-sanitized)' CHECK (json_valid(`metadata`)),
  `detected_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` int(10) unsigned DEFAULT NULL,
  `vulnerability_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sev_event_type` (`event_type`),
  KEY `idx_sev_severity` (`severity`),
  KEY `idx_sev_user_id` (`user_id`),
  KEY `idx_sev_detected_at` (`detected_at`),
  KEY `idx_sev_risk_score` (`risk_score`),
  KEY `idx_sev_resource` (`resource_type`,`resource_id`),
  KEY `idx_sev_ip` (`ip_address`),
  KEY `idx_se_vulnerability` (`vulnerability_id`),
  CONSTRAINT `fk_se_vulnerability` FOREIGN KEY (`vulnerability_id`) REFERENCES `vulnerabilities` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=101 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Centralized security event telemetry (PII-safe)';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `security_incident_events`
--

DROP TABLE IF EXISTS `security_incident_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `security_incident_events` (
  `incident_id` bigint(20) unsigned NOT NULL,
  `event_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`incident_id`,`event_id`),
  KEY `idx_ice_event` (`event_id`),
  CONSTRAINT `fk_ice_event` FOREIGN KEY (`event_id`) REFERENCES `security_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ice_incident` FOREIGN KEY (`incident_id`) REFERENCES `security_incidents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Many-to-many link between security incidents and events';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `security_incidents`
--

DROP TABLE IF EXISTS `security_incidents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `security_incidents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_uuid` char(36) NOT NULL COMMENT 'UUIDv4 for the incident',
  `severity` enum('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'LOW',
  `risk_score` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT '0-100 aggregated risk score',
  `status` enum('NEW','INVESTIGATING','CONTAINED','RESOLVED','FALSE_POSITIVE') NOT NULL DEFAULT 'NEW',
  `source` varchar(50) NOT NULL COMMENT 'rule_engine, ai_analysis, manual',
  `summary` text DEFAULT NULL COMMENT 'Administrator-friendly summary',
  `ai_classification` varchar(50) DEFAULT NULL COMMENT 'NORMAL, SUSPICIOUS, LIKELY_ATTACK, HIGH_CONFIDENCE_ATTACK, CRITICAL',
  `ai_confidence` decimal(5,4) DEFAULT NULL COMMENT '0.0000-1.0000',
  `ai_reasoning` text DEFAULT NULL COMMENT 'AI reasoning summary',
  `ai_risk_score` tinyint(4) DEFAULT NULL COMMENT 'AI-recommended risk score',
  `ai_recommended_action` varchar(100) DEFAULT NULL,
  `first_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` int(10) unsigned DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `related_event_count` int(10) unsigned NOT NULL DEFAULT 0,
  `vulnerability_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `incident_uuid` (`incident_uuid`),
  KEY `idx_inc_status` (`status`),
  KEY `idx_inc_severity` (`severity`),
  KEY `idx_inc_risk_score` (`risk_score`),
  KEY `idx_inc_first_seen` (`first_seen`),
  KEY `idx_inc_last_seen` (`last_seen`),
  KEY `idx_inc_vulnerability` (`vulnerability_id`),
  CONSTRAINT `fk_si_vulnerability` FOREIGN KEY (`vulnerability_id`) REFERENCES `vulnerabilities` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `security_logs`
--

DROP TABLE IF EXISTS `security_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `security_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `event_type` varchar(50) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp(),
  `details` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `strategic_plan`
--

DROP TABLE IF EXISTS `strategic_plan`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `strategic_plan` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `image` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `strategic_targets`
--

DROP TABLE IF EXISTS `strategic_targets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `strategic_targets` (
  `id` int(11) NOT NULL,
  `goal_id` int(11) NOT NULL,
  `strategic_plan_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `baseline_value` varchar(100) DEFAULT NULL,
  `target_value` varchar(100) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `goal_id` (`goal_id`),
  KEY `strategic_plan_id` (`strategic_plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `strategies`
--

DROP TABLE IF EXISTS `strategies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `strategies` (
  `id` int(11) NOT NULL,
  `strategic_plan_id` int(11) NOT NULL,
  `objective_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `activity` text NOT NULL,
  `kpi` text NOT NULL,
  `target` text NOT NULL,
  `Y1` int(11) NOT NULL,
  `Y2` int(11) NOT NULL,
  `Y3` int(11) NOT NULL,
  `Y4` int(11) NOT NULL,
  `Y5` int(11) NOT NULL,
  `Comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `subsections`
--

DROP TABLE IF EXISTS `subsections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subsections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `department_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_consents`
--

DROP TABLE IF EXISTS `user_consents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_consents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `national_id` varchar(100) NOT NULL,
  `consent_version` varchar(10) NOT NULL DEFAULT '1.0',
  `consent_given` tinyint(1) DEFAULT 1,
  `consent_date` datetime NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_consent_version` (`user_id`,`consent_version`)
) ENGINE=InnoDB AUTO_INCREMENT=195 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores employee data protection consent records';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_notification_preferences`
--

DROP TABLE IF EXISTS `user_notification_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_notification_preferences` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `category` varchar(100) NOT NULL,
  `enabled` tinyint(1) DEFAULT 1,
  `email_enabled` tinyint(1) DEFAULT 0,
  `push_enabled` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_page_permissions`
--

DROP TABLE IF EXISTS `user_page_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_page_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `module` varchar(50) NOT NULL DEFAULT '',
  `action` varchar(50) NOT NULL DEFAULT 'view',
  `page_id` varchar(100) NOT NULL,
  `permission_type` enum('allow','deny') NOT NULL,
  `granted_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `granted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_page` (`user_id`,`page_id`),
  UNIQUE KEY `uq_user_module_action` (`user_id`,`module`,`action`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_page_id` (`page_id`),
  KEY `idx_permission_type` (`permission_type`),
  KEY `idx_granted_by` (`granted_by`),
  KEY `idx_active` (`active`),
  KEY `idx_user_page_module` (`module`),
  KEY `idx_user_page_action` (`action`),
  KEY `idx_user_module_action_active` (`user_id`,`module`,`action`,`active`),
  KEY `fk_upp_updated_by` (`updated_by`),
  CONSTRAINT `fk_upp_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_upp_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_upp_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_page_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_page_permissions_ibfk_2` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=257 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_page_permissions_backup_015`
--

DROP TABLE IF EXISTS `user_page_permissions_backup_015`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_page_permissions_backup_015` (
  `id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `user_id` int(11) NOT NULL,
  `page_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `permission_type` enum('allow','deny') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `granted_by` int(11) DEFAULT NULL,
  `granted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) DEFAULT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `surname` varchar(50) NOT NULL,
  `gender` varchar(10) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('bod_chairman','super_admin','hr_manager','dept_head','section_head','manager','officer','managing_director','sub_section_head') DEFAULT 'officer',
  `designation` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `profile_image_url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `session_token` varchar(255) DEFAULT NULL,
  `login_identifier` varchar(64) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_activity` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_users_employee` (`employee_id`),
  CONSTRAINT `fk_users_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2175 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vulnerabilities`
--

DROP TABLE IF EXISTS `vulnerabilities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vulnerabilities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `title` varchar(255) NOT NULL COMMENT 'Short summary (no PII)',
  `description` text DEFAULT NULL COMMENT 'Admin-facing; never sent raw to AI',
  `category` varchar(64) NOT NULL,
  `type` varchar(64) NOT NULL,
  `cwe_id` varchar(32) DEFAULT NULL,
  `owasp_category` varchar(64) DEFAULT NULL,
  `severity` enum('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'MEDIUM',
  `risk_score` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `cvss_score` decimal(4,2) DEFAULT NULL,
  `status` enum('OPEN','ACKNOWLEDGED','IN_PROGRESS','MITIGATED','RESOLVED','FALSE_POSITIVE','ACCEPTED_RISK','REOPENED') NOT NULL DEFAULT 'OPEN',
  `source` enum('manual','ai_analysis','rule_engine','event') NOT NULL DEFAULT 'rule_engine',
  `ai_classification` varchar(50) DEFAULT NULL,
  `ai_confidence` decimal(5,4) DEFAULT NULL,
  `ai_analysis` text DEFAULT NULL,
  `affected_resource_type` varchar(50) DEFAULT NULL,
  `affected_resource_id` varchar(50) DEFAULT NULL,
  `affected_endpoint` varchar(255) DEFAULT NULL,
  `affected_route` varchar(255) DEFAULT NULL,
  `affected_method` varchar(10) DEFAULT NULL,
  `first_detected_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `verified_at` datetime DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` bigint(20) unsigned DEFAULT NULL,
  `resolved_notes` text DEFAULT NULL,
  `remediation` text DEFAULT NULL,
  `verification_notes` text DEFAULT NULL,
  `assigned_to` bigint(20) unsigned DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `idx_vuln_status` (`status`),
  KEY `idx_vuln_severity` (`severity`),
  KEY `idx_vuln_category` (`category`),
  KEY `idx_vuln_endpoint` (`affected_endpoint`),
  KEY `idx_vuln_resource` (`affected_resource_type`,`affected_resource_id`),
  KEY `idx_vuln_first_seen` (`first_detected_at`),
  KEY `idx_vuln_last_seen` (`last_seen_at`),
  KEY `idx_vuln_assigned` (`assigned_to`),
  KEY `idx_vuln_risk` (`risk_score`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Tracked security vulnerabilities with lifecycle and AI analysis';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vulnerability_events`
--

DROP TABLE IF EXISTS `vulnerability_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vulnerability_events` (
  `vulnerability_id` bigint(20) unsigned NOT NULL,
  `event_id` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`vulnerability_id`,`event_id`),
  KEY `idx_ve_event` (`event_id`),
  CONSTRAINT `fk_ve_event` FOREIGN KEY (`event_id`) REFERENCES `security_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ve_vulnerability` FOREIGN KEY (`vulnerability_id`) REFERENCES `vulnerabilities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Many-to-many link between vulnerabilities and security events';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vulnerability_incidents`
--

DROP TABLE IF EXISTS `vulnerability_incidents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vulnerability_incidents` (
  `vulnerability_id` bigint(20) unsigned NOT NULL,
  `incident_id` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`vulnerability_id`,`incident_id`),
  KEY `idx_vi_incident` (`incident_id`),
  CONSTRAINT `fk_vi_incident` FOREIGN KEY (`incident_id`) REFERENCES `security_incidents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vi_vulnerability` FOREIGN KEY (`vulnerability_id`) REFERENCES `vulnerabilities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Many-to-many link between vulnerabilities and security incidents';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vulnerability_timeline`
--

DROP TABLE IF EXISTS `vulnerability_timeline`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vulnerability_timeline` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `vulnerability_id` bigint(20) unsigned NOT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `actor_name` varchar(255) DEFAULT NULL,
  `status_from` varchar(50) DEFAULT NULL,
  `status_to` varchar(50) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vt_vuln` (`vulnerability_id`),
  KEY `idx_vt_created` (`created_at`),
  CONSTRAINT `fk_vt_vulnerability` FOREIGN KEY (`vulnerability_id`) REFERENCES `vulnerabilities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Immutable audit timeline of vulnerability lifecycle changes';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workplan_logs`
--

DROP TABLE IF EXISTS `workplan_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `workplan_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `objective_id` int(11) NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action_type` varchar(50) NOT NULL COMMENT 'progress_update|status_change|evidence_upload|objective_update',
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Snapshot of changed fields before the update' CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Snapshot of changed fields after the update' CHECK (json_valid(`new_values`)),
  `progress_percent` tinyint(3) unsigned DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `evidence_path` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wpl_objective` (`objective_id`),
  KEY `idx_wpl_user` (`user_id`),
  KEY `idx_wpl_action_type` (`action_type`),
  KEY `idx_wpl_created_at` (`created_at`),
  CONSTRAINT `fk_wpl_objective` FOREIGN KEY (`objective_id`) REFERENCES `workplan_objectives` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workplan_objective_cycles`
--

DROP TABLE IF EXISTS `workplan_objective_cycles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `workplan_objective_cycles` (
  `id` int(11) NOT NULL,
  `objective_id` int(11) NOT NULL,
  `cycle_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_objective_cycle` (`objective_id`,`cycle_id`),
  KEY `cycle_id` (`cycle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workplan_objectives`
--

DROP TABLE IF EXISTS `workplan_objectives`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `workplan_objectives` (
  `strategic_target_id` int(11) DEFAULT NULL COMMENT 'Organisation-level strategic target',
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `performance_contract_id` int(11) DEFAULT NULL COMMENT 'Department performance contract backing this activity (NULL = organisation-level activity)',
  `objective` text NOT NULL,
  `kpi` varchar(255) NOT NULL,
  `measure_unit` varchar(50) NOT NULL COMMENT 'e.g., Percentage, Number',
  `section_id` int(11) DEFAULT NULL,
  `subsection_id` int(11) DEFAULT NULL,
  `level` enum('organisation','department','section','subsection') DEFAULT NULL COMMENT 'Position in the organisational cascade',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `cycle_ids` varchar(255) DEFAULT NULL,
  `Y1` varchar(255) DEFAULT NULL,
  `Y2` varchar(255) DEFAULT NULL,
  `Y3` varchar(255) DEFAULT NULL,
  `Y4` varchar(255) DEFAULT NULL,
  `Y5` varchar(255) DEFAULT NULL,
  `goal_id` int(11) DEFAULT NULL COMMENT 'Strategic goal perspective (goals.id)',
  `parent_objective_id` int(11) DEFAULT NULL COMMENT 'Self-referencing FK to parent objective',
  `progress_percent` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT '0-100 completion percentage',
  `status` enum('not_started','in_progress','completed','at_risk','off_track') NOT NULL DEFAULT 'not_started',
  `evidence_path` text DEFAULT NULL COMMENT 'Path or reference to uploaded evidence',
  `budget_amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Allocated budget',
  `resource_notes` text DEFAULT NULL COMMENT 'Free-text resource / funding notes',
  `responsible_officer_id` int(11) DEFAULT NULL COMMENT 'Employee responsible (employees.id)',
  `created_by` int(11) DEFAULT NULL COMMENT 'User who created the objective',
  `planned_start_date` date DEFAULT NULL COMMENT 'Plan start date',
  `planned_end_date` date DEFAULT NULL COMMENT 'Plan end date',
  `actual_completion_date` date DEFAULT NULL COMMENT 'Actual completion date',
  `dependencies` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON array of cross-workplan dependency links' CHECK (json_valid(`dependencies`)),
  `is_integrated` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = visible in org-level integrated view',
  `soft_deleted` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Soft-delete flag',
  PRIMARY KEY (`id`),
  KEY `performance_contract_id` (`performance_contract_id`),
  KEY `idx_section_id` (`section_id`),
  KEY `idx_subsection_id` (`subsection_id`),
  KEY `idx_wpo_status` (`status`),
  KEY `idx_wpo_goal` (`goal_id`),
  KEY `idx_wpo_target` (`strategic_target_id`),
  KEY `idx_wpo_parent` (`parent_objective_id`),
  KEY `idx_wpo_officer` (`responsible_officer_id`),
  KEY `idx_wpo_integrated` (`is_integrated`),
  KEY `idx_wpo_soft_deleted` (`soft_deleted`),
  KEY `idx_wpo_level` (`level`),
  KEY `idx_wpo_created_by` (`created_by`),
  CONSTRAINT `fk_wpo_parent_objective` FOREIGN KEY (`parent_objective_id`) REFERENCES `workplan_objectives` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=237 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed
