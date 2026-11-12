<?php
// Letter downloads.
//   Recruiter (signed in):  offer_file.php?id=<offer id>&format=print|docx|policies
//   Candidate (public):     offer_file.php?token=<portal token>&format=print|docx|policies
// "print" renders a print-ready page that opens the browser's Save-as-PDF dialog.
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/offer_letters.php';

$pdo = db();
$token = (string)($_GET['token'] ?? '');
$format = (string)($_GET['format'] ?? 'print');
if (!in_array($format, ['print', 'docx', 'policies'], true)) $format = 'print';

if ($token !== '') {
    $offer = findOfferByToken($pdo, $token);
    if (!$offer || in_array($offer['status'], ['draft', 'withdrawn'], true)) {
        http_response_code(404);
        exit('This link is invalid or no longer active.');
    }
} else {
    requireAuth();
    $s = $pdo->prepare('SELECT o.*, c.name candidate_name, c.email candidate_email FROM offer_letters o JOIN candidates c ON c.id=o.candidate_id WHERE o.id=?');
    $s->execute([(int)($_GET['id'] ?? 0)]);
    $offer = $s->fetch();
    if (!$offer) {
        http_response_code(404);
        exit('Letter not found.');
    }
}

$brand = organizationBrand();
$policies = json_decode((string)($offer['policy_ids_json'] ?? ''), true) ?: [];
$policiesHtml = '';
foreach ($policies as $p) $policiesHtml .= sanitizeLetterHtml(renderLetterTemplate((string)($p['body_html'] ?? ''), ['company_name' => (string)$brand['name']]));
$letterHtml = sanitizeLetterHtml((string)$offer['body_html']);
$candidateName = (string)$offer['candidate_name'];

try {
    $pdo->prepare('INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,?,?,?,?)')
        ->execute([$token !== '' ? null : (currentUser()['id'] ?? null), 'offer_letter.downloaded', 'candidate', $offer['candidate_id'], json_encode(['offer_id' => (int)$offer['id'], 'format' => $format, 'by' => $token !== '' ? 'candidate' : 'recruiter'])]);
} catch (Throwable $e) {}

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

if ($format === 'docx' || $format === 'policies') {
    $isPolicies = $format === 'policies';
    if ($isPolicies && $policiesHtml === '') {
        http_response_code(404);
        exit('No policies were shared with this letter.');
    }
    $docx = buildLetterDocx($isPolicies ? $policiesHtml : $letterHtml, $brand, $isPolicies ? 'Company policies' : (OFFER_LETTER_TYPES[$offer['letter_type']] ?? 'Letter'));
    $filename = $isPolicies ? 'Company-Policies-' . (trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string)$brand['name']), '-') ?: 'Company') . '.docx' : letterFileName($offer, $candidateName, 'docx');
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($docx));
    echo $docx;
    exit;
}

$title = (OFFER_LETTER_TYPES[$offer['letter_type']] ?? 'Letter') . ' — ' . $candidateName;
$withPolicies = !empty($_GET['policies']) && $policiesHtml !== '';
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?= htmlspecialchars($title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
body{margin:0;background:#f6f7fb;padding:24px 12px;font-family:Inter,Arial,sans-serif}
<?= letterPaperCss() ?>
.print-bar{max-width:780px;margin:0 auto 12px;display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:13px;color:#4b5060}
.print-bar button{background:#6f45ff;color:#fff;border:0;border-radius:8px;padding:9px 14px;font-weight:600;cursor:pointer}
.page-break{page-break-before:always;break-before:page;height:0}
</style></head>
<body>
<div class="print-bar no-print"><span>Use <strong>Save as PDF</strong> as the printer to download a PDF.</span><button type="button" onclick="window.print()">Print / Save as PDF</button></div>
<?= letterPaperHtml($letterHtml, $brand) ?>
<?php if ($withPolicies): ?><div class="page-break"></div><div style="height:16px" class="no-print"></div><?= letterPaperHtml($policiesHtml, $brand) ?><?php endif ?>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body></html>
