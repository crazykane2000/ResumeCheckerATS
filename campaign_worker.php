<?php
// CLI & Cron Background Worker for Hiring Outreach Campaigns
// Run via CLI/Cron: php campaign_worker.php
// Or web request: campaign_worker.php?key=<CRON_SECRET>
// Only campaigns in 'sending' status are processed (drafts are never sent).

if (php_sapi_name() !== 'cli') {
    // Web access is disabled unless a CRON_SECRET environment value is configured.
    // (Previously a default key was hard-coded here and published in the repository.)
    $expectedKey = (string)getenv('CRON_SECRET');
    $givenKey = (string)($_GET['key'] ?? $_POST['key'] ?? '');
    if ($expectedKey === '' || !hash_equals($expectedKey, $givenKey)) {
        http_response_code(403);
        header('Content-Type: application/json');
        exit(json_encode(['error' => 'Unauthorized worker access']));
    }
}

// lib/outreach_campaign.php loads config/database.php and the mailers it needs.
// (The previous require of the non-existent lib/database.php made every run fatal.)
require_once __DIR__ . '/lib/outreach_campaign.php';

$pdo = db();
$maxBatches = 5;
$batchSize = 20;
$totalProcessed = 0;

for ($i = 0; $i < $maxBatches; $i++) {
    $processed = processOutreachBatch($pdo, $batchSize);
    $totalProcessed += $processed;
    if ($processed === 0) {
        break;
    }
    usleep(200000); // 200ms pause between sub-batches
}

if (php_sapi_name() === 'cli') {
    echo "[" . date('Y-m-d H:i:s') . "] Outreach Campaign Worker executed. Processed {$totalProcessed} recipients.\n";
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'processed' => $totalProcessed, 'timestamp' => date(DATE_ATOM)]);
}
