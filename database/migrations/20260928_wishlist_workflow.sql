USE resumeiq;
ALTER TABLE wishlist_items
  ADD COLUMN disposition VARCHAR(30) NOT NULL DEFAULT 'wishlist' AFTER candidate_id,
  ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD INDEX idx_wishlist_disposition (profile_id, disposition);
ALTER TABLE email_batches
  ADD COLUMN interview_at DATETIME NULL AFTER body,
  ADD COLUMN timezone VARCHAR(80) NULL AFTER interview_at;
