-- Migration: Hiring Outreach Campaign Tables
-- Created: 2026-10-06

CREATE TABLE IF NOT EXISTS `email_campaigns` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_uuid` char(36) NOT NULL,
  `name` varchar(190) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `template_key` varchar(80) NOT NULL DEFAULT 'hiring_outreach_dark_v1',
  `template_version` varchar(20) NOT NULL DEFAULT '1.0.0',
  `html_snapshot` mediumtext NOT NULL,
  `text_snapshot` mediumtext NOT NULL,
  `career_url` varchar(1000) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `status` enum('draft','queued','sending','paused','completed','completed_with_errors','cancelled') NOT NULL DEFAULT 'draft',
  `total_recipients` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `pending_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `processing_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `sent_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `failed_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `skipped_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `suppressed_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `paused_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_campaign_uuid` (`campaign_uuid`),
  KEY `idx_status` (`status`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_campaign_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint(20) UNSIGNED NOT NULL,
  `job_id` bigint(20) UNSIGNED NOT NULL,
  `job_title_snapshot` varchar(190) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_campaign_job` (`campaign_id`, `job_id`),
  KEY `idx_job_id` (`job_id`),
  CONSTRAINT `fk_campaign_jobs_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `email_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_campaign_recipients` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint(20) UNSIGNED NOT NULL,
  `candidate_id` varchar(32) NOT NULL,
  `candidate_name_snapshot` varchar(160) NOT NULL,
  `email` varchar(190) NOT NULL,
  `status` enum('pending','processing','sent','failed','retry','skipped','suppressed','cancelled') NOT NULL DEFAULT 'pending',
  `attempt_count` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `last_attempt_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `provider_message_id` varchar(190) DEFAULT NULL,
  `error_code` varchar(80) DEFAULT NULL,
  `error_message` varchar(500) DEFAULT NULL,
  `idempotency_key` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_campaign_candidate` (`campaign_id`, `candidate_id`),
  UNIQUE KEY `idx_idempotency` (`idempotency_key`),
  KEY `idx_campaign_status` (`campaign_id`, `status`),
  KEY `idx_candidate_id` (`candidate_id`),
  KEY `idx_email` (`email`),
  CONSTRAINT `fk_campaign_recipients_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `email_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_suppressions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `reason` enum('unsubscribed','hard_bounce','invalid','manual','complaint') NOT NULL DEFAULT 'manual',
  `source` varchar(100) NOT NULL DEFAULT 'system',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_suppressed_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
