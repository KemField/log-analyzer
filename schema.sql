-- L2 Support Log Analyzer & Incident Dashboard Database Schema
-- Target Database: support_dashboard

CREATE DATABASE IF NOT EXISTS `support_dashboard` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `support_dashboard`;

-- --------------------------------------------------------
-- Table structure for table `logs`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `timestamp` DATETIME NOT NULL,
  `severity` ENUM('CRITICAL', 'ERROR', 'WARNING') NOT NULL,
  `status_code` INT DEFAULT NULL,
  `error_message` TEXT NOT NULL,
  `file_path` VARCHAR(255) DEFAULT NULL,
  `client_ip` VARCHAR(45) DEFAULT NULL,
  `status` ENUM('NEW', 'ESCALATED', 'RESOLVED') NOT NULL DEFAULT 'NEW',
  -- Unique key to prevent duplicates during multiple parser runs
  UNIQUE KEY `unique_log_constraint` (`timestamp`, `severity`, `status_code`, `client_ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `incidents`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `incidents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `log_id` INT NOT NULL,
  `priority` ENUM('HIGH', 'MEDIUM', 'LOW') NOT NULL,
  `developer_notes` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`log_id`) REFERENCES `logs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
