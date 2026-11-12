<?php
// Hiring reports: funnel by source, time-to-invite / time-to-hire, offer acceptance,
// monthly applications and a per-job table. Read-only; every metric is defined on the page.
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/workspace.php';

$pdo = db();
$safe = static function (string $sql) use ($pdo): array {
    try { return $pdo->query($sql)->fetchAll(); } catch (Throwable $e) { return []; }
};

$candidates = $safe('SELECT c.id, c.source_name, c.job_id, c.stage, c.created_at, j.title job_title FROM candidates c LEFT JOIN jobs j ON j.id=c.job_id');
$wishlisted = array_flip(array_column($safe("SELECT DISTINCT candidate_id FROM wishlist_items WHERE disposition IN ('wishlist','selected')"), 'candidate_id'));
$invites = [];
foreach ($safe("SELECT candidate_id, status, outcome, sent_at, created_at FROM interview_invitations WHERE status<>'draft'") as $r) $invites[$r['candidate_id']][] = $r;
$offers = [];
foreach ($safe("SELECT candidate_id, status, sent_at, responded_at FROM offer_letters WHERE letter_type='offer_letter' AND status<>'withdrawn'") as $r) $offers[$r['candidate_id']][] = $r;
$hiredAt = [];
foreach ($safe("SELECT entity_id, MAX(created_at) at FROM audit_events WHERE action='candidate.hired' AND entity_type='candidate' GROUP BY entity_id") as $r) $hiredAt[$r['entity_id']] = $r['at'];

$days = static fn(?string $from, ?string $to): ?float => ($from && $to && strtotime($from) && strtotime($to) && strtotime($to) >= strtotime($from)) ? (strtotime($to) - strtotime($from)) / 86400 : null;
$median = static function (array $v): ?float {
    $v = array_values(array_filter($v, fn($x) => $x !== null));
    if (!$v) return null;
    sort($v);
    $n = count($v);
    return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
};
$normSource = static fn(?string $s): string => normalizeCandidateSource($s) ?: 'Direct upload';

// Per-candidate stage flags (definitions shown on the page).
$rows = [];
foreach ($candidates as $c) {
    $id = $c['id'];
    $stage = strtolower((string)$c['stage']);
    $inv = $invites[$id] ?? [];
    $off = $offers[$id] ?? [];
    $firstInvite = null;
    foreach ($inv as $i) {
        $at = $i['sent_at'] ?: $i['created_at'];
        if ($at && ($firstInvite === null || $at < $firstInvite)) $firstInvite = $at;
    }
    $interviewed = in_array($stage, ['round 2', 'offer', 'hired'], true) || (bool)array_filter($inv, fn($i) => $i['status'] === 'confirmed' && $i['outcome'] !== 'no_show' || in_array($i['outcome'], ['completed', 'selected', 'rejected', 'offer_sent', 'offer_accepted', 'offer_declined', 'hired'], true));
    $offered = in_array($stage, ['offer', 'hired'], true) || (bool)$off;
    $rows[] = [
        'source' => $normSource($c['source_name']),
        'job' => (string)($c['job_title'] ?: 'No job'),
        'created_at' => (string)$c['created_at'],
        'shortlisted' => isset($wishlisted[$id]) || $stage !== 'applied' || (bool)$inv,
        'invited' => $firstInvite !== null,
        'interviewed' => $interviewed,
        'offered' => $offered,
        'hired' => $stage === 'hired',
        'days_to_invite' => $days($c['created_at'], $firstInvite),
        'days_to_hire' => $stage === 'hired' ? $days($c['created_at'], $hiredAt[$id] ?? null) : null,
    ];
}

$stages = ['applicants' => 'Applied', 'shortlisted' => 'Shortlisted', 'invited' => 'Invited', 'interviewed' => 'Interviewed', 'offered' => 'Offered', 'hired' => 'Hired'];
$count = static function (array $set) use ($stages): array {
    $out = ['applicants' => count($set)];
    foreach (array_slice(array_keys($stages), 1) as $k) $out[$k] = count(array_filter($set, fn($r) => $r[$k]));
    return $out;
};
$total = $count($rows);
$bySource = [];
foreach ($rows as $r) $bySource[$r['source']][] = $r;
uasort($bySource, fn($a, $b) => count($b) <=> count($a));
$byJob = [];
foreach ($rows as $r) $byJob[$r['job']][] = $r;
uasort($byJob, fn($a, $b) => count($b) <=> count($a));

$offerRows = array_merge(...array_values($offers ?: [[]]));
$accepted = count(array_filter($offerRows, fn($o) => $o['status'] === 'accepted'));
$declined = count(array_filter($offerRows, fn($o) => $o['status'] === 'declined'));
$pendingOffers = count(array_filter($offerRows, fn($o) => $o['status'] === 'sent'));
$acceptRate = ($accepted + $declined) > 0 ? round($accepted / ($accepted + $declined) * 100) : null;
$medInvite = $median(array_column($rows, 'days_to_invite'));
$medHire = $median(array_column($rows, 'days_to_hire'));

