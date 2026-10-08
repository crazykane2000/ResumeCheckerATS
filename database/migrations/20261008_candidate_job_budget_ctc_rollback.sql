USE resumeiq;

ALTER TABLE jobs
  DROP COLUMN budget_max;

ALTER TABLE candidates
  DROP INDEX idx_expected_ctc,
  DROP COLUMN current_ctc,
  DROP COLUMN expected_ctc;
