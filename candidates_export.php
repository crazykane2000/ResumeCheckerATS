<?php
/**
 * Candidate master DB export as CSV (opens in Excel). Owner-controlled and audited.
 *   candidates_export.php            all candidates
 *   candidates_export.php?job=12     one job's applicants
 *   candidates_export.php?stage=...  optional stage filter
 */
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/workspace.php';
require_once __DIR__ . '/lib/candidate_export.php';

$pdo = db();
$user = currentUser();

if (!candidateExportAllowed($pdo, $user)) {
    $pdo->prepare("INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,'candidate.export_denied','workspace','candidates',?)")
        ->execute([$user['id'], json_encode(['outcome' => 'denied'])]);
    http_response_code(403);
    exit('Candidate export is restricted to the workspace owner.');
}

$jobId = max(0, (int)($_GET['job'] ?? 0));
$stage = trim((string)($_GET['stage'] ?? ''));
$allowedStages = ['Applied', 'Screening', 'Invited', 'Interview', 'Round 1', 'Round 2', 'Offer', 'Rejected', 'Hired'];
if (!in_array($stage, $allowedStages, true)) {
    $stage = '';
}

$where = [];
$params = [];
if ($jobId > 0) {
    $where[] = 'c.job_id = ?';
    $params[] = $jobId;
}
if ($stage !== '') {
    $where[] = 'c.stage = ?';
    $params[] = $stage;
}
$sql = 'SELECT c.id, c.name, c.email, c.phone, c.country_name, c.role_title, c.stage, c.source_name, c.source_url,
               c.current_ctc, c.expected_ctc, c.skills_json, c.experience_json, c.created_at, j.title AS job_title
        FROM candidates c LEFT JOIN jobs j ON j.id = c.job_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY c.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$jobSlug = 'all';
if ($jobId > 0) {
    $t = $pdo->prepare('SELECT title FROM jobs WHERE id = ?');
    $t->execute([$jobId]);
    $jobSlug = trim(preg_replace('/[^a-z0-9]+/i', '-', (string)$t->fetchColumn()), '-') ?: 'job-' . $jobId;
}
$filename = 'candidates-' . strtolower($jobSlug) . ($stage !== '' ? '-' . strtolower(str_replace(' ', '-', $stage)) : '') . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows names correctly
fputcsv($out, candidateExportHeaders());
$count = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, candidateExportRow($row));
    $count++;
}
fclose($out);

$pdo->prepare("INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,'candidate.exported','workspace','candidates',?)")
    ->execute([$user['id'], json_encode(['job_id' => $jobId ?: null, 'stage' => $stage ?: null, 'rows' => $count, 'format' => 'csv', 'outcome' => 'success'])]);
