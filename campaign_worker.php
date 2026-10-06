<?php
// CLI & Cron Background Worker for Hiring Outreach Campaigns
// Run via CLI/Cron: php campaign_worker.php
// Or web request: campaign_worker.php?key=<CRON_SECRET>

if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/config/database.php';
    // Web request security check if called via HTTP
    $expectedKey = getenv('CRON_SECRET') ?: 'nonceblox_outreach_worker_2026';
    $givenKey = $_GET['key'] ?? $_POST['key'] ?? '';
    if (!hash_equals($expectedKey, (string)$givenKey)) {
        http_response_code(403);
        exit(json_encode(['error' => 'Unauthorized worker access']));
    }
}

require_once __DIR__ . '/lib/database.php';
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
