-- Job application deadline (recruiter feedback: highlight urgency per role, allow extension).
--
-- Backward compatible: adds one nullable column. Existing jobs keep NULL (= no deadline).
-- The application checks for this column and hides the deadline UI until it exists.
-- Extending a deadline is an UPDATE of this value; each change is written to audit_events
-- ('job.deadline_changed') with the old and new date, so history is not lost.

ALTER TABLE jobs ADD COLUMN deadline_at DATE NULL AFTER status;
