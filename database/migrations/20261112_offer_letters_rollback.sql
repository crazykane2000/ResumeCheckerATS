-- Rollback for 20261112_offer_letters.sql.
-- WARNING: drops issued letters and edited templates. Back up both tables first.
DROP TABLE IF EXISTS offer_letters;
DROP TABLE IF EXISTS document_templates;
