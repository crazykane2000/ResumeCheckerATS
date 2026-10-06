ALTER TABLE interview_invitations
  ADD COLUMN notification_status VARCHAR(30) NOT NULL DEFAULT 'not_sent' AFTER confirmed_slot_id,
  ADD COLUMN notification_sent_at DATETIME NULL AFTER notification_status,
  ADD COLUMN notification_error VARCHAR(500) NULL AFTER notification_sent_at,
  ADD COLUMN outcome VARCHAR(30) NULL AFTER notification_error,
  ADD COLUMN outcome_at DATETIME NULL AFTER outcome,
  ADD COLUMN outcome_by BIGINT UNSIGNED NULL AFTER outcome_at,
  ADD COLUMN outcome_notes VARCHAR(1000) NULL AFTER outcome_by,
  ADD INDEX idx_interview_invitation_outcome(outcome,outcome_at),
  ADD CONSTRAINT fk_interview_invitation_outcome_user FOREIGN KEY(outcome_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS interview_outcome_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invitation_id BIGINT UNSIGNED NOT NULL,
  candidate_id VARCHAR(32) NOT NULL,
  actor_id BIGINT UNSIGNED NOT NULL,
  outcome VARCHAR(30) NOT NULL,
  notes VARCHAR(1000) NULL,
  previous_outcome VARCHAR(30) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_interview_outcome_invitation FOREIGN KEY(invitation_id) REFERENCES interview_invitations(id) ON DELETE CASCADE,
  CONSTRAINT fk_interview_outcome_candidate FOREIGN KEY(candidate_id) REFERENCES candidates(id) ON DELETE RESTRICT,
  CONSTRAINT fk_interview_outcome_actor FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_interview_outcome_candidate(candidate_id,created_at),
  INDEX idx_interview_outcome_invitation(invitation_id,created_at)
) ENGINE=InnoDB;
