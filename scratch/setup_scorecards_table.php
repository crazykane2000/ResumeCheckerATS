<?php
require_once __DIR__ . '/../lib/auth.php';
$pdo = db();

$sql = "
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
";

try {
    $pdo->exec($sql);
    echo "SUCCESS: interview_scorecards table created/verified.\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
