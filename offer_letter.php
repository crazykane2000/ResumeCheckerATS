<?php
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/workspace.php';
require_once __DIR__ . '/lib/offer_letters.php';
require_once __DIR__ . '/lib/lifecycle_mailer.php';

$pdo = db();
$user = currentUser();
$brand = organizationBrand();
$candidateId = trim((string)($_GET['candidate_id'] ?? $_POST['candidate_id'] ?? ''));
$message = '';
$error = '';

$rows = $candidateId !== '' ? loadCandidateRecords($candidateId) : [];
$candidate = $rows[0] ?? null;
if (!$candidate) {
    http_response_code(404);
    exit('Candidate not found.');
}
try {
    $pdo->query('SELECT 1 FROM offer_letters LIMIT 1');
} catch (Throwable $e) {
    exit('Offer letters are not installed yet. Apply database/migrations/20261112_offer_letters.sql first.');
}

$audit = static function (string $action, string $entityType, string $entityId, array $meta) use ($pdo, $user): void {
    try {
        $pdo->prepare('INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,?,?,?,?)')
            ->execute([$user['id'] ?? null, $action, $entityType, $entityId, json_encode($meta)]);
    } catch (Throwable $e) {}
};
$loadOffer = static function (int $id) use ($pdo, $candidateId): ?array {
    $s = $pdo->prepare('SELECT * FROM offer_letters WHERE id=? AND candidate_id=?');
    $s->execute([$id, $candidateId]);
    return $s->fetch() ?: null;
};
$policySnapshot = static function (array $ids) use ($pdo): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $s = $pdo->prepare('SELECT id,name,body_html FROM document_templates WHERE doc_type=\'policy\' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY sort_order,id');
    $s->execute($ids);
    return array_map(static fn($p) => ['id' => (int)$p['id'], 'name' => (string)$p['name'], 'body_html' => sanitizeLetterHtml((string)$p['body_html'])], $s->fetchAll());
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Invalid request.');
    }
    $action = (string)($_POST['action'] ?? '');
    $offerId = (int)($_POST['offer_id'] ?? 0);
    try {
        if (in_array($action, ['save_draft', 'send'], true)) {
            $type = (string)($_POST['letter_type'] ?? 'offer_letter');
            if (!isset(OFFER_LETTER_TYPES[$type])) throw new RuntimeException('Choose a valid letter type.');
            $designation = trim((string)($_POST['designation'] ?? ''));
            if ($designation === '' || mb_strlen($designation) > 160) throw new RuntimeException('Designation is required.');
            $dateOrNull = static function (string $key): ?string {
                $v = trim((string)($_POST[$key] ?? ''));
                if ($v === '') return null;
                $d = DateTime::createFromFormat('Y-m-d', $v);
                if (!$d || $d->format('Y-m-d') !== $v) throw new RuntimeException('Invalid date for ' . str_replace('_', ' ', $key) . '.');
                return $v;
            };
            $body = sanitizeLetterHtml((string)($_POST['body_html'] ?? ''));
            if (trim(strip_tags($body)) === '') throw new RuntimeException('The letter is empty. Choose a template or write the letter.');
            if (preg_match('/\{\{\s*[a-z_]+\s*\}\}/', $body)) throw new RuntimeException('The letter still contains unfilled {{placeholders}}. Fill the fields or edit the text.');
            $fields = [
                'letter_type' => $type,
                'template_id' => (int)($_POST['template_id'] ?? 0) ?: null,
                'designation' => $designation,
                'ctc_text' => mb_substr(trim((string)($_POST['ctc_text'] ?? '')), 0, 120) ?: null,
                'joining_date' => $dateOrNull('joining_date'),
                'location' => mb_substr(trim((string)($_POST['location'] ?? '')), 0, 160) ?: null,
                'reporting_to' => mb_substr(trim((string)($_POST['reporting_to'] ?? '')), 0, 160) ?: null,
                'respond_by' => $dateOrNull('respond_by'),
                'signatory_name' => mb_substr(trim((string)($_POST['signatory_name'] ?? '')), 0, 160) ?: null,
                'signatory_title' => mb_substr(trim((string)($_POST['signatory_title'] ?? '')), 0, 160) ?: null,
                'body_html' => $body,
                'policy_ids_json' => json_encode($policySnapshot((array)($_POST['policy_ids'] ?? []))),
                'email_subject' => mb_substr(trim((string)($_POST['email_subject'] ?? '')), 0, 255) ?: null,
            ];
            if ($offerId > 0) {
                $existing = $loadOffer($offerId);
                if (!$existing || $existing['status'] !== 'draft') throw new RuntimeException('Only draft letters can be edited. Create a new letter instead.');
                $set = implode(',', array_map(static fn($k) => $k . '=?', array_keys($fields)));
                $pdo->prepare("UPDATE offer_letters SET $set WHERE id=? AND status='draft'")->execute([...array_values($fields), $offerId]);
            } else {
                $cols = array_keys($fields);
                $pdo->prepare('INSERT INTO offer_letters(candidate_id,job_id,created_by,status,' . implode(',', $cols) . ') VALUES(?,?,?,\'draft\',' . implode(',', array_fill(0, count($cols), '?')) . ')')
                    ->execute([$candidateId, $candidate['job_id'] ?: null, $user['id'], ...array_values($fields)]);
                $offerId = (int)$pdo->lastInsertId();
                $audit('offer_letter.draft_created', 'candidate', $candidateId, ['offer_id' => $offerId, 'letter_type' => $type]);
            }
            if ($action === 'save_draft') {
                header('Location: offer_letter.php?candidate_id=' . urlencode($candidateId) . '&offer_id=' . $offerId . '&saved=1');
                exit;
            }
        }

        if ($action === 'send' || $action === 'resend') {
            $offer = $loadOffer($offerId);
            if (!$offer) throw new RuntimeException('Letter not found.');
            if ($action === 'send' && $offer['status'] !== 'draft') throw new RuntimeException('This letter was already sent. Use "Resend link" instead.');
            if ($action === 'resend' && $offer['status'] !== 'sent') throw new RuntimeException('Only letters awaiting a response can be resent.');
            $to = trim((string)($candidate['email'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('This candidate has no valid email address.');
            if ($action === 'send') {
                $dup = $pdo->prepare("SELECT id FROM offer_letters WHERE candidate_id=? AND letter_type=? AND status='sent' AND id<>? LIMIT 1");
                $dup->execute([$candidateId, $offer['letter_type'], $offerId]);
                if ($dup->fetchColumn()) throw new RuntimeException('Another ' . strtolower(OFFER_LETTER_TYPES[$offer['letter_type']]) . ' is already awaiting a response. Withdraw it first.');
            }
            [$token, $hash] = newOfferToken();
            $link = offerPublicLink($token);
            $subject = $offer['email_subject'] ?: (($offer['letter_type'] === 'offer_letter' ? 'Your offer from ' : 'Your appointment letter from ') . $brand['name'] . ' — ' . $offer['designation']);
            $html = offerEmailHtml($offer, $candidate, $brand, $link);
            $result = sendLifecycleEmail($pdo, [
                'recipient_email' => $to, 'candidate_id' => $candidateId, 'stage' => 'Offer',
                'template_key' => $offer['letter_type'], 'subject' => $subject, 'html' => $html, 'user_id' => (int)$user['id'],
            ]);
            if (empty($result['ok'])) throw new RuntimeException('Email could not be sent: ' . ($result['error'] ?? 'unknown error') . '. The letter was saved as a draft.');
            $pdo->prepare("UPDATE offer_letters SET status='sent',token_hash=?,sent_at=NOW(),email_subject=? WHERE id=?")->execute([$hash, $subject, $offerId]);
            if ($offer['letter_type'] === 'offer_letter' && !in_array(strtolower((string)$candidate['stage']), ['offer', 'hired'], true)) {
                $pdo->prepare("UPDATE candidates SET stage='Offer' WHERE id=?")->execute([$candidateId]);
                $audit('candidate.stage_changed', 'candidate', $candidateId, ['old_stage' => $candidate['stage'], 'new_stage' => 'Offer', 'reason' => 'offer_letter_sent', 'offer_id' => $offerId]);
            }
            $audit($action === 'send' ? 'offer_letter.sent' : 'offer_letter.resent', 'candidate', $candidateId, ['offer_id' => $offerId, 'letter_type' => $offer['letter_type'], 'transport' => $result['transport'] ?? null, 'link_host' => parse_url($link, PHP_URL_HOST)]);
            header('Location: offer_letter.php?candidate_id=' . urlencode($candidateId) . '&offer_id=' . $offerId . '&sent=1');
            exit;
        }

        if ($action === 'withdraw') {
            $offer = $loadOffer($offerId);
            if (!$offer || !in_array($offer['status'], ['sent', 'draft'], true)) throw new RuntimeException('Only draft or pending letters can be withdrawn.');
            $pdo->prepare("UPDATE offer_letters SET status='withdrawn' WHERE id=?")->execute([$offerId]);
            $audit('offer_letter.withdrawn', 'candidate', $candidateId, ['offer_id' => $offerId, 'previous_status' => $offer['status']]);
            header('Location: offer_letter.php?candidate_id=' . urlencode($candidateId) . '&withdrawn=1');
            exit;
        }

        if ($action === 'mark_hired') {
            $offer = $loadOffer($offerId);
            $offline = !empty($_POST['accepted_offline']);
            if (!$offer || $offer['letter_type'] !== 'offer_letter') throw new RuntimeException('Choose the offer letter this hire is based on.');
            if ($offer['status'] !== 'accepted' && !($offline && $offer['status'] === 'sent')) throw new RuntimeException('The candidate has not accepted this offer yet. Tick "accepted outside the portal" if they confirmed by phone or email.');
            if ($offline && $offer['status'] === 'sent') {
                $pdo->prepare("UPDATE offer_letters SET status='accepted',responded_at=NOW(),response_name=?,response_note=? WHERE id=?")
                    ->execute(['Recorded by ' . ($user['name'] ?? 'recruiter'), 'Accepted outside the portal (recorded by recruiter).', $offerId]);
                $audit('offer_letter.accepted_offline', 'candidate', $candidateId, ['offer_id' => $offerId]);
            }
            $pdo->prepare("UPDATE candidates SET stage='Hired' WHERE id=?")->execute([$candidateId]);
            $audit('candidate.hired', 'candidate', $candidateId, ['offer_id' => $offerId, 'previous_stage' => $candidate['stage'], 'joining_date' => $offer['joining_date'], 'designation' => $offer['designation']]);
            header('Location: offer_letter.php?candidate_id=' . urlencode($candidateId) . '&hired=1');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong. Nothing was sent.';
        if ($offerId > 0 && in_array($action, ['save_draft', 'send'], true)) $_GET['offer_id'] = $offerId;
    }
}

// Reload after any change.
$rows = loadCandidateRecords($candidateId);
$candidate = $rows[0] ?? $candidate;
$offersStmt = $pdo->prepare('SELECT * FROM offer_letters WHERE candidate_id=? ORDER BY created_at DESC, id DESC');
$offersStmt->execute([$candidateId]);
$offers = $offersStmt->fetchAll();
$editing = null;
if (!empty($_GET['offer_id'])) foreach ($offers as $o) if ((int)$o['id'] === (int)$_GET['offer_id'] && $o['status'] === 'draft') $editing = $o;
$wantType = (string)($_GET['type'] ?? '');
if (!$editing && !isset(OFFER_LETTER_TYPES[$wantType])) $wantType = strtolower((string)$candidate['stage']) === 'hired' ? 'hiring_letter' : 'offer_letter';
if (isset($_GET['saved'])) $message = 'Draft saved. Review it, then send it to the candidate.';
if (isset($_GET['sent'])) $message = 'Letter emailed to ' . ($candidate['email'] ?? 'the candidate') . '. Their personal link opens on ' . parse_url(offerPublicBaseUrl(), PHP_URL_HOST) . '.';
if (isset($_GET['withdrawn'])) $message = 'Letter withdrawn. The candidate link no longer allows a response.';
if (isset($_GET['hired'])) $message = 'Marked as hired. The candidate now appears under Offers & Employees and leaves the active pipeline.';

$templates = documentTemplates($pdo);
$byType = ['offer_letter' => [], 'hiring_letter' => [], 'policy' => []];
foreach ($templates as $t) $byType[$t['doc_type']][] = ['id' => (int)$t['id'], 'name' => $t['name'], 'body_html' => sanitizeLetterHtml((string)$t['body_html'])];
$acceptedOffer = null;
foreach ($offers as $o) if ($o['letter_type'] === 'offer_letter' && in_array($o['status'], ['accepted', 'sent'], true)) { $acceptedOffer ??= $o; if ($o['status'] === 'accepted') { $acceptedOffer = $o; break; } }
$jobTitle = trim((string)($candidate['job_title'] ?? $candidate['role'] ?? ''));
$jobLocation = '';
if (!empty($candidate['job_id'])) {
    $j = $pdo->prepare('SELECT location FROM jobs WHERE id=?');
    $j->execute([$candidate['job_id']]);
    $jobLocation = (string)($j->fetchColumn() ?: '');
}
$defaults = $editing ?: [
    'letter_type' => $wantType, 'template_id' => null, 'designation' => $jobTitle, 'ctc_text' => '', 'joining_date' => date('Y-m-d', strtotime('+21 days')),
    'location' => $jobLocation, 'reporting_to' => '', 'respond_by' => date('Y-m-d', strtotime('+7 days')),
    'signatory_name' => (string)($user['name'] ?? ''), 'signatory_title' => 'Human Resources', 'body_html' => '', 'policy_ids_json' => null, 'email_subject' => '',
];
if (!$editing && $wantType === 'hiring_letter' && $acceptedOffer) {
    foreach (['designation', 'ctc_text', 'joining_date', 'location', 'reporting_to', 'signatory_name', 'signatory_title'] as $k) if (!empty($acceptedOffer[$k])) $defaults[$k] = $acceptedOffer[$k];
}
$selectedPolicies = $editing ? array_column(json_decode((string)$editing['policy_ids_json'], true) ?: [], 'id') : array_column($byType['policy'], 'id');
$stated = isset($candidate['expected_ctc']) && $candidate['expected_ctc'] !== null ? '₹' . rtrim(rtrim(number_format((float)$candidate['expected_ctc'], 2), '0'), '.') . ' LPA expected' : '';
$isLocal = in_array($_SERVER['HTTP_HOST'] ?? '', ['127.0.0.1', 'localhost'], true) || str_starts_with((string)($_SERVER['HTTP_HOST'] ?? ''), '127.0.0.1:') || str_starts_with((string)($_SERVER['HTTP_HOST'] ?? ''), 'localhost:');
$statusLabels = ['draft' => ['Draft', '#64748b', '#f1f5f9'], 'sent' => ['Awaiting response', '#8c610d', '#fff7e7'], 'accepted' => ['Accepted', '#0f7a52', '#ecfaf4'], 'declined' => ['Declined', '#b4233c', '#fff0f2'], 'withdrawn' => ['Withdrawn', '#64748b', '#f1f5f9']];
$fmtDate = static fn(?string $d): string => ($d && strtotime($d)) ? date('d M Y', strtotime($d)) : '—';

$activePage = 'employees';
$pageTitle = 'Offer letter · ' . $candidate['name'];
$pageStyles = '<style>' . letterPaperCss() . '
.ol-grid{display:grid;grid-template-columns:minmax(0,420px) minmax(0,1fr);gap:12px;margin-top:12px;align-items:start}
.ol-form{padding:16px}.ol-form label{display:block;font-size:11px;font-weight:600;color:var(--muted);margin:10px 0 5px}
.ol-two{display:grid;grid-template-columns:1fr 1fr;gap:8px}.ol-hint{font-size:11px;color:var(--muted);margin-top:4px}
.ol-policies{display:grid;gap:4px;margin-top:4px}.ol-policies label{display:flex;gap:6px;align-items:center;margin:0;font-weight:500;color:var(--text);font-size:12px}
.ol-editor{padding:16px}.rte-toolbar{display:flex;flex-wrap:wrap;gap:4px;padding:6px;border:1px solid var(--line);border-bottom:0;border-radius:8px 8px 0 0;background:var(--soft)}
.rte-toolbar button{border:1px solid var(--line);background:#fff;border-radius:6px;padding:4px 8px;font-size:12px;cursor:pointer}
.rte-wrap .letter-paper{border-radius:0 0 8px 8px;max-width:none;box-shadow:none}.rte-wrap .letter-inner{padding:28px 32px 22px}.rte-wrap .letter-foot{margin:0 32px}.rte-wrap .letter-body{outline:none;min-height:380px}
.ol-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;align-items:center}
.btn-ghost{background:#fff;border:1px solid var(--line);color:var(--text)}
.ol-flash{padding:10px 12px;border-radius:8px;font-size:12px;margin-top:10px}.ol-flash.ok{background:var(--green-soft);color:#0f7a52}.ol-flash.err{background:#fff0f2;color:#b4233c}.ol-flash.warn{background:var(--amber-soft);color:#8c610d}
.ol-history{padding:16px;margin-top:12px}.ol-history table{width:100%;border-collapse:collapse;font-size:12px}.ol-history th,.ol-history td{padding:8px 6px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
.ol-history th{font-size:10px;text-transform:uppercase;color:var(--muted);letter-spacing:.04em}
.status-pill{display:inline-block;padding:3px 7px;border-radius:6px;font-size:10px;font-weight:700}
.ol-row-actions{display:flex;flex-wrap:wrap;gap:6px}.ol-row-actions form{display:inline}.ol-row-actions a,.ol-row-actions button{font-size:11px;padding:4px 8px;border-radius:6px;border:1px solid var(--line);background:#fff;color:var(--text);text-decoration:none;cursor:pointer}
.ol-hire{padding:14px 16px;margin-top:12px;border:1px solid #bfe9d6;background:#f3fbf7;border-radius:10px}
@media(max-width:1100px){.ol-grid{grid-template-columns:1fr}}
</style>';
require __DIR__ . '/views/partials/header.php';
?>
<section class="panel" style="padding:16px 18px">
    <div class="kicker"><i class="fa-solid fa-file-signature"></i> Offer &amp; hiring letters</div>
    <h1 style="margin:4px 0 2px;font-size:20px"><?= htmlspecialchars($candidate['name']) ?></h1>
    <p class="muted" style="margin:0;font-size:12px"><?= htmlspecialchars($jobTitle ?: 'No job assigned') ?> · Stage <strong><?= htmlspecialchars((string)$candidate['stage']) ?></strong> · <?= htmlspecialchars((string)($candidate['email'] ?: 'no email')) ?><?= $stated ? ' · ' . htmlspecialchars($stated) : '' ?>
        · <a href="candidate_detail.php?id=<?= urlencode($candidateId) ?>">Profile</a> · <a href="documents.php">Edit templates</a></p>
    <?php if ($message): ?><div class="ol-flash ok"><?= htmlspecialchars($message) ?></div><?php endif ?>
    <?php if ($error): ?><div class="ol-flash err"><?= htmlspecialchars($error) ?></div><?php endif ?>
    <?php if ($isLocal): ?><div class="ol-flash warn"><i class="fa-solid fa-triangle-exclamation"></i> You are on a local copy. Candidate links point to <strong><?= htmlspecialchars(offerPublicBaseUrl()) ?></strong>, which shares this database. Deploy this release there before sending real letters.</div><?php endif ?>
    <?php if (!$byType['offer_letter'] && !$byType['hiring_letter']): ?><div class="ol-flash warn">No letter templates yet. <a href="documents.php">Install the starter templates</a> first.</div><?php endif ?>
</section>

<?php if ($acceptedOffer && $acceptedOffer['status'] === 'accepted' && strtolower((string)$candidate['stage']) !== 'hired'): ?>
<section class="ol-hire">
    <form method="post" onsubmit="return confirm('Mark <?= htmlspecialchars(addslashes($candidate['name']), ENT_QUOTES) ?> as hired? They will leave the active pipeline and appear under Employees.')">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="action" value="mark_hired"><input type="hidden" name="candidate_id" value="<?= htmlspecialchars($candidateId) ?>"><input type="hidden" name="offer_id" value="<?= (int)$acceptedOffer['id'] ?>">
        <strong style="font-size:13px;color:#0f7a52"><i class="fa-solid fa-circle-check"></i> Offer accepted<?= $acceptedOffer['response_name'] ? ' by ' . htmlspecialchars($acceptedOffer['response_name']) : '' ?> on <?= $fmtDate($acceptedOffer['responded_at']) ?>.</strong>
        <button class="btn btn-primary" style="margin-left:10px" type="submit"><i class="fa-solid fa-user-check"></i> Mark as hired</button>
    </form>
</section>
<?php endif ?>

<form method="post" id="letterForm" onsubmit="return beforeSubmit(event)">
<input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken()) ?>">
<input type="hidden" name="candidate_id" value="<?= htmlspecialchars($candidateId) ?>">
<input type="hidden" name="offer_id" value="<?= (int)($editing['id'] ?? 0) ?>">
<input type="hidden" name="body_html" id="bodyHtml">
<section class="ol-grid">
    <article class="panel ol-form">
        <h2 style="font-size:14px;margin:0"><?= $editing ? 'Edit draft #' . (int)$editing['id'] : 'New letter' ?></h2>
        <label for="letterType">Letter type</label>
        <select class="control" id="letterType" name="letter_type" onchange="refillTemplates()"><?php foreach (OFFER_LETTER_TYPES as $k => $label): ?><option value="<?= $k ?>" <?= $defaults['letter_type'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach ?></select>
        <label for="templateId">Template</label>
        <select class="control" id="templateId" name="template_id" onchange="applyTemplate(true)"></select>
        <div class="ol-two">
            <div><label for="designation">Designation *</label><input class="control" id="designation" name="designation" required maxlength="160" value="<?= htmlspecialchars((string)$defaults['designation']) ?>"></div>
            <div><label for="ctcText">Annual CTC</label><input class="control" id="ctcText" name="ctc_text" maxlength="120" placeholder="₹18,00,000 per annum" value="<?= htmlspecialchars((string)$defaults['ctc_text']) ?>"></div>
            <div><label for="joiningDate">Date of joining</label><input class="control" type="date" id="joiningDate" name="joining_date" value="<?= htmlspecialchars((string)$defaults['joining_date']) ?>"></div>
            <div><label for="respondBy">Respond by</label><input class="control" type="date" id="respondBy" name="respond_by" value="<?= htmlspecialchars((string)$defaults['respond_by']) ?>"></div>
            <div><label for="location">Work location</label><input class="control" id="location" name="location" maxlength="160" value="<?= htmlspecialchars((string)$defaults['location']) ?>"></div>
            <div><label for="reportingTo">Reporting to</label><input class="control" id="reportingTo" name="reporting_to" maxlength="160" value="<?= htmlspecialchars((string)$defaults['reporting_to']) ?>"></div>
            <div><label for="signatoryName">Signatory name</label><input class="control" id="signatoryName" name="signatory_name" maxlength="160" value="<?= htmlspecialchars((string)$defaults['signatory_name']) ?>"></div>
            <div><label for="signatoryTitle">Signatory title</label><input class="control" id="signatoryTitle" name="signatory_title" maxlength="160" value="<?= htmlspecialchars((string)$defaults['signatory_title']) ?>"></div>
        </div>
        <p class="ol-hint" id="ctcConv"></p>
        <?php if ($stated): ?><p class="ol-hint">Candidate stated: <?= htmlspecialchars($stated) ?>. The CTC above is what the letter will say.</p><?php endif ?>
        <label>Policies shared with this letter</label>
        <div class="ol-policies">
            <?php foreach ($byType['policy'] as $p): ?><label><input type="checkbox" name="policy_ids[]" value="<?= (int)$p['id'] ?>" <?= in_array($p['id'], $selectedPolicies, true) ? 'checked' : '' ?>> <?= htmlspecialchars($p['name']) ?></label><?php endforeach ?>
            <?php if (!$byType['policy']): ?><span class="ol-hint">No active policies. <a href="documents.php?new=policy">Add one</a>.</span><?php endif ?>
        </div>
        <label for="emailSubject">Email subject</label>
        <input class="control" id="emailSubject" name="email_subject" maxlength="255" placeholder="Default: Your offer from <?= htmlspecialchars($brand['name']) ?> — designation" value="<?= htmlspecialchars((string)$defaults['email_subject']) ?>">
        <div class="ol-actions">
            <button class="btn btn-ghost" type="submit" name="action" value="save_draft"><i class="fa-solid fa-floppy-disk"></i> Save draft</button>
            <button class="btn btn-primary" type="submit" name="action" value="send"><i class="fa-solid fa-paper-plane"></i> Save &amp; send to candidate</button>
        </div>
        <p class="ol-hint">The email contains a personal link (no attachments). On that page the candidate can download PDF/Word, read the policies and <?= 'accept or decline' ?>. Nothing is sent until you click send and confirm.</p>
    </article>

    <article class="panel ol-editor">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px"><strong style="font-size:13px">Letter (editable)</strong><button type="button" class="btn btn-ghost" style="font-size:11px;padding:4px 8px" onclick="applyTemplate(true)"><i class="fa-solid fa-rotate"></i> Refill from template</button></div>
        <div class="rte-toolbar">
            <button type="button" onclick="fmt('formatBlock','h2')">H2</button><button type="button" onclick="fmt('formatBlock','h3')">H3</button><button type="button" onclick="fmt('formatBlock','p')">¶</button>
            <button type="button" onclick="fmt('bold')"><b>B</b></button><button type="button" onclick="fmt('italic')"><i>I</i></button><button type="button" onclick="fmt('underline')"><u>U</u></button>
            <button type="button" onclick="fmt('insertUnorderedList')"><i class="fa-solid fa-list-ul"></i></button><button type="button" onclick="fmt('insertOrderedList')"><i class="fa-solid fa-list-ol"></i></button>
            <button type="button" onclick="fmt('removeFormat')"><i class="fa-solid fa-text-slash"></i></button>
        </div>
        <div class="rte-wrap"><?= letterPaperHtml('', $brand, ['label' => OFFER_LETTER_TYPES[$defaults['letter_type']] ?? 'Letter', 'ref' => $editing ? letterReference($editing, $brand) : 'Draft']) ?></div>
        <p class="ol-hint">Fields on the left fill the template. After you type in the letter, field changes no longer overwrite it; use <em>Refill from template</em> to start over.</p>
    </article>
</section>
</form>

<section class="panel ol-history">
    <h2 style="font-size:14px;margin:0 0 8px">Letters for this candidate</h2>
    <?php if (!$offers): ?><p class="muted" style="font-size:12px;margin:0">No letters yet.</p><?php else: ?>
    <table>
        <thead><tr><th>#</th><th>Type</th><th>Designation / joining</th><th>Status</th><th>Sent</th><th>Response</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($offers as $o): [$sl, $sc, $sb] = $statusLabels[$o['status']] ?? [$o['status'], '#64748b', '#f1f5f9']; ?>
            <tr>
                <td><?= (int)$o['id'] ?></td>
                <td><?= htmlspecialchars(OFFER_LETTER_TYPES[$o['letter_type']] ?? $o['letter_type']) ?></td>
                <td><?= htmlspecialchars($o['designation']) ?><br><small class="muted">Joining <?= $fmtDate($o['joining_date']) ?><?= $o['ctc_text'] ? ' · ' . htmlspecialchars($o['ctc_text']) : '' ?></small></td>
                <td><span class="status-pill" style="color:<?= $sc ?>;background:<?= $sb ?>"><?= htmlspecialchars($sl) ?></span><?php if ($o['viewed_at'] && $o['status'] === 'sent'): ?><br><small class="muted">Opened <?= $fmtDate($o['viewed_at']) ?></small><?php endif ?></td>
                <td><?= $fmtDate($o['sent_at']) ?></td>
                <td><?php if ($o['responded_at']): ?><?= $fmtDate($o['responded_at']) ?><br><small class="muted"><?= htmlspecialchars((string)$o['response_name']) ?><?= $o['response_note'] ? ' — ' . htmlspecialchars(mb_strimwidth((string)$o['response_note'], 0, 80, '…')) : '' ?></small><?php else: ?>—<?php endif ?></td>
                <td>
                    <div class="ol-row-actions">
                        <a href="offer_file.php?id=<?= (int)$o['id'] ?>&amp;format=print" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i> PDF</a>
                        <a href="offer_file.php?id=<?= (int)$o['id'] ?>&amp;format=docx"><i class="fa-solid fa-file-word"></i> Word</a>
                        <?php if ($o['status'] === 'draft'): ?><a href="offer_letter.php?candidate_id=<?= urlencode($candidateId) ?>&amp;offer_id=<?= (int)$o['id'] ?>"><i class="fa-solid fa-pen"></i> Edit</a><?php endif ?>
                        <?php if ($o['status'] === 'sent'): ?>
                            <form method="post" onsubmit="return confirm('Email a fresh link to the candidate? The previous link stops working.')"><input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="candidate_id" value="<?= htmlspecialchars($candidateId) ?>"><input type="hidden" name="offer_id" value="<?= (int)$o['id'] ?>"><button name="action" value="resend"><i class="fa-solid fa-paper-plane"></i> Resend link</button></form>
                            <?php if ($o['letter_type'] === 'offer_letter'): ?>
                            <form method="post" onsubmit="return confirm('Record that the candidate accepted outside the portal and mark them as hired?')"><input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="candidate_id" value="<?= htmlspecialchars($candidateId) ?>"><input type="hidden" name="offer_id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="accepted_offline" value="1"><button name="action" value="mark_hired"><i class="fa-solid fa-user-check"></i> Accepted offline → hire</button></form>
                            <?php endif ?>
                        <?php endif ?>
                        <?php if (in_array($o['status'], ['sent', 'draft'], true)): ?>
                            <form method="post" onsubmit="return confirm('Withdraw this letter? The candidate will no longer be able to respond.')"><input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="candidate_id" value="<?= htmlspecialchars($candidateId) ?>"><input type="hidden" name="offer_id" value="<?= (int)$o['id'] ?>"><button name="action" value="withdraw" style="color:#b4233c"><i class="fa-solid fa-ban"></i> Withdraw</button></form>
                        <?php endif ?>
                    </div>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    <?php endif ?>
</section>

<script>
const TEMPLATES = <?= json_encode($byType, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const CANDIDATE = <?= json_encode(['name' => $candidate['name'], 'company' => $brand['name']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const INITIAL_BODY = <?= json_encode($editing ? sanitizeLetterHtml((string)$editing['body_html']) : '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const INITIAL_TEMPLATE = <?= json_encode((int)($defaults['template_id'] ?? 0)) ?>;
const FX = <?= json_encode(fxRates(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const CANDIDATE_EMAIL = <?= json_encode((string)($candidate['email'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const body = document.querySelector('.rte-wrap .letter-body');
body.contentEditable = 'true';
let edited = !!INITIAL_BODY;
const $ = id => document.getElementById(id);
const escText = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const longDate = v => { if (!v) return ''; const d = new Date(v + 'T00:00:00'); return isNaN(d) ? v : d.toLocaleDateString('en-GB', {day: '2-digit', month: 'long', year: 'numeric'}); };
const OPTIONAL = ['ctc_converted'];
// Mirrors normalizeCtcLpa()/ctcConversionText() in PHP: only clear INR amounts are converted.
function ctcRupees(text) {
  const v = String(text || '').toLowerCase();
  if (/[$€£]|\b(usd|aed|eur|gbp|dollars?|dirhams?)\b/.test(v)) return null;
  const m = v.match(/(\d[\d,]*(?:\.\d+)?)/); if (!m) return null;
  let n = parseFloat(m[1].replace(/,/g, '')); if (!n) return null;
  const has = alt => new RegExp('(?<![a-z])(?:' + alt + ')(?![a-z])').test(v);
  const thousands = has('k|thousand'); if (thousands) n *= 1000;
  let lpa = null;
  if (has('crores?|cr')) lpa = n * 100;
  else if (has('lpa|lakhs?|lacs?|l')) lpa = n;
  else if (has('per month|monthly|pm|p\.m|month')) lpa = n >= 1000 ? n * 12 / 1e5 : null;
  else if (thousands) lpa = n / 1e5;
  else if (n >= 100000) lpa = n / 1e5;
  else if (n <= 200) lpa = n;
  return (lpa === null || lpa < 0.5 || lpa > 300) ? null : lpa * 1e5;
}
function ctcConversion(text) {
  const inr = ctcRupees(text);
  if (inr === null || !FX.inr_per_usd || !FX.inr_per_aed) return '';
  const r = x => (Math.round(x / 100) * 100).toLocaleString('en-US');
  const rate = x => String(Math.round(x * 100) / 100);
  const d = FX.as_of ? ' as of ' + new Date(FX.as_of + 'T00:00:00').toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'}) : '';
  return `≈ USD ${r(inr / FX.inr_per_usd)} · AED ${r(inr / FX.inr_per_aed)} per annum (indicative: 1 USD = ₹${rate(FX.inr_per_usd)}, 1 AED = ₹${rate(FX.inr_per_aed)}${d})`;
}
function showConversion() {
  const out = $('ctcConv'), conv = ctcConversion($('ctcText').value);
  if (!FX.inr_per_usd) out.innerHTML = 'USD / AED conversion is off. <a href="documents.php" target="_blank" rel="noopener">Set exchange rates</a>.';
  else out.textContent = conv || ($('ctcText').value.trim() ? 'Could not read this amount for conversion. Use e.g. "₹12,00,000 per annum" or "12 LPA".' : '');
}
function vars() {
  return {
    candidate_name: CANDIDATE.name, company_name: CANDIDATE.company, designation: $('designation').value, ctc: $('ctcText').value, ctc_converted: ctcConversion($('ctcText').value),
    joining_date: longDate($('joiningDate').value), location: $('location').value, reporting_to: $('reportingTo').value,
    respond_by: longDate($('respondBy').value), offer_date: longDate(new Date().toISOString().slice(0, 10)),
    signatory_name: $('signatoryName').value, signatory_title: $('signatoryTitle').value,
  };
}
function refillTemplates() {
  const list = TEMPLATES[$('letterType').value] || [];
  $('templateId').innerHTML = list.map(t => `<option value="${t.id}">${escText(t.name)}</option>`).join('') || '<option value="">No templates — add one in Letters &amp; Policies</option>';
  if (INITIAL_TEMPLATE && list.some(t => t.id === INITIAL_TEMPLATE)) $('templateId').value = String(INITIAL_TEMPLATE);
  if (!edited) applyTemplate(false);
}
function applyTemplate(force) {
  if (force && edited && !confirm('Replace the letter text with the template? Your edits in the letter will be lost.')) return;
  const list = TEMPLATES[$('letterType').value] || [];
  const tpl = list.find(t => String(t.id) === $('templateId').value) || list[0];
  if (!tpl) { body.innerHTML = '<p class="muted">No template available.</p>'; return; }
  const v = vars();
  body.innerHTML = tpl.body_html.replace(/\{\{\s*([a-z_]+)\s*\}\}/g, (m, k) => k in v ? (escText(v[k]).trim() || (OPTIONAL.includes(k) ? '' : '—')) : m);
  edited = false;
}
function fmt(cmd, val) { body.focus(); document.execCommand(cmd, false, val ?? null); edited = true; }
body.addEventListener('input', () => { edited = true; });
['designation', 'ctcText', 'joiningDate', 'respondBy', 'location', 'reportingTo', 'signatoryName', 'signatoryTitle'].forEach(id => $(id).addEventListener('input', () => { if (!edited) applyTemplate(false); }));
function beforeSubmit(event) {
  $('bodyHtml').value = body.innerHTML;
  const action = event.submitter?.value;
  if (action === 'send') {
    if (!CANDIDATE_EMAIL) { alert('This candidate has no email address.'); return false; }
    return confirm(`Send this ${$('letterType').selectedOptions[0].textContent.toLowerCase()} to ${CANDIDATE_EMAIL}? The candidate receives a personal link to view, download and respond.`);
  }
  return true;
}
$('ctcText').addEventListener('input', showConversion); showConversion();
if (INITIAL_BODY) { refillTemplates(); body.innerHTML = INITIAL_BODY; edited = true; } else { refillTemplates(); }
</script>
<?php require __DIR__ . '/views/partials/footer.php'; ?>
