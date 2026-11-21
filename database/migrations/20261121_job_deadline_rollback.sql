-- Rollback for 20261121_job_deadline.sql. Removes stored deadlines; audit history remains.
ALTER TABLE jobs DROP COLUMN deadline_at;
