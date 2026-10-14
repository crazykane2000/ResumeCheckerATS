-- Migration: Add candidate_notes table for round interview feedback & chronological evaluation timeline
CREATE TABLE IF NOT EXISTS candidate_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id VARCHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  author_name VARCHAR(120) NOT NULL,
  stage VARCHAR(50) NOT NULL,
  feedback TEXT NOT NULL,
  strengths TEXT NULL,
  recommendation VARCHAR(50) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cand_notes (candidate_id, created_at)
) ENGINE=InnoDB;