// Applications per month, last 6 months (by application date).
$months = [];
for ($i = 5; $i >= 0; $i--) $months[date('Y-m', strtotime("first day of -$i month"))] = 0;
foreach ($rows as $r) {
    $m = $r['created_at'] && strtotime($r['created_at']) ? date('Y-m', strtotime($r['created_at'])) : null;
    if ($m !== null && isset($months[$m])) $months[$m]++;
}
$maxMonth = max(1, ...array_values($months));
$pct = static fn(int $n, int $of): string => $of > 0 ? round($n / $of * 100) . '%' : '—';
$fmtDays = static fn(?float $d): string => $d === null ? '—' : (round($d) . ' day' . (round($d) == 1 ? '' : 's'));

$activePage = 'reports';
$pageTitle = 'Hiring Reports';
$pageStyles = '<style>
.rp-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-top:12px}
.rp-kpi{padding:14px 16px}.rp-kpi small{display:block;color:var(--muted);font-size:11px}.rp-kpi strong{display:block;font-size:24px;line-height:1.2;margin-top:4px;color:var(--text)}.rp-kpi span{display:block;font-size:11px;color:var(--muted);margin-top:2px}
.rp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;margin-top:12px}
.rp-card{padding:16px}.rp-card h2{font-size:14px;margin:0 0 2px}.rp-card>small{color:var(--muted);font-size:11px}
.fn{margin-top:12px;display:grid;gap:7px}
.fn-row{display:grid;grid-template-columns:86px 1fr 74px;gap:8px;align-items:center;font-size:12px}
.fn-row label{color:var(--muted)}.fn-row output{text-align:right;color:var(--text);font-variant-numeric:tabular-nums}
.fn-track{position:relative;height:14px;border-radius:4px;background:#f3f1fb}
.fn-bar{position:absolute;left:0;top:0;bottom:0;border-radius:0 4px 4px 0;background:#6f45ff;min-width:2px}
.has-tip{position:relative}.has-tip:hover::after{content:attr(data-tip);position:absolute;left:50%;bottom:calc(100% + 6px);transform:translateX(-50%);white-space:nowrap;background:#1e1b2e;color:#fff;font-size:11px;padding:5px 8px;border-radius:6px;z-index:5;pointer-events:none}
.cols{display:flex;align-items:flex-end;gap:10px;height:160px;margin-top:16px;padding:0 4px;border-bottom:1px solid var(--line)}
.col{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;gap:4px}
.col-bar{width:60%;max-width:44px;border-radius:4px 4px 0 0;background:#6f45ff;min-height:2px}
.col-val{font-size:11px;color:var(--text);font-variant-numeric:tabular-nums}
.col-labels{display:flex;gap:10px;padding:6px 4px 0}.col-labels span{flex:1;text-align:center;font-size:11px;color:var(--muted)}
.rp-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:10px}.rp-table th,.rp-table td{padding:8px 6px;border-bottom:1px solid var(--line);text-align:right;font-variant-numeric:tabular-nums}
.rp-table th:first-child,.rp-table td:first-child{text-align:left}.rp-table th{font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:600}
.rp-defs{font-size:11.5px;color:var(--muted);line-height:1.6;margin:8px 0 0;padding-left:18px}
.rp-empty{color:var(--muted);font-size:12px;margin-top:10px}
</style>';
require __DIR__ . '/views/partials/header.php';
?>
<section class="panel" style="padding:16px 18px">
    <div class="kicker"><i class="fa-solid fa-chart-column"></i> Hiring reports</div>
    <h1 style="margin:4px 0 2px;font-size:20px">Hiring reports</h1>
    <p class="muted" style="margin:0;font-size:12px">Funnel, speed and offer outcomes from recorded data across <?= number_format($total['applicants']) ?> applications. Definitions are at the bottom of the page.</p>
</section>

<section class="rp-kpis">
    <article class="panel rp-kpi"><small>Applications</small><strong><?= number_format($total['applicants']) ?></strong><span><?= array_sum($months) ?> in the last 6 months</span></article>
    <article class="panel rp-kpi"><small>Shortlisted</small><strong><?= $pct($total['shortlisted'], $total['applicants']) ?></strong><span><?= $total['shortlisted'] ?> of <?= $total['applicants'] ?></span></article>
    <article class="panel rp-kpi"><small>Interviewed</small><strong><?= $total['interviewed'] ?></strong><span><?= $pct($total['interviewed'], max(1, $total['invited'])) ?> of <?= $total['invited'] ?> invited</span></article>
    <article class="panel rp-kpi"><small>Offer acceptance</small><strong><?= $acceptRate === null ? '—' : $acceptRate . '%' ?></strong><span><?= $accepted ?> accepted · <?= $declined ?> declined · <?= $pendingOffers ?> pending</span></article>
    <article class="panel rp-kpi"><small>Median days to first invite</small><strong><?= $fmtDays($medInvite) ?></strong><span><?= $total['invited'] ?> candidates invited</span></article>
    <article class="panel rp-kpi"><small>Median time to hire</small><strong><?= $fmtDays($medHire) ?></strong><span><?= $total['hired'] ? $total['hired'] . ' hired' : 'No hires recorded yet' ?></span></article>
</section>

<section class="rp-grid">
    <?php foreach ($bySource as $source => $set): $c = $count($set); ?>
    <article class="panel rp-card">
        <h2><?= htmlspecialchars($source) ?></h2>
        <small>Funnel for applications from this source · bar length = share of its <?= $c['applicants'] ?> applications</small>
        <div class="fn">
            <?php foreach ($stages as $k => $label): $n = $c[$k]; $share = $c['applicants'] ? $n / $c['applicants'] * 100 : 0; ?>
            <div class="fn-row has-tip" data-tip="<?= htmlspecialchars($label . ': ' . $n . ' (' . $pct($n, $c['applicants']) . ' of applications)') ?>">
                <label><?= htmlspecialchars($label) ?></label>
                <div class="fn-track"><div class="fn-bar" style="width:<?= number_format($share, 2, '.', '') ?>%"></div></div>
                <output><?= $n ?> <span class="muted">· <?= $pct($n, $c['applicants']) ?></span></output>
            </div>
            <?php endforeach ?>
        </div>
    </article>
    <?php endforeach ?>

    <article class="panel rp-card">
        <h2>Applications per month</h2>
        <small>By application date, last 6 months</small>
        <div class="cols" role="img" aria-label="Applications per month">
            <?php foreach ($months as $m => $n): ?>
            <div class="col has-tip" data-tip="<?= htmlspecialchars(date('F Y', strtotime($m . '-01')) . ': ' . $n . ' applications') ?>">
                <span class="col-val"><?= $n ?></span>
                <div class="col-bar" style="height:<?= number_format($n / $maxMonth * 82, 2, '.', '') ?>%"></div>
            </div>
            <?php endforeach ?>
        </div>
        <div class="col-labels"><?php foreach (array_keys($months) as $m): ?><span><?= htmlspecialchars(date('M', strtotime($m . '-01'))) ?></span><?php endforeach ?></div>
    </article>
</section>

<section class="panel rp-card" style="margin-top:12px;overflow:auto">
    <h2>Funnel by source (table)</h2>
    <table class="rp-table">
        <thead><tr><th>Source</th><?php foreach ($stages as $label): ?><th><?= htmlspecialchars($label) ?></th><?php endforeach ?><th>Applied → offer</th><th>Median days to invite</th></tr></thead>
        <tbody>
        <?php foreach ($bySource as $source => $set): $c = $count($set); ?>
            <tr><td><?= htmlspecialchars($source) ?></td><?php foreach (array_keys($stages) as $k): ?><td><?= $c[$k] ?></td><?php endforeach ?><td><?= $pct($c['offered'], $c['applicants']) ?></td><td><?= $fmtDays($median(array_column($set, 'days_to_invite'))) ?></td></tr>
        <?php endforeach ?>
        </tbody>
    </table>
</section>

<section class="panel rp-card" style="margin-top:12px;overflow:auto">
    <h2>By job</h2>
    <table class="rp-table">
        <thead><tr><th>Job</th><th>Applied</th><th>Shortlisted</th><th>Interviewed</th><th>Offered</th><th>Hired</th><th>Median days to invite</th></tr></thead>
        <tbody>
        <?php foreach ($byJob as $job => $set): $c = $count($set); ?>
            <tr><td><?= htmlspecialchars($job) ?></td><td><?= $c['applicants'] ?></td><td><?= $c['shortlisted'] ?></td><td><?= $c['interviewed'] ?></td><td><?= $c['offered'] ?></td><td><?= $c['hired'] ?></td><td><?= $fmtDays($median(array_column($set, 'days_to_invite'))) ?></td></tr>
        <?php endforeach ?>
        </tbody>
    </table>
</section>

<section class="panel rp-card" style="margin-top:12px">
    <h2>How these numbers are calculated</h2>
    <ul class="rp-defs">
        <li><strong>Applied:</strong> one candidate record (an application to a job). Source = where the application came from.</li>
        <li><strong>Shortlisted:</strong> on a job wishlist, moved past Applied, or invited to interview.</li>
        <li><strong>Invited:</strong> at least one interview invitation was sent.</li>
        <li><strong>Interviewed:</strong> booked a slot (not a no-show), has a recorded interview outcome, or reached Round 2 / Offer / Hired.</li>
        <li><strong>Offered:</strong> an offer letter was sent (not withdrawn) or the stage is Offer / Hired.</li>
        <li><strong>Hired:</strong> marked as hired. <strong>Time to hire</strong> = application date to the "Mark as hired" date.</li>
        <li><strong>Offer acceptance:</strong> accepted ÷ (accepted + declined) offer letters; pending and withdrawn letters are excluded.</li>
        <li><strong>Days to first invite:</strong> application date to the first interview invitation. Older imported applications can make this large.</li>
    </ul>
</section>
<?php require __DIR__ . '/views/partials/footer.php'; ?>
