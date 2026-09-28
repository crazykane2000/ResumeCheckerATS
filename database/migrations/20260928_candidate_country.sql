USE resumeiq;
ALTER TABLE candidates
  ADD COLUMN country_code CHAR(2) NULL AFTER phone,
  ADD COLUMN country_name VARCHAR(100) NULL AFTER country_code,
  ADD INDEX idx_country (country_code);
