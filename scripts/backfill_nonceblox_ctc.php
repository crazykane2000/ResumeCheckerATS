<?php
// Backfill candidate CTC from the NonceBlox application form (career_request.ctc / exctc).
//
//   php scripts/backfill_nonceblox_ctc.php           # dry run: report only, writes nothing
//   php scripts/backfill_nonceblox_ctc.php --apply   # write changes + one audit event
//
// Requires migration database/migrations/20261112_candidate_ctc_provenance.sql.
// Also tags existing resume-derived CTC on direct uploads as ctc_source='resume_text'
// (values are kept, not deleted) so the UI can label them "verify".
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../lib/integrations.php';
require_once __DIR__ . '/../lib/ctc.php';

$apply = in_array('--apply', $argv, true);
$config = integrationConfig('nonceblox_mysql');
if (($config['status'] ?? '') !== 'configured') { fwrite(STDERR, "NonceBlox source is not configured.\n"); exit(1); }
$remote = new PDO('mysql:host=' . $config['host'] . ';port=' . ($config['port'] ?: 3306) . ';dbname=' . $config['database'] . ';charset=utf8mb4', $config['username'], integrationSecret($config, 'password'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 8]);
$local = db();

$known = array_flip($local->query("SELECT id FROM candidates WHERE id LIKE 'WEB-%'")->fetchAll(PDO::FETCH_COLUMN));
$rows = $remote->query('SELECT id,ctc,exctc,country FROM career_request ORDER BY id')->fetchAll();

$stats = ['matched' => 0, 'expected' => [], 'current' => []];
$updates = [];
foreach ($rows as $row) {
    $id = 'WEB-' . substr(hash('sha256', 'nonceblox-career-' . $row['id']), 0, 20);
    if (!isset($known[$id])) continue;
    $stats['matched']++;
    $cur = normalizeCtcLpa($row['ctc'] ?? null, $row['country'] ?? null);
    $exp = normalizeCtcLpa($row['exctc'] ?? null, $row['country'] ?? null);
    $stats['current'][$cur['status']] = ($stats['current'][$cur['status']] ?? 0) + 1;
    $stats['expected'][$exp['status']] = ($stats['expected'][$exp['status']] ?? 0) + 1;
    $updates[] = [
        $cur['lpa'], $exp['status'] === 'stated' ? $exp['lpa'] : null,
        $cur['raw'] !== '' ? $cur['raw'] : null, $exp['raw'] !== '' ? $exp['raw'] : null,
        $id,
    ];
}
$resumeTagged = (int)$local->query("SELECT COUNT(*) FROM candidates WHERE id NOT LIKE 'WEB-%' AND ctc_source IS NULL AND (current_ctc IS NOT NULL OR expected_ctc IS NOT NULL)")->fetchColumn();

echo ($apply ? 'APPLY' : 'DRY RUN') . "\n";
echo "web_candidates_matched={$stats['matched']}\n";
echo 'expected_ctc: ' . json_encode($stats['expected']) . "\n";
echo 'current_ctc:  ' . json_encode($stats['current']) . "\n";
echo "direct_uploads_to_tag_resume_text={$resumeTagged}\n";
if (!$apply) { echo "No changes written. Re-run with --apply.\n"; exit(0); }

$local->beginTransaction();
try {
    $stmt = $local->prepare("UPDATE candidates SET current_ctc=?,expected_ctc=?,current_ctc_raw=?,expected_ctc_raw=?,ctc_source='nonceblox_form' WHERE id=?");
    foreach ($updates as $u) $stmt->execute($u);
    $local->exec("UPDATE candidates SET ctc_source='resume_text' WHERE id NOT LIKE 'WEB-%' AND ctc_source IS NULL AND (current_ctc IS NOT NULL OR expected_ctc IS NOT NULL)");
    $actorId = $local->query('SELECT MIN(id) FROM users')->fetchColumn() ?: null;
    $local->prepare('INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,?,?,?,?)')->execute([$actorId, 'candidate_ctc_backfill_completed', 'integration', 'nonceblox_mysql', json_encode(['web_candidates' => count($updates), 'expected' => $stats['expected'], 'current' => $stats['current'], 'resume_text_tagged' => $resumeTagged, 'script' => 'backfill_nonceblox_ctc', 'outcome' => 'success'])]);
    $local->commit();
    echo "Applied.\n";
} catch (Throwable $e) {
    $local->rollBack();
    fwrite(STDERR, 'Backfill failed: ' . $e->getMessage() . "\n");
    exit(1);
}
