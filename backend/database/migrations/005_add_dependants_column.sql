-- Migration: Add dependants column, expand next_of_kin, create dependants table
-- Fixes 500 errors and "Employee not found" issues

-- 1. Add dependants column to employees table (TEXT to store JSON array - backward compat)
ALTER TABLE `employees` 
ADD COLUMN IF NOT EXISTS `dependants` TEXT NULL AFTER `next_of_kin`;

-- 2. Expand next_of_kin column from varchar(50) to TEXT to store JSON data
ALTER TABLE `employees` 
MODIFY COLUMN `next_of_kin` TEXT NULL;

-- 3. Create dependencies table (matches existing database schema)
CREATE TABLE IF NOT EXISTS `dependencies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `relationship` varchar(100) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `id_no` varchar(50) DEFAULT NULL,
  `contact` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `dependencies_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
