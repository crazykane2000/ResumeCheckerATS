USE resumeiq;
CREATE TABLE IF NOT EXISTS interview_invite_locks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profile_id BIGINT UNSIGNED NOT NULL,
  candidate_id VARCHAR(32) NOT NULL,
  batch_id BIGINT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  locked_at DATETIME NOT NULL,
  reset_at DATETIME NULL,
  reset_by BIGINT UNSIGNED NULL,
  CONSTRAINT fk_invite_lock_profile FOREIGN KEY(profile_id) REFERENCES wishlist_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_invite_lock_batch FOREIGN KEY(batch_id) REFERENCES email_batches(id) ON DELETE SET NULL,
  CONSTRAINT fk_invite_lock_reset_user FOREIGN KEY(reset_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_profile_invite_candidate(profile_id,candidate_id),
  INDEX idx_invite_locks_active(profile_id,active)
) ENGINE=InnoDB;

INSERT INTO interview_invite_locks(profile_id,candidate_id,batch_id,active,locked_at)
SELECT eb.profile_id,er.candidate_id,MAX(er.batch_id),1,MAX(COALESCE(er.sent_at,eb.created_at))
FROM email_recipients er
JOIN email_batches eb ON eb.id=er.batch_id
WHERE er.status='sent' AND eb.profile_id IS NOT NULL
GROUP BY eb.profile_id,er.candidate_id
ON DUPLICATE KEY UPDATE batch_id=VALUES(batch_id),active=1,locked_at=VALUES(locked_at);
