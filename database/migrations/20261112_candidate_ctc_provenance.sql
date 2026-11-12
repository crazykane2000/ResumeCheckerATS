-- Candidate CTC provenance (2026-11-12)
--
-- Why: current_ctc / expected_ctc hold a normalised LPA number but not what the
-- candidate actually wrote or where it came from. NonceBlox application forms
-- carry free-text CTC ("15,00,000", "6LPA", "$87K", "NA"), and the legacy resume
-- regex produced noise (years such as 2024). Keeping the raw text and source lets
-- recruiters verify values and lets the UI separate stated figures from estimates.
--
-- Backward compatible: adds nullable columns only; no existing data is changed.
-- Rollback: database/migrations/20261112_candidate_ctc_provenance_rollback.sql

ALTER TABLE candidates
  ADD COLUMN current_ctc_raw VARCHAR(60) NULL AFTER expected_ctc,
  ADD COLUMN expected_ctc_raw VARCHAR(60) NULL AFTER current_ctc_raw,
  ADD COLUMN ctc_source VARCHAR(30) NULL AFTER expected_ctc_raw;
-- ctc_source values: 'nonceblox_form' (candidate-stated on the application form)
--                    'resume_text'    (regex-extracted from resume text; verify)
