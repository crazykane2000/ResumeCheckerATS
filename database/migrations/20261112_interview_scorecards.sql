-- Interview scorecards (documents an existing table)
--
-- This table was previously created only by a one-off script (scratch/setup_scorecards_table.php)
-- and is used by candidate_detail.php and lib/notifications.php. It already exists on the
-- shared database; this file makes it part of the normal migration set for new installs.

CREATE TABLE IF NOT EXISTS interview_scorecards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    candidate_id VARCHAR(32) NOT NULL,
    job_id BIGINT UNSIGNED NULL,
    interviewer_id BIGINT UNSIGNED NOT NULL,
    technical_rating TINYINT UNSIGNED NOT NULL DEFAULT 3,
    communication_rating TINYINT UNSIGNED NOT NULL DEFAULT 3,
    cultural_rating TINYINT UNSIGNED NOT NULL DEFAULT 3,
    problem_solving_rating TINYINT UNSIGNED NOT NULL DEFAULT 3,
    overall_score DECIMAL(3,1) NOT NULL DEFAULT 3.0,
    strengths_json TEXT NULL,
    weaknesses_json TEXT NULL,
    recommendation VARCHAR(30) NOT NULL DEFAULT 'Hire',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_scorecard_candidate (candidate_id),
    KEY idx_scorecard_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
