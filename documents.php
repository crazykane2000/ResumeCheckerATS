<?php
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/offer_letters.php';

$pdo = db();
$user = currentUser();
$brand = organizationBrand();
$types = ['offer_letter' => 'Offer letters', 'hiring_letter' => 'Appointment / hiring letters', 'policy' => 'Company policies'];
$message = '';
$error = '';

$audit = static function (string $action, ?int $id, array $meta) use ($pdo, $user): void {
    try {
        $pdo->prepare("INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,?,'document_template',?,?)")
            ->execute([$user['id'] ?? null, $action, $id !== null ? (string)$id : null, json_encode($meta)]);
    } catch (Throwable $e) {}
};

$tablesReady = true;
try {
    $pdo->query('SELECT 1 FROM document_templates LIMIT 1');
} catch (Throwable $e) {
    $tablesReady = false;
    $error = 'The document tables are not installed yet. Apply database/migrations/20261112_offer_letters.sql first.';
}

if ($tablesReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Invalid request.');
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'install') {
            $n = installDefaultDocumentTemplates($pdo, (int)$user['id']);
            $audit('document_template.defaults_installed', null, ['count' => $n]);
            header('Location: documents.php?installed=' . $n);
            exit;
        }
        if ($action === 'fetch_rates' || $action === 'save_rates') {
            if ($action === 'fetch_rates') {
                $rates = fetchLatestFxRates();
            } else {
                $usd = (float)($_POST['inr_per_usd'] ?? 0);
                $aed = (float)($_POST['inr_per_aed'] ?? 0);
                $asOf = (string)($_POST['as_of'] ?? '');
                if ($usd < 20 || $usd > 500 || $aed < 5 || $aed > 200) throw new RuntimeException('Enter realistic rates (rupees per 1 USD and per 1 AED).');
                $d = DateTime::createFromFormat('Y-m-d', $asOf);
                if (!$d || $d->format('Y-m-d') !== $asOf) $asOf = date('Y-m-d');
                $rates = ['inr_per_usd' => round($usd, 4), 'inr_per_aed' => round($aed, 4), 'as_of' => $asOf, 'source' => 'manual'];
            }
            saveIntegration('fx_rates', 'configured', $rates);
            $audit('fx_rates.' . ($action === 'fetch_rates' ? 'fetched' : 'updated'), null, $rates);
            $returnId = (int)($_POST['return_id'] ?? 0);
            header('Location: documents.php?' . ($returnId > 0 ? 'id=' . $returnId . '&' : '') . 'rates=1');
            exit;
        }
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $type = (string)($_POST['doc_type'] ?? '');
            $name = trim((string)($_POST['name'] ?? ''));
            $body = sanitizeLetterHtml((string)($_POST['body_html'] ?? ''));
            $active = !empty($_POST['is_active']) ? 1 : 0;
            $sort = max(0, min(999, (int)($_POST['sort_order'] ?? 0)));
            if (!isset($types[$type])) throw new RuntimeException('Choose a valid document type.');
            if ($name === '' || mb_strlen($name) > 160) throw new RuntimeException('Name is required (max 160 characters).');
            if (trim(strip_tags($body)) === '') throw new RuntimeException('The document body is empty.');
            if ($id > 0) {
                $pdo->prepare('UPDATE document_templates SET doc_type=?,name=?,body_html=?,is_active=?,sort_order=?,updated_by=? WHERE id=?')
                    ->execute([$type, $name, $body, $active, $sort, $user['id'], $id]);
                $audit('document_template.updated', $id, ['doc_type' => $type, 'name' => $name, 'active' => $active]);
            } else {
                $pdo->prepare('INSERT INTO document_templates(doc_type,name,body_html,is_active,sort_order,updated_by) VALUES(?,?,?,?,?,?)')
                    ->execute([$type, $name, $body, $active, $sort, $user['id']]);
                $id = (int)$pdo->lastInsertId();
                $audit('document_template.created', $id, ['doc_type' => $type, 'name' => $name]);
            }
            header('Location: documents.php?id=' . $id . '&saved=1');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not save the template.';
    }
}

