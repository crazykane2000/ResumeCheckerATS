-- Rollback for 20261112_candidate_ctc_provenance.sql (drops only the added columns).
ALTER TABLE candidates
  DROP COLUMN ctc_source,
  DROP COLUMN expected_ctc_raw,
  DROP COLUMN current_ctc_raw;
