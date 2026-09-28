USE resumeiq;
ALTER TABLE candidates ADD COLUMN source_url VARCHAR(1000) NULL AFTER analysis_json;
UPDATE candidates SET source_url=stored_file WHERE stored_file LIKE 'http://%' OR stored_file LIKE 'https://%';