$all = $tablesReady ? documentTemplates($pdo, null, false) : [];
$grouped = array_fill_keys(array_keys($types), []);
foreach ($all as $t) $grouped[$t['doc_type']][] = $t;

$selectedId = (int)($_GET['id'] ?? 0);
$newType = (string)($_GET['new'] ?? '');
$current = null;
foreach ($all as $t) if ((int)$t['id'] === $selectedId) $current = $t;
if (!$current && isset($types[$newType])) {
    $current = ['id' => 0, 'doc_type' => $newType, 'name' => '', 'body_html' => '<p>Dear {{candidate_name}},</p><p></p>', 'is_active' => 1, 'sort_order' => count($grouped[$newType]) + 1];
}
if (!$current && $all) $current = $all[0];
if (isset($_GET['saved'])) $message = 'Template saved.';
if (isset($_GET['rates'])) $message = 'Exchange rates saved. New letters will use them for the USD / AED line.';
$fx = fxRates();
if (isset($_GET['installed'])) $message = (int)$_GET['installed'] . ' starter templates installed. Review the wording with HR/legal before sending.';

$sample = [
    'candidate_name' => 'Aarav Mehta', 'designation' => 'Senior Software Engineer', 'company_name' => (string)$brand['name'],
    'ctc' => '₹18,00,000 per annum', 'ctc_converted' => ctcConversionText('₹18,00,000 per annum'), 'joining_date' => date('d F Y', strtotime('+21 days')), 'location' => 'Remote (India)',
    'reporting_to' => 'Engineering Manager', 'respond_by' => date('d F Y', strtotime('+7 days')), 'offer_date' => date('d F Y'),
    'signatory_name' => (string)($user['name'] ?? 'HR Team'), 'signatory_title' => 'Human Resources',
];

