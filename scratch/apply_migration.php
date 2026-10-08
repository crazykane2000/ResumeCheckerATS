<?php
require_once __DIR__ . '/../lib/auth.php';

try {
    $pdo = db();
    $sql = file_get_contents(__DIR__ . '/../database/migrations/20261008_candidate_job_budget_ctc.sql');
    // PDO exec multi-queries might fail if not split or if driver requires single statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if ($stmt !== '' && !preg_match('/^USE\s+/i', $stmt)) {
            try {
                $pdo->exec($stmt);
            } catch (Throwable $ex) {
                // If column already exists ignore error
                if (!str_contains($ex->getMessage(), 'Duplicate column name') && !str_contains($ex->getMessage(), 'already exists')) {
                    throw $ex;
                }
            }
        }
    }
    echo "SUCCESS\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
