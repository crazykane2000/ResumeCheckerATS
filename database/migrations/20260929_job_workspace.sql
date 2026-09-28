ALTER TABLE jobs
  ADD COLUMN external_id BIGINT NULL AFTER id,
  ADD COLUMN public_url VARCHAR(1000) NULL AFTER description,
  ADD COLUMN location VARCHAR(180) NULL AFTER public_url,
  ADD COLUMN employment_type VARCHAR(80) NULL AFTER location,
  ADD COLUMN min_experience DECIMAL(4,1) NULL AFTER employment_type,
  ADD COLUMN max_experience DECIMAL(4,1) NULL AFTER min_experience,
  ADD COLUMN required_skills_json JSON NULL AFTER max_experience,
  ADD COLUMN preferred_skills_json JSON NULL AFTER required_skills_json,
  ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'open' AFTER preferred_skills_json,
  ADD UNIQUE KEY uniq_jobs_external_id (external_id);
ALTER TABLE candidates ADD COLUMN job_id BIGINT UNSIGNED NULL AFTER id, ADD INDEX idx_candidates_job_id(job_id);
ALTER TABLE wishlist_profiles ADD COLUMN job_id BIGINT UNSIGNED NULL AFTER id, ADD INDEX idx_profiles_job_id(job_id);
CREATE TABLE IF NOT EXISTS audit_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NULL,action VARCHAR(80) NOT NULL,entity_type VARCHAR(50) NOT NULL,entity_id VARCHAR(64) NULL,metadata_json JSON NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_audit_entity(entity_type,entity_id),INDEX idx_audit_created(created_at)) ENGINE=InnoDB;
