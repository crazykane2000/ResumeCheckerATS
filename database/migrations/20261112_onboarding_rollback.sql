-- Rollback for 20261112_onboarding.sql.
-- WARNING: drops submitted onboarding records. Back up the table and uploads/onboarding first.
ALTER TABLE offer_letters DROP COLUMN salary_breakup_json;
DROP TABLE IF EXISTS onboarding_items;
