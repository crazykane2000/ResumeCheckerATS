<?php
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/offer_letters.php';

$pdo = db();
$tablesReady = true;
try {
    $pdo->query('SELECT 1 FROM offer_letters LIMIT 1');
} catch (Throwable $e) {
    $tablesReady = false;
}

$offers = [];
$employees = [];
if ($tablesReady) {
    // Latest offer letter per candidate that is not yet hired.
    $offers = $pdo->query("SELECT o.*, c.name candidate_name, c.email, c.stage, j.title job_title
        FROM offer_letters o
        JOIN candidates c ON c.id=o.candidate_id
        LEFT JOIN jobs j ON j.id=o.job_id
        WHERE o.letter_type='offer_letter' AND c.stage<>'Hired'
          AND o.id=(SELECT MAX(o2.id) FROM offer_letters o2 WHERE o2.candidate_id=o.candidate_id AND o2.letter_type='offer_letter')
        ORDER BY FIELD(o.status,'accepted','sent','draft','declined','withdrawn'), o.updated_at DESC")->fetchAll();
    $employees = $pdo->query("SELECT c.id, c.name, c.email, c.phone, j.title job_title,
          (SELECT a.created_at FROM audit_events a WHERE a.entity_type='candidate' AND a.entity_id=c.id AND a.action='candidate.hired' ORDER BY a.id DESC LIMIT 1) hired_at,
          o.id offer_id, o.designation, o.joining_date, o.ctc_text, o.location,
          (SELECT COUNT(*) FROM offer_letters h WHERE h.candidate_id=c.id AND h.letter_type='hiring_letter' AND h.status IN ('sent','accepted')) appointment_letters
        FROM candidates c
        LEFT JOIN jobs j ON j.id=c.job_id
        LEFT JOIN offer_letters o ON o.id=(SELECT MAX(o3.id) FROM offer_letters o3 WHERE o3.candidate_id=c.id AND o3.letter_type='offer_letter' AND o3.status='accepted')
        WHERE c.stage='Hired'
        ORDER BY COALESCE(o.joining_date, '9999-12-31') DESC, c.name")->fetchAll();
} else {
    $employees = $pdo->query("SELECT c.id, c.name, c.email, c.phone, j.title job_title, NULL hired_at, NULL offer_id, NULL designation, NULL joining_date, NULL ctc_text, NULL location, 0 appointment_letters FROM candidates c LEFT JOIN jobs j ON j.id=c.job_id WHERE c.stage='Hired' ORDER BY c.name")->fetchAll();
}

$fmt = static fn(?string $d): string => ($d && strtotime($d)) ? date('d M Y', strtotime($d)) : '—';
$statusLabels = ['draft' => ['Draft', '#64748b', '#f1f5f9'], 'sent' => ['Awaiting response', '#8c610d', '#fff7e7'], 'accepted' => ['Accepted', '#0f7a52', '#ecfaf4'], 'declined' => ['Declined', '#b4233c', '#fff0f2'], 'withdrawn' => ['Withdrawn', '#64748b', '#f1f5f9']];
$counts = array_count_values(array_column($offers, 'status'));
$tab = ($_GET['tab'] ?? '') === 'employees' ? 'employees' : 'offers';

$activePage = 'employees';
$pageTitle = 'Offers & Employees';
$pageStyles = '<style>
.oe-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:12px}
.oe-stat{padding:12px 14px}.oe-stat small{display:block;color:var(--muted);font-size:11px}.oe-stat strong{font-size:20px}
.oe-tabs{display:flex;gap:6px;margin:14px 0 0}.oe-tabs a{padding:8px 12px;border-radius:8px;border:1px solid var(--line);background:#fff;color:var(--text);text-decoration:none;font-size:12px;font-weight:600}
.oe-tabs a.active{background:var(--primary-soft);border-color:#d9cffd;color:var(--primary)}
.oe-table{padding:6px 14px 14px;margin-top:10px;overflow:auto}.oe-table table{width:100%;border-collapse:collapse;font-size:12px;min-width:760px}
.oe-table th,.oe-table td{padding:10px 6px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}.oe-table th{font-size:10px;text-transform:uppercase;color:var(--muted);letter-spacing:.04em}
.status-pill{display:inline-block;padding:3px 7px;border-radius:6px;font-size:10px;font-weight:700}
.oe-next{font-size:11px;color:var(--muted)}.oe-link{color:var(--primary);font-weight:600;text-decoration:none;font-size:12px}
.oe-empty{padding:26px;text-align:center;color:var(--muted);font-size:12px}
</style>';
require __DIR__ . '/views/partials/header.php';
?>
<section class="panel" style="padding:16px 18px">
    <div class="kicker"><i class="fa-solid fa-id-badge"></i> Offers &amp; employees</div>
    <h1 style="margin:4px 0 2px;font-size:20px">Offers &amp; Employees</h1>
    <p class="muted" style="margin:0;font-size:12px">Track offer letters until the candidate joins. Hired people leave the active pipeline and are listed here. Letter wording lives in <a href="documents.php">Letters &amp; Policies</a>.</p>
    <?php if (!$tablesReady): ?><p style="margin:10px 0 0;padding:10px 12px;border-radius:8px;background:var(--amber-soft);color:#8c610d;font-size:12px">Offer letters are not installed yet. Apply <code>database/migrations/20261112_offer_letters.sql</code>.</p><?php endif ?>
</section>

<section class="oe-stats">
    <article class="panel oe-stat"><small>Awaiting response</small><strong><?= (int)($counts['sent'] ?? 0) ?></strong></article>
    <article class="panel oe-stat"><small>Accepted — ready to hire</small><strong style="color:#0f7a52"><?= (int)($counts['accepted'] ?? 0) ?></strong></article>
    <article class="panel oe-stat"><small>Drafts</small><strong><?= (int)($counts['draft'] ?? 0) ?></strong></article>
    <article class="panel oe-stat"><small>Declined</small><strong style="color:#b4233c"><?= (int)($counts['declined'] ?? 0) ?></strong></article>
    <article class="panel oe-stat"><small>Employees (hired)</small><strong style="color:var(--primary)"><?= count($employees) ?></strong></article>
</section>

<nav class="oe-tabs"><a href="employees.php" class="<?= $tab === 'offers' ? 'active' : '' ?>">Offer tracker (<?= count($offers) ?>)</a><a href="employees.php?tab=employees" class="<?= $tab === 'employees' ? 'active' : '' ?>">Employees (<?= count($employees) ?>)</a></nav>

<?php if ($tab === 'offers'): ?>
<section class="panel oe-table">
    <?php if (!$offers): ?><div class="oe-empty">No offers yet. Open a selected candidate in the <a href="pipeline.php">Pipeline</a> and use <strong>Create offer letter</strong> from the hiring journey.</div><?php else: ?>
    <table>
        <thead><tr><th>Candidate</th><th>Role</th><th>Joining</th><th>CTC</th><th>Status</th><th>Next step</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($offers as $o): [$sl, $sc, $sb] = $statusLabels[$o['status']] ?? [$o['status'], '#64748b', '#f1f5f9'];
            $next = match ($o['status']) {
                'draft' => 'Review and send the letter',
                'sent' => ($o['viewed_at'] ? 'Opened ' . $fmt($o['viewed_at']) . ' · ' : 'Not opened yet · ') . ($o['respond_by'] ? 'respond by ' . $fmt($o['respond_by']) : 'awaiting response'),
                'accepted' => 'Accepted ' . $fmt($o['responded_at']) . ' — mark as hired',
                'declined' => 'Declined ' . $fmt($o['responded_at']) . ($o['response_note'] ? ' · "' . mb_strimwidth((string)$o['response_note'], 0, 60, '…') . '"' : ''),
                default => 'Withdrawn',
            }; ?>
            <tr>
                <td><strong><?= htmlspecialchars($o['candidate_name']) ?></strong><br><small class="muted"><?= htmlspecialchars((string)$o['email']) ?></small></td>
                <td><?= htmlspecialchars($o['designation']) ?><br><small class="muted"><?= htmlspecialchars((string)($o['job_title'] ?? '')) ?></small></td>
                <td><?= $fmt($o['joining_date']) ?></td>
                <td><?= htmlspecialchars((string)($o['ctc_text'] ?: '—')) ?></td>
                <td><span class="status-pill" style="color:<?= $sc ?>;background:<?= $sb ?>"><?= htmlspecialchars($sl) ?></span></td>
                <td class="oe-next"><?= htmlspecialchars($next) ?></td>
                <td><a class="oe-link" href="offer_letter.php?candidate_id=<?= urlencode($o['candidate_id']) ?>">Open <i class="fa-solid fa-arrow-right"></i></a></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    <?php endif ?>
</section>
<?php else: ?>
<section class="panel oe-table">
    <?php if (!$employees): ?><div class="oe-empty">Nobody has been marked as hired yet.</div><?php else: ?>
    <table>
        <thead><tr><th>Employee</th><th>Designation</th><th>Joining date</th><th>CTC</th><th>Location</th><th>Hired on</th><th>Letters</th></tr></thead>
        <tbody>
        <?php foreach ($employees as $e): ?>
            <tr>
                <td><strong><?= htmlspecialchars($e['name']) ?></strong><br><small class="muted"><?= htmlspecialchars((string)($e['email'] ?: '')) ?><?= $e['phone'] ? ' · ' . htmlspecialchars($e['phone']) : '' ?></small></td>
                <td><?= htmlspecialchars((string)($e['designation'] ?: $e['job_title'] ?: '—')) ?></td>
                <td><?= $fmt($e['joining_date']) ?></td>
                <td><?= htmlspecialchars((string)($e['ctc_text'] ?: '—')) ?></td>
                <td><?= htmlspecialchars((string)($e['location'] ?: '—')) ?></td>
                <td><?= $fmt($e['hired_at']) ?></td>
                <td>
                    <a class="oe-link" href="offer_letter.php?candidate_id=<?= urlencode($e['id']) ?>&amp;type=hiring_letter"><?= (int)$e['appointment_letters'] ? 'Letters' : 'Send appointment letter' ?></a>
                    · <a class="oe-link" href="candidate_detail.php?id=<?= urlencode($e['id']) ?>">Profile</a>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    <?php endif ?>
</section>
<?php endif ?>
<?php require __DIR__ . '/views/partials/footer.php'; ?>
