<?php
// Recruiter-only view/download of an onboarding document (audited).
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/onboarding.php';

$pdo = db();
$s = $pdo->prepare('SELECT o.id, o.candidate_id, o.item_key, o.file_path, o.original_name, o.mime FROM onboarding_items o WHERE o.id=?');
$s->execute([(int)($_GET['id'] ?? 0)]);
$row = $s->fetch();
$path = $row ? onboardingFilePath($row['file_path']) : null;
if (!$row || !$path) {
    http_response_code(404);
    exit('Document not found.');
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
if (!isset(ONBOARDING_MIME[$mime])) {
    http_response_code(415);
    exit('Unsupported document type.');
}
$download = ($_GET['download'] ?? '') === '1';
try {
    $pdo->prepare("INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,?,'candidate',?,?)")
        ->execute([currentUser()['id'] ?? null, $download ? 'onboarding.document_downloaded' : 'onboarding.document_viewed', $row['candidate_id'], json_encode(['onboarding_item_id' => (int)$row['id'], 'item' => $row['item_key']])]);
} catch (Throwable $e) {}
$name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($row['original_name'] ?: ($row['item_key'] . '.' . ONBOARDING_MIME[$mime])));
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