$activePage = 'documents';
$pageTitle = 'Letters & Policies';
$pageStyles = '<style>' . letterPaperCss() . '
.doc-grid{display:grid;grid-template-columns:260px minmax(0,1fr) minmax(0,1fr);gap:12px;margin-top:12px;align-items:start}
.doc-list{padding:14px}.doc-list h3{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin:14px 0 6px;display:flex;justify-content:space-between;align-items:center}
.doc-list h3:first-child{margin-top:0}.doc-list h3 a{font-size:11px;text-transform:none;letter-spacing:0;color:var(--primary);text-decoration:none;font-weight:600}
.doc-link{display:flex;justify-content:space-between;gap:6px;padding:7px 9px;border-radius:6px;color:var(--text);text-decoration:none;font-size:12px}
.doc-link:hover{background:var(--soft)}.doc-link.active{background:var(--primary-soft);color:var(--primary);font-weight:600}
.doc-link small{color:var(--muted);font-size:10px}.doc-editor,.doc-preview{padding:16px}
.doc-editor label{display:block;font-size:11px;font-weight:600;color:var(--muted);margin:10px 0 5px}
.doc-row{display:grid;grid-template-columns:1fr 120px 90px;gap:8px;align-items:end}
.rte-toolbar{display:flex;flex-wrap:wrap;gap:4px;padding:6px;border:1px solid var(--line);border-bottom:0;border-radius:8px 8px 0 0;background:var(--soft)}
.rte-toolbar button{border:1px solid var(--line);background:#fff;border-radius:6px;padding:4px 8px;font-size:12px;cursor:pointer;color:var(--text)}
.rte-toolbar button:hover{border-color:var(--primary);color:var(--primary)}
.rte{min-height:420px;max-height:62vh;overflow:auto;border:1px solid var(--line);border-radius:0 0 8px 8px;padding:14px;font-size:13px;line-height:1.6;background:#fff;outline:none}
.rte:focus{border-color:var(--primary)}.rte table{border-collapse:collapse;width:100%}.rte th,.rte td{border:1px solid #e3e3ee;padding:6px 8px;text-align:left}
.rte-source{display:none;width:100%;min-height:420px;font:12px/1.5 ui-monospace,Consolas,monospace;border:1px solid var(--line);border-radius:0 0 8px 8px;padding:12px}
.chips{display:flex;flex-wrap:wrap;gap:5px;margin-top:8px}.chip-btn{border:1px dashed #c9bdfd;background:var(--primary-soft);color:#4b2fd1;border-radius:6px;padding:3px 7px;font-size:11px;cursor:pointer}
.doc-actions{display:flex;gap:8px;align-items:center;margin-top:14px;flex-wrap:wrap}
.doc-note{font-size:11px;color:var(--muted);margin-top:8px;line-height:1.5}
.doc-flash{padding:10px 12px;border-radius:8px;font-size:12px;margin-top:10px}.doc-flash.ok{background:var(--green-soft);color:#0f7a52}.doc-flash.err{background:#fff0f2;color:#b4233c}
.doc-preview .letter-paper{font-size:12.5px;max-width:none;box-shadow:none}.doc-preview .letter-inner{padding:26px 28px 20px}.doc-preview .letter-foot{margin:0 28px}
.empty-state{padding:30px;text-align:center}
.fx-panel{margin-top:12px;padding:14px 16px;display:flex;justify-content:space-between;gap:16px;align-items:flex-end;flex-wrap:wrap}
.fx-form{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap}.fx-form label{display:flex;flex-direction:column;gap:4px;font-size:10px;font-weight:600;color:var(--muted)}.fx-form .control{width:120px}
@media(max-width:1200px){.doc-grid{grid-template-columns:1fr}}
</style>';
require __DIR__ . '/views/partials/header.php';
?>
<section class="panel" style="padding:16px 18px">
    <div class="kicker"><i class="fa-solid fa-file-signature"></i> Hiring documents</div>
    <h1 style="margin:4px 0 4px;font-size:20px">Letters &amp; Policies</h1>
    <p class="muted" style="margin:0;font-size:12px">Edit the offer letter, appointment letter and policy templates used on <a href="employees.php">offers</a>. Placeholders like <code>{{candidate_name}}</code> are filled per candidate, and every letter can still be edited before it is sent. Letters already sent keep their own copy.</p>
    <?php if ($message): ?><div class="doc-flash ok"><?= htmlspecialchars($message) ?></div><?php endif ?>
    <?php if ($error): ?><div class="doc-flash err"><?= htmlspecialchars($error) ?></div><?php endif ?>
</section>

<?php if ($tablesReady): ?>
<section class="panel fx-panel">
    <div>
        <strong style="font-size:13px"><i class="fa-solid fa-money-bill-transfer" style="color:var(--primary)"></i> Currency conversion for CTC</strong>
        <p class="muted" style="margin:3px 0 0;font-size:11px">Letters show the CTC in USD and AED next to the rupee amount via <code>{{ctc_converted}}</code>, marked as indicative with the rate and date used.
            <?php if ($fx['inr_per_usd']): ?>Current: 1 USD = ₹<?= htmlspecialchars((string)$fx['inr_per_usd']) ?>, 1 AED = ₹<?= htmlspecialchars((string)$fx['inr_per_aed']) ?><?= $fx['as_of'] ? ' · as of ' . htmlspecialchars(date('d M Y', strtotime($fx['as_of']))) : '' ?><?= $fx['source'] ? ' · ' . htmlspecialchars($fx['source']) : '' ?>.<?php else: ?><strong style="color:#8c610d">Not set yet — the USD/AED line stays hidden until rates are saved.</strong><?php endif ?></p>
    </div>
    <form method="post" class="fx-form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="return_id" value="<?= (int)($current['id'] ?? 0) ?>">
        <label>₹ per 1 USD<input class="control" type="number" step="0.0001" min="20" max="500" name="inr_per_usd" value="<?= htmlspecialchars((string)($fx['inr_per_usd'] ?? '')) ?>"></label>
        <label>₹ per 1 AED<input class="control" type="number" step="0.0001" min="5" max="200" name="inr_per_aed" value="<?= htmlspecialchars((string)($fx['inr_per_aed'] ?? '')) ?>"></label>
        <label>As of<input class="control" type="date" name="as_of" value="<?= htmlspecialchars((string)($fx['as_of'] ?? date('Y-m-d'))) ?>"></label>
        <button class="btn" type="submit" name="action" value="save_rates" style="background:#fff;border:1px solid var(--line)">Save rates</button>
        <button class="btn btn-primary" type="submit" name="action" value="fetch_rates" formnovalidate><i class="fa-solid fa-rotate"></i> Fetch latest</button>
    </form>
</section>
<?php endif ?>

<?php if ($tablesReady && !$all && (!$current || ($current['id'] ?? 0) === 0)): ?>
<section class="panel empty-state" style="margin-top:12px">
    <h2 style="font-size:15px;margin:0 0 6px">No templates yet</h2>
    <p class="muted" style="font-size:12px;margin:0 0 14px">Install professional starter templates (offer letter, appointment letter, Code of Conduct, Leave &amp; Attendance, Confidentiality &amp; IP), then edit them to match your company.</p>
    <form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="action" value="install">
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-wand-magic-sparkles"></i> Install starter templates</button></form>
</section>
<?php elseif ($tablesReady && $current): ?>
<section class="doc-grid">
    <aside class="panel doc-list">
        <?php foreach ($types as $type => $label): ?>
            <h3><?= htmlspecialchars($label) ?> <a href="documents.php?new=<?= urlencode($type) ?>">+ New</a></h3>
            <?php if (!$grouped[$type]): ?><p class="muted" style="font-size:11px;margin:4px 9px">None yet.</p><?php endif ?>
            <?php foreach ($grouped[$type] as $t): ?>
                <a class="doc-link <?= (int)$t['id'] === (int)$current['id'] ? 'active' : '' ?>" href="documents.php?id=<?= (int)$t['id'] ?>">
                    <span><?= htmlspecialchars($t['name']) ?></span><?php if (!$t['is_active']): ?><small>inactive</small><?php endif ?>
                </a>
            <?php endforeach ?>
        <?php endforeach ?>
    </aside>

    <article class="panel doc-editor">
        <form method="post" id="docForm" onsubmit="syncEditor()">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)$current['id'] ?>">
            <input type="hidden" name="body_html" id="bodyHtml">
            <div class="doc-row">
                <div><label for="docName">Name</label><input class="control" id="docName" name="name" maxlength="160" required value="<?= htmlspecialchars($current['name']) ?>"></div>
                <div><label for="docType">Type</label><select class="control" id="docType" name="doc_type"><?php foreach ($types as $type => $label): ?><option value="<?= $type ?>" <?= $current['doc_type'] === $type ? 'selected' : '' ?>><?= htmlspecialchars(rtrim($label, 's')) ?></option><?php endforeach ?></select></div>
                <div><label for="docSort">Order</label><input class="control" id="docSort" type="number" min="0" max="999" name="sort_order" value="<?= (int)$current['sort_order'] ?>"></div>
            </div>
            <label>Content</label>
            <div class="rte-toolbar" role="toolbar" aria-label="Formatting">
                <button type="button" onclick="fmt('formatBlock','h2')" title="Heading">H2</button>
                <button type="button" onclick="fmt('formatBlock','h3')" title="Sub-heading">H3</button>
                <button type="button" onclick="fmt('formatBlock','p')" title="Paragraph">¶</button>
                <button type="button" onclick="fmt('bold')" title="Bold"><b>B</b></button>
                <button type="button" onclick="fmt('italic')" title="Italic"><i>I</i></button>
                <button type="button" onclick="fmt('underline')" title="Underline"><u>U</u></button>
                <button type="button" onclick="fmt('insertUnorderedList')" title="Bullet list"><i class="fa-solid fa-list-ul"></i></button>
                <button type="button" onclick="fmt('insertOrderedList')" title="Numbered list"><i class="fa-solid fa-list-ol"></i></button>
                <button type="button" onclick="insertTermsTable()" title="Insert key-terms table"><i class="fa-solid fa-table"></i></button>
                <button type="button" onclick="fmt('removeFormat')" title="Clear formatting"><i class="fa-solid fa-text-slash"></i></button>
                <button type="button" onclick="toggleSource()" title="Edit HTML" id="srcBtn"><i class="fa-solid fa-code"></i> HTML</button>
            </div>
            <div class="rte" id="rte" contenteditable="true" spellcheck="true"><?= sanitizeLetterHtml((string)$current['body_html']) ?></div>
            <textarea class="rte-source" id="rteSource" spellcheck="false"></textarea>
            <label>Placeholders <span style="font-weight:400">(click to insert at the cursor)</span></label>
            <div class="chips"><?php foreach (offerPlaceholders() as $key => $help): ?><button type="button" class="chip-btn" title="<?= htmlspecialchars($help) ?>" onclick="insertPlaceholder('<?= $key ?>')">{{<?= $key ?>}}</button><?php endforeach ?></div>
            <div class="doc-actions">
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save template</button>
                <label style="margin:0;display:flex;gap:6px;align-items:center;font-weight:500;color:var(--text)"><input type="checkbox" name="is_active" value="1" <?= $current['is_active'] ? 'checked' : '' ?>> Active (available when creating letters)</label>
            </div>
            <p class="doc-note">Allowed formatting: headings, paragraphs, bold/italic/underline, lists, tables and links. Scripts, images and styles are removed on save. Templates are not deleted; untick <em>Active</em> to retire one.</p>
        </form>
    </article>

    <article class="panel doc-preview">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px"><strong style="font-size:13px">Live preview</strong><small class="muted">Sample candidate data</small></div>
        <div id="previewHost"><?= letterPaperHtml('', $brand, ['label' => $current['doc_type'] === 'policy' ? 'Company policy' : (OFFER_LETTER_TYPES[$current['doc_type']] ?? 'Letter'), 'ref' => 'Sample']) ?></div>
    </article>
</section>
<script>
const SAMPLE = <?= json_encode($sample, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const rte = document.getElementById('rte'), src = document.getElementById('rteSource'), previewBody = () => document.querySelector('#previewHost .letter-body');
let sourceMode = false;
const escapeText = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
function currentHtml() { return sourceMode ? src.value : rte.innerHTML; }
function renderPreview() {
  const html = currentHtml().replace(/\{\{\s*([a-z_]+)\s*\}\}/g, (m, k) => k in SAMPLE ? escapeText(SAMPLE[k]) : m);
  // Preview only: strip script/style/event handlers client-side; the server sanitises on save.
  const tpl = document.createElement('template'); tpl.innerHTML = html;
  tpl.content.querySelectorAll('script,style,iframe,object,embed,img,svg').forEach(n => n.remove());
  tpl.content.querySelectorAll('*').forEach(el => [...el.attributes].forEach(a => { if (!['href','colspan','rowspan'].includes(a.name)) el.removeAttribute(a.name); }));
  previewBody().replaceChildren(tpl.content);
}
function fmt(cmd, val) { if (sourceMode) return; rte.focus(); document.execCommand(cmd, false, val ?? null); renderPreview(); }
function insertPlaceholder(key) {
  const text = '{{' + key + '}}';
  if (sourceMode) { const s = src.selectionStart, e = src.selectionEnd; src.setRangeText(text, s, e, 'end'); src.focus(); }
  else { rte.focus(); document.execCommand('insertText', false, text); }
  renderPreview();
}
function insertTermsTable() {
  if (sourceMode) return;
  rte.focus();
  document.execCommand('insertHTML', false, '<table><tr><th>Designation</th><td>{{designation}}</td></tr><tr><th>Annual CTC</th><td>{{ctc}}</td></tr><tr><th>Date of joining</th><td>{{joining_date}}</td></tr><tr><th>Work location</th><td>{{location}}</td></tr><tr><th>Reporting to</th><td>{{reporting_to}}</td></tr></table><p></p>');
  renderPreview();
}
function toggleSource() {
  sourceMode = !sourceMode;
  if (sourceMode) { src.value = rte.innerHTML; rte.style.display = 'none'; src.style.display = 'block'; }
  else { rte.innerHTML = src.value; src.style.display = 'none'; rte.style.display = 'block'; }
  document.getElementById('srcBtn').classList.toggle('active', sourceMode);
  renderPreview();
}
function syncEditor() { document.getElementById('bodyHtml').value = currentHtml(); }
rte.addEventListener('input', renderPreview); src.addEventListener('input', renderPreview);
renderPreview();
</script>
<?php endif ?>
<?php require __DIR__ . '/views/partials/footer.php'; ?>
