-- Offer / hiring letters, editable templates and policies (2026-11-12)
--
-- document_templates: recruiter-editable offer letter, hiring/appointment letter and
--   company policy templates (documents.php). Starter templates are inserted by the app
--   on request ("Install starter templates"), not by this migration.
-- offer_letters: one row per issued letter. body_html is an immutable snapshot of the
--   rendered letter so later template edits never alter what a candidate received.
--   token_hash is the SHA-256 of the candidate's portal token (the token itself is
--   never stored). Responses (accept/decline) are recorded with timestamp and IP.
--
-- Backward compatible: new tables only. Candidates move to stage 'Hired' only when a
-- recruiter clicks "Mark as hired"; nothing here changes existing rows.
-- Rollback: database/migrations/20261112_offer_letters_rollback.sql

CREATE TABLE IF NOT EXISTS document_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_type VARCHAR(30) NOT NULL,
  name VARCHAR(160) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_document_templates_type (doc_type, is_active, sort_order)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS offer_letters (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id VARCHAR(32) NOT NULL,
  job_id BIGINT UNSIGNED NULL,
  letter_type VARCHAR(30) NOT NULL DEFAULT 'offer_letter',
  template_id BIGINT UNSIGNED NULL,
  designation VARCHAR(160) NOT NULL,
  ctc_text VARCHAR(120) NULL,
  joining_date DATE NULL,
  location VARCHAR(160) NULL,
  reporting_to VARCHAR(160) NULL,
  respond_by DATE NULL,
  signatory_name VARCHAR(160) NULL,
  signatory_title VARCHAR(160) NULL,
  body_html MEDIUMTEXT NOT NULL,
  policy_ids_json JSON NULL,
  email_subject VARCHAR(255) NULL,
  token_hash CHAR(64) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  sent_at DATETIME NULL,
  viewed_at DATETIME NULL,
  responded_at DATETIME NULL,
  response_name VARCHAR(160) NULL,
  response_note VARCHAR(1000) NULL,
  response_ip VARCHAR(64) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_offer_token (token_hash),
  INDEX idx_offer_candidate (candidate_id, created_at),
  INDEX idx_offer_status (status, responded_at)
) ENGINE=InnoDB;
-- status: draft | sent | accepted | declined | withdrawn
