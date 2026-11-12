<?php
// Public candidate offer portal: offer.php?token=...
// No staff login. Access is by the personal token emailed to the candidate (only its hash is stored).
// The candidate can view the letter, download PDF/Word, read policies and accept/decline once.
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/offer_letters.php';
require_once __DIR__ . '/lib/onboarding.php';
startAppSession();

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$pdo = db();
$brand = organizationBrand();
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$offer = $token !== '' ? findOfferByToken($pdo, $token) : null;
if ($offer) $brand = entityBrand($brand, letterEntity($offer['entity_key'] ?? null));
$notice = '';
$error = '';

$audit = static function (string $action, array $offer, array $meta = []) use ($pdo): void {
    try {
        $pdo->prepare('INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(NULL,?,\'candidate\',?,?)')
            ->execute([$action, $offer['candidate_id'], json_encode(['offer_id' => (int)$offer['id']] + $meta)]);
    } catch (Throwable $e) {}
};

// Onboarding (after the offer is accepted): document uploads and bank details.
$onboardingOpen = $offer && $offer['letter_type'] === 'offer_letter' && $offer['status'] === 'accepted' && onboardingTablesReady($pdo);
if ($onboardingOpen && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['onboarding_action'])) {
    $itemKey = (string)($_POST['item_key'] ?? '');
    $checklist = onboardingChecklist();
    try {
        if (!verifyCsrf((string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Your session expired. Please reload the page and try again.');
        if (!isset($checklist[$itemKey])) throw new RuntimeException('Unknown checklist item.');
        $existing = $pdo->prepare('SELECT status FROM onboarding_items WHERE offer_id=? AND item_key=?');
        $existing->execute([$offer['id'], $itemKey]);
        if ($existing->fetchColumn() === 'verified') throw new RuntimeException('This item is already verified by HR.');
        $fields = ['file_path' => null, 'original_name' => null, 'mime' => null, 'size_bytes' => null, 'data_json' => null];
        if ($checklist[$itemKey]['kind'] === 'file') {
            $stored = onboardingStoreUpload($_FILES['document'] ?? [], (int)$offer['id']);
            $fields = ['file_path' => $stored['path'], 'original_name' => $stored['original'], 'mime' => $stored['mime'], 'size_bytes' => $stored['size'], 'data_json' => null];
        } else {
            $fields['data_json'] = json_encode(onboardingBankData($_POST));
        }
        $pdo->prepare("INSERT INTO onboarding_items(offer_id,candidate_id,item_key,status,file_path,original_name,mime,size_bytes,data_json,submitted_at) VALUES(?,?,?,'submitted',?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE status='submitted',file_path=VALUES(file_path),original_name=VALUES(original_name),mime=VALUES(mime),size_bytes=VALUES(size_bytes),data_json=VALUES(data_json),submitted_at=NOW(),reviewed_at=NULL,reviewed_by=NULL,review_note=NULL")
            ->execute([$offer['id'], $offer['candidate_id'], $itemKey, $fields['file_path'], $fields['original_name'], $fields['mime'], $fields['size_bytes'], $fields['data_json']]);
        $audit('onboarding.item_submitted', $offer, ['item' => $itemKey, 'kind' => $checklist[$itemKey]['kind'], 'size' => $fields['size_bytes']]);
        header('Location: offer.php?token=' . rawurlencode($token) . '&uploaded=' . rawurlencode($itemKey) . '#onboarding');
        exit;
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not save this item. Please try again.';
    }
}
if ($offer && $offer['status'] !== 'draft' && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['onboarding_action'])) {
    $choice = (string)($_POST['response'] ?? '');
    $name = trim(preg_replace('/\s+/u', ' ', (string)($_POST['full_name'] ?? '')));
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 1000);
    $isOffer = $offer['letter_type'] === 'offer_letter';
    $allowed = $isOffer ? ['accept', 'decline'] : ['acknowledge'];
    if (!verifyCsrf((string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please reload the page and try again.';
    } elseif ($offer['status'] !== 'sent') {
        $error = 'A response has already been recorded for this letter.';
    } elseif (!in_array($choice, $allowed, true)) {
        $error = 'Please choose a response.';
    } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
        $error = 'Please type your full name to confirm.';
    } elseif ($choice !== 'decline' && empty($_POST['read_policies'])) {
        $error = 'Please confirm that you have read the letter and the company policies.';
    } else {
        $status = $choice === 'decline' ? 'declined' : 'accepted';
        $ip = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        $u = $pdo->prepare("UPDATE offer_letters SET status=?,responded_at=NOW(),response_name=?,response_note=?,response_ip=? WHERE id=? AND status='sent'");
        $u->execute([$status, $name, $note !== '' ? $note : null, $ip, $offer['id']]);
        if ($u->rowCount() === 1) {
            $audit('offer_letter.' . ($choice === 'acknowledge' ? 'acknowledged' : $status), $offer, ['response_name' => $name, 'has_note' => $note !== '']);
            header('Location: offer.php?token=' . rawurlencode($token) . '&done=' . $choice);
            exit;
        }
        $error = 'A response has already been recorded for this letter.';
    }
    $offer = findOfferByToken($pdo, $token);
}

if ($offer && $offer['status'] !== 'draft' && empty($offer['viewed_at'])) {
    $v = $pdo->prepare('UPDATE offer_letters SET viewed_at=NOW() WHERE id=? AND viewed_at IS NULL');
    $v->execute([$offer['id']]);
    if ($v->rowCount() === 1) $audit('offer_letter.viewed', $offer);
}

$valid = $offer && $offer['status'] !== 'draft';
$orgName = htmlspecialchars((string)$brand['name'], ENT_QUOTES, 'UTF-8');
$done = (string)($_GET['done'] ?? '');
if ($done === 'accept') $notice = 'Thank you! Your acceptance has been recorded. Our HR team will contact you with the next steps.';
if ($done === 'decline') $notice = 'Your response has been recorded. Thank you for letting us know, and we wish you the very best.';
if ($done === 'acknowledge') $notice = 'Thank you for acknowledging your letter.';
if (!empty($_GET['uploaded'])) $notice = 'Saved. HR will review it shortly.';
if ($done === 'accept' && !empty($onboardingOpen)) $notice .= ' Please complete the onboarding checklist below.';
$policies = $valid ? (json_decode((string)($offer['policy_ids_json'] ?? ''), true) ?: []) : [];
$fmt = static fn(?string $d): string => ($d && strtotime($d)) ? date('d F Y', strtotime($d)) : '';
$qs = $valid ? 'token=' . rawurlencode($token) : '';
$logo = brandLogoDataUri($brand);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?= $valid ? htmlspecialchars((OFFER_LETTER_TYPES[$offer['letter_type']] ?? 'Letter') . ' · ' . $brand['name']) : 'Link not available · ' . $orgName ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
<style>
:root{--line:#ececf3;--text:#171820;--muted:#7b8090;--primary:#6f45ff;--primary-soft:#f0edff;--green:#18a66f;--green-soft:#ecfaf4;--amber-soft:#fff7e7;--danger:#e4697e}
*{box-sizing:border-box}body{margin:0;background:#f6f7fb;color:var(--text);font-family:Inter,Arial,sans-serif;font-size:14px}
<?= letterPaperCss() ?>
.portal{max-width:860px;margin:0 auto;padding:24px 16px 48px}
.portal-top{display:flex;align-items:center;gap:12px;margin-bottom:16px}.portal-top img{max-height:38px;max-width:150px;object-fit:contain}.portal-top strong{font-size:15px}
.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:18px 20px;margin-bottom:14px}
.card h1{font-size:20px;margin:0 0 6px}.card h2{font-size:15px;margin:0 0 10px}.muted{color:var(--muted)}
.facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-top:12px}.fact{border:1px solid var(--line);border-radius:8px;padding:9px 11px}.fact small{display:block;color:var(--muted);font-size:11px}.fact strong{font-size:13px}
.downloads{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}.downloads a{display:inline-flex;gap:6px;align-items:center;padding:9px 13px;border-radius:8px;border:1px solid var(--line);color:var(--text);text-decoration:none;font-weight:600;font-size:13px;background:#fff}
.downloads a:hover{border-color:var(--primary);color:var(--primary)}
.status{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:10px;font-size:13px;margin-bottom:14px}.status.ok{background:var(--green-soft);color:#0f7a52}.status.warn{background:var(--amber-soft);color:#8c610d}.status.err{background:#fff0f2;color:#b4233c}.status.info{background:var(--primary-soft);color:#3f2aa8}
details.policy{border:1px solid var(--line);border-radius:8px;margin-bottom:8px;background:#fff}details.policy summary{cursor:pointer;padding:11px 14px;font-weight:600;font-size:13px}details.policy .letter-body{padding:0 16px 12px;font-size:13px}
.respond label{display:block;font-size:12px;font-weight:600;color:var(--muted);margin:12px 0 5px}.respond input[type=text],.respond textarea{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;font:inherit}
.respond .check{display:flex;gap:8px;align-items:flex-start;color:var(--text);font-weight:500}
.respond .btns{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}.respond button{border:0;border-radius:8px;padding:11px 18px;font-weight:600;font-size:14px;cursor:pointer}
.btn-accept{background:var(--primary);color:#fff}.btn-decline{background:#fff;color:#b4233c;border:1px solid #f3c2cb!important}
.onboarding .ob-item{border:1px solid var(--line);border-radius:10px;padding:11px 13px;margin-bottom:8px}.onboarding .ob-head{display:flex;justify-content:space-between;gap:10px;align-items:center}.onboarding .ob-head strong{font-size:13px}
.ob-pill{font-size:11px;font-weight:700;padding:3px 8px;border-radius:6px;background:#f1f5f9;color:#64748b;white-space:nowrap}.ob-submitted .ob-pill{background:var(--amber-soft);color:#8c610d}.ob-verified .ob-pill{background:var(--green-soft);color:#0f7a52}.ob-rejected .ob-pill{background:#fff0f2;color:#b4233c}
.ob-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px}.ob-form button{border:0;border-radius:8px;padding:8px 14px;font-weight:600;background:var(--primary);color:#fff;cursor:pointer}.ob-form input[type=file]{font-size:12px}
.ob-bank{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;flex:1}.ob-bank input{padding:9px 11px;border:1px solid var(--line);border-radius:8px;font:inherit;font-size:13px}
.foot{text-align:center;color:var(--muted);font-size:12px;margin-top:20px}
</style></head>
<body><main class="portal">
<div class="portal-top"><?php if ($logo): ?><img src="<?= $logo ?>" alt="<?= $orgName ?> logo"><?php endif ?><strong><?= $orgName ?></strong></div>

<?php if (!$valid): ?>
    <section class="card"><h1>This link is not available</h1><p class="muted">The link may be incorrect, or a newer link was sent to you. Please use the most recent email from <?= $orgName ?>, or reply to that email for help.</p></section>
<?php else:
    $isOffer = $offer['letter_type'] === 'offer_letter';
    $label = OFFER_LETTER_TYPES[$offer['letter_type']] ?? 'Letter';
?>
    <?php if ($notice): ?><div class="status ok"><i class="fa-solid fa-circle-check"></i><span><?= htmlspecialchars($notice) ?></span></div><?php endif ?>
    <?php if ($error): ?><div class="status err"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif ?>
    <?php if ($offer['status'] === 'withdrawn'): ?><div class="status warn"><i class="fa-solid fa-ban"></i><span>This letter has been withdrawn by <?= $orgName ?> and can no longer be accepted.</span></div><?php endif ?>
    <?php if ($offer['status'] === 'accepted' && !$notice): ?><div class="status ok"><i class="fa-solid fa-circle-check"></i><span><?= $isOffer ? 'You accepted this offer' : 'You acknowledged this letter' ?> on <?= $fmt($offer['responded_at']) ?>.</span></div><?php endif ?>
    <?php if ($offer['status'] === 'declined' && !$notice): ?><div class="status info"><i class="fa-solid fa-circle-info"></i><span>You declined this offer on <?= $fmt($offer['responded_at']) ?>.</span></div><?php endif ?>

    <?php if ($offer['status'] !== 'withdrawn'): ?>
    <section class="card">
        <h1><?= htmlspecialchars($label) ?> — <?= htmlspecialchars($offer['designation']) ?></h1>
        <p class="muted" style="margin:0">Hello <?= htmlspecialchars($offer['candidate_name']) ?>, here is your <?= htmlspecialchars(strtolower($label)) ?> from <?= $orgName ?>.</p>
        <div class="facts">
            <div class="fact"><small>Designation</small><strong><?= htmlspecialchars($offer['designation']) ?></strong></div>
            <?php if ($offer['joining_date']): ?><div class="fact"><small>Date of joining</small><strong><?= $fmt($offer['joining_date']) ?></strong></div><?php endif ?>
            <?php if ($offer['ctc_text']): $conv = ctcConversionText($offer['ctc_text']); ?><div class="fact"><small>Annual CTC</small><strong><?= htmlspecialchars($offer['ctc_text']) ?></strong><?php if ($conv !== ''): ?><small style="margin-top:3px"><?= htmlspecialchars($conv) ?></small><?php endif ?></div><?php endif ?>
            <?php if ($isOffer && $offer['respond_by'] && $offer['status'] === 'sent'): ?><div class="fact"><small>Please respond by</small><strong><?= $fmt($offer['respond_by']) ?></strong></div><?php endif ?>
        </div>
        <div class="downloads">
            <a href="offer_file.php?<?= $qs ?>&amp;format=print&amp;policies=1" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf" style="color:#d93025"></i> Download PDF</a>
            <a href="offer_file.php?<?= $qs ?>&amp;format=docx"><i class="fa-solid fa-file-word" style="color:#2b579a"></i> Download Word</a>
            <?php if ($policies): ?><a href="offer_file.php?<?= $qs ?>&amp;format=policies"><i class="fa-solid fa-book" style="color:var(--primary)"></i> Policies (Word)</a><?php endif ?>
        </div>
    </section>

    <?= letterPaperHtml(sanitizeLetterHtml((string)$offer['body_html'] . salaryBreakupHtml($offer['salary_breakup_json'] ?? null)), $brand, ['label' => $label, 'ref' => letterReference($offer, $brand)]) ?>

    <?php if ($policies): ?>
    <section class="card" style="margin-top:14px">
        <h2><i class="fa-solid fa-book" style="color:var(--primary)"></i> Company policies</h2>
        <?php foreach ($policies as $p): ?>
            <details class="policy"><summary><?= htmlspecialchars((string)($p['name'] ?? 'Policy')) ?></summary><div class="letter-body"><?= sanitizeLetterHtml(renderLetterTemplate((string)($p['body_html'] ?? ''), ['company_name' => (string)$brand['name']])) ?></div></details>
        <?php endforeach ?>
    </section>
    <?php endif ?>

    <?php if (!empty($onboardingOpen)): $obItems = onboardingItems($pdo, (int)$offer['id']); $obProg = onboardingProgress($obItems); ?>
    <section class="card onboarding" id="onboarding" style="margin-top:14px">
        <h2><i class="fa-solid fa-clipboard-check" style="color:var(--primary)"></i> Onboarding checklist</h2>
        <p class="muted" style="margin:0 0 10px;font-size:13px">Welcome aboard! Please upload the documents below (PDF, JPG or PNG, up to 5 MB each). HR will verify them. <?= $obProg['verified'] ?> of <?= $obProg['total'] ?> verified.</p>
        <?php foreach ($obItems as $key => $item): $st = $item['status']; ?>
        <div class="ob-item ob-<?= htmlspecialchars($st) ?>">
            <div class="ob-head">
                <strong><?= htmlspecialchars($item['label']) ?><?= $item['required'] ? '' : ' <span class="muted" style="font-weight:400">(optional)</span>' ?></strong>
                <span class="ob-pill"><?= htmlspecialchars(ONBOARDING_STATUS[$st] ?? $st) ?></span>
            </div>
            <?php if ($item['row'] && $item['kind'] === 'file' && $item['row']['original_name']): ?><small class="muted">Uploaded: <?= htmlspecialchars($item['row']['original_name']) ?></small><?php endif ?>
            <?php if ($item['row'] && $item['kind'] === 'bank'): ?><small class="muted"><?= htmlspecialchars(onboardingMaskedBank($item['row']['data_json'])) ?></small><?php endif ?>
            <?php if ($st === 'rejected' && !empty($item['row']['review_note'])): ?><small style="display:block;color:#b4233c">HR note: <?= htmlspecialchars($item['row']['review_note']) ?></small><?php endif ?>
            <?php if ($st !== 'verified'): ?>
            <form method="post" enctype="multipart/form-data" class="ob-form">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="onboarding_action" value="1"><input type="hidden" name="item_key" value="<?= htmlspecialchars($key) ?>">
                <?php if ($item['kind'] === 'file'): ?>
                    <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
                    <button type="submit"><?= $item['row'] ? 'Replace' : 'Upload' ?></button>
                <?php else: ?>
                    <div class="ob-bank">
                        <input type="text" name="account_holder" placeholder="Account holder name" maxlength="120" required>
                        <input type="text" name="bank_name" placeholder="Bank name" maxlength="120" required>
                        <input type="text" name="account_number" placeholder="Account number / IBAN" maxlength="40" required autocomplete="off">
                        <input type="text" name="bank_code" placeholder="IFSC / SWIFT code" maxlength="15" required>
                    </div>
                    <button type="submit"><?= $item['row'] ? 'Update details' : 'Save details' ?></button>
                <?php endif ?>
            </form>
            <?php endif ?>
        </div>
        <?php endforeach ?>
        <p class="muted" style="font-size:11px;margin:10px 0 0">Your documents are stored privately and are visible only to the HR team. Bank account numbers are encrypted.</p>
    </section>
    <?php endif ?>
    <?php if ($offer['status'] === 'sent'): ?>
    <section class="card respond" style="margin-top:14px">
        <h2><?= $isOffer ? 'Your response' : 'Acknowledge receipt' ?></h2>
        <form method="post" onsubmit="return confirmResponse(event)">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <label for="fullName">Type your full name to sign</label>
            <input type="text" id="fullName" name="full_name" maxlength="160" required autocomplete="name" placeholder="<?= htmlspecialchars($offer['candidate_name']) ?>">
            <label for="note">Message to the hiring team (optional)</label>
            <textarea id="note" name="note" rows="3" maxlength="1000"></textarea>
            <label class="check"><input type="checkbox" name="read_policies" value="1"> I have read the <?= htmlspecialchars(strtolower($label)) ?><?= $policies ? ' and the company policies' : '' ?>.</label>
            <div class="btns">
                <?php if ($isOffer): ?>
                    <button type="submit" name="response" value="accept" class="btn-accept"><i class="fa-solid fa-check"></i> Accept offer</button>
                    <button type="submit" name="response" value="decline" class="btn-decline"><i class="fa-solid fa-xmark"></i> Decline</button>
                <?php else: ?>
                    <button type="submit" name="response" value="acknowledge" class="btn-accept"><i class="fa-solid fa-check"></i> Acknowledge</button>
                <?php endif ?>
            </div>
        </form>
    </section>
    <script>
    function confirmResponse(e) {
      const v = e.submitter?.value;
      if (v === 'decline') return confirm('Decline this offer? This cannot be undone from this page.');
      if (v === 'accept') return confirm('Accept this offer? Your typed name will be recorded as your acceptance.');
      return true;
    }
    </script>
    <?php endif ?>
    <?php endif ?>
<?php endif ?>
<p class="foot"><?= $orgName ?><?= !empty($brand['domain']) ? ' · ' . htmlspecialchars((string)$brand['domain']) : '' ?> · This page is personal to you. Please do not share the link.</p>
</main></body></html>
