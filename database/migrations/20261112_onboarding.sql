-- Post-offer onboarding and salary breakup (2026-11-12)
--
-- onboarding_items: one row per checklist item submitted by the candidate on the offer
--   portal (documents and bank details). Files are stored under uploads/onboarding/<offer id>/
--   with random names and are never web-served; bank account numbers are encrypted in
--   data_json and only the last 4 digits are shown. Recruiters verify or reject each item.
-- offer_letters.salary_breakup_json: optional annexure of CTC components.
--
-- Backward compatible: one new table and one nullable column. No existing data changes.
-- Rollback: 20261112_onboarding_rollback.sql

CREATE TABLE IF NOT EXISTS onboarding_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  offer_id BIGINT UNSIGNED NOT NULL,
  candidate_id VARCHAR(32) NOT NULL,
  item_key VARCHAR(40) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'submitted',
  file_path VARCHAR(255) NULL,
  original_name VARCHAR(190) NULL,
  mime VARCHAR(60) NULL,
  size_bytes INT UNSIGNED NULL,
  data_json JSON NULL,
  submitted_at DATETIME NULL,
  reviewed_at DATETIME NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  review_note VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_onboarding_offer_item (offer_id, item_key),
  INDEX idx_onboarding_candidate (candidate_id),
  INDEX idx_onboarding_status (status, submitted_at)
) ENGINE=InnoDB;
-- status: submitted | verified | rejected

ALTER TABLE offer_letters
  ADD COLUMN salary_breakup_json JSON NULL AFTER ctc_text;
