USE resumeiq;

ALTER TABLE jobs
  ADD COLUMN budget_max DECIMAL(12,2) NULL AFTER max_experience;

ALTER TABLE candidates
  ADD COLUMN current_ctc DECIMAL(12,2) NULL AFTER country_name,
  ADD COLUMN expected_ctc DECIMAL(12,2) NULL AFTER current_ctc,
  ADD INDEX idx_expected_ctc (expected_ctc);
