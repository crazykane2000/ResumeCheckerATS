-- Issuing company per letter (2026-11-12)
--
-- Letters can be issued by different companies that share one logo (e.g. NonceBlox and
-- Nonce Labs). Company details (name, address, P.O. box, website, email, phone) are
-- edited on documents.php and stored in integrations('letterhead_entities'); each letter
-- records only which company issued it, so later edits to those details apply to every
-- document. NULL = the first configured company (existing letters keep working).
--
-- Backward compatible: one nullable column. Rollback: 20261112_offer_letter_entity_rollback.sql

ALTER TABLE offer_letters
  ADD COLUMN entity_key VARCHAR(40) NULL AFTER letter_type;
