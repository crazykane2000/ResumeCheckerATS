CREATE TABLE IF NOT EXISTS interview_batches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_id BIGINT UNSIGNED NOT NULL, profile_id BIGINT UNSIGNED NULL, email_batch_id BIGINT UNSIGNED NULL, created_by BIGINT UNSIGNED NOT NULL, name VARCHAR(180) NOT NULL,
 availability_start DATE NOT NULL, availability_end DATE NOT NULL, daily_start TIME NOT NULL, daily_end TIME NOT NULL, slot_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30, buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0, timezone VARCHAR(80) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'active',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_interview_batch_job FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE RESTRICT,
 CONSTRAINT fk_interview_batch_profile FOREIGN KEY(profile_id) REFERENCES wishlist_profiles(id) ON DELETE SET NULL,
 CONSTRAINT fk_interview_batch_email FOREIGN KEY(email_batch_id) REFERENCES email_batches(id) ON DELETE SET NULL,
 CONSTRAINT fk_interview_batch_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
 INDEX idx_interview_batch_window(availability_start,availability_end), INDEX idx_interview_batch_job_status(job_id,status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS interview_invitations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, batch_id BIGINT UNSIGNED NOT NULL, candidate_id VARCHAR(32) NOT NULL, email_recipient_id BIGINT UNSIGNED NULL, token_hash CHAR(64) NOT NULL UNIQUE,
 status VARCHAR(30) NOT NULL DEFAULT 'draft', recipient_email VARCHAR(190) NOT NULL, subject VARCHAR(255) NOT NULL, html_snapshot MEDIUMTEXT NOT NULL, sent_at DATETIME NULL, expires_at DATETIME NOT NULL, confirmed_slot_id BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_interview_invitation_batch FOREIGN KEY(batch_id) REFERENCES interview_batches(id) ON DELETE CASCADE,
 CONSTRAINT fk_interview_invitation_candidate FOREIGN KEY(candidate_id) REFERENCES candidates(id) ON DELETE RESTRICT,
 CONSTRAINT fk_interview_invitation_recipient FOREIGN KEY(email_recipient_id) REFERENCES email_recipients(id) ON DELETE SET NULL,
 UNIQUE KEY uniq_interview_batch_candidate(batch_id,candidate_id), INDEX idx_interview_invitation_status(status,expires_at), INDEX idx_interview_invitation_candidate(candidate_id,created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS interview_slots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, batch_id BIGINT UNSIGNED NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'available', invitation_id BIGINT UNSIGNED NULL, reserved_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_interview_slot_batch FOREIGN KEY(batch_id) REFERENCES interview_batches(id) ON DELETE CASCADE,
 CONSTRAINT fk_interview_slot_invitation FOREIGN KEY(invitation_id) REFERENCES interview_invitations(id) ON DELETE SET NULL,
 UNIQUE KEY uniq_interview_batch_start(batch_id,starts_at), INDEX idx_interview_slot_status(batch_id,status,starts_at)
) ENGINE=InnoDB;

ALTER TABLE interview_invitations ADD CONSTRAINT fk_interview_invitation_slot FOREIGN KEY(confirmed_slot_id) REFERENCES interview_slots(id) ON DELETE SET NULL;
