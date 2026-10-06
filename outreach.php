<?php
require_once __DIR__.'/lib/auth.php';
requireAuth();
require_once __DIR__.'/lib/workspace.php';
require_once __DIR__.'/lib/branding.php';

$pdo = db();
$user = currentUser();
$brand = organizationBrand();

$jobFilter = (int)($_GET['job_id'] ?? 0);
$statusFilter = trim((string)($_GET['status'] ?? ''));
$searchQuery = trim((string)($_GET['q'] ?? ''));

// Fetch jobs for filter dropdown
$jobsStmt = $pdo->prepare('SELECT id, title FROM jobs ORDER BY title ASC');
$jobsStmt->execute();
$jobs = $jobsStmt->fetchAll();

// Fetch summary metrics
$metricsStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT eb.id) AS total_batches,
        COUNT(er.id) AS total_recipients,
        SUM(CASE WHEN er.status = 'sent' THEN 1 ELSE 0 END) AS total_sent,
        SUM(CASE WHEN er.status = 'failed' THEN 1 ELSE 0 END) AS total_failed,
        SUM(CASE WHEN er.status = 'pending' THEN 1 ELSE 0 END) AS total_pending
    FROM email_batches eb
    LEFT JOIN email_recipients er ON er.batch_id = eb.id
    WHERE eb.user_id = ?
");
$metricsStmt->execute([$user['id']]);
$metrics = $metricsStmt->fetch() ?: ['total_batches' => 0, 'total_recipients' => 0, 'total_sent' => 0, 'total_failed' => 0, 'total_pending' => 0];

$totalSent = (int)($metrics['total_sent'] ?? 0);
$totalRecipients = (int)($metrics['total_recipients'] ?? 0);
$successRate = $totalRecipients > 0 ? round(($totalSent / $totalRecipients) * 100, 1) : 100;

// Fetch Batches
$batchesQuery = "
    SELECT 
        eb.id, eb.created_at, eb.interview_at, eb.timezone, eb.subject, eb.status, eb.recipient_count,
        wp.job_id, j.title AS job_title,
        SUM(CASE WHEN er.status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
        SUM(CASE WHEN er.status = 'failed' THEN 1 ELSE 0 END) AS failed_count
    FROM email_batches eb
    LEFT JOIN wishlist_profiles wp ON wp.id = eb.profile_id
    LEFT JOIN jobs j ON j.id = wp.job_id
    LEFT JOIN email_recipients er ON er.batch_id = eb.id
    WHERE eb.user_id = ?
";
$params = [$user['id']];

if ($jobFilter > 0) {
    $batchesQuery .= " AND wp.job_id = ?";
    $params[] = $jobFilter;
}
if ($statusFilter !== '') {
    $batchesQuery .= " AND eb.status = ?";
    $params[] = $statusFilter;
}

$batchesQuery .= " GROUP BY eb.id ORDER BY eb.created_at DESC LIMIT 30";
$batchesStmt = $pdo->prepare($batchesQuery);
$batchesStmt->execute($params);
$batches = $batchesStmt->fetchAll();

// Fetch Recipients Activity Log
$recipientsQuery = "
    SELECT 
        er.id, er.batch_id, er.candidate_id, er.email, er.status, er.sent_at, er.error_text,
        eb.interview_at, eb.timezone, eb.created_at AS batch_created_at, eb.subject,
        j.id AS job_id, j.title AS job_title,
        c.name AS candidate_name, c.role_title, c.stage AS candidate_stage
    FROM email_recipients er
    JOIN email_batches eb ON eb.id = er.batch_id
    LEFT JOIN wishlist_profiles wp ON wp.id = eb.profile_id
    LEFT JOIN jobs j ON j.id = wp.job_id
    LEFT JOIN candidates c ON c.id = er.candidate_id
    WHERE eb.user_id = ?
";
$recParams = [$user['id']];

if ($jobFilter > 0) {
    $recipientsQuery .= " AND wp.job_id = ?";
    $recParams[] = $jobFilter;
}
if ($statusFilter !== '') {
    $recipientsQuery .= " AND er.status = ?";
    $recParams[] = $statusFilter;
}
if ($searchQuery !== '') {
    $recipientsQuery .= " AND (c.name LIKE ? OR er.email LIKE ? OR eb.subject LIKE ?)";
    $term = '%' . $searchQuery . '%';
    $recParams[] = $term;
    $recParams[] = $term;
    $recParams[] = $term;
}

$recipientsQuery .= " ORDER BY COALESCE(er.sent_at, eb.created_at) DESC LIMIT 100";
$recStmt = $pdo->prepare($recipientsQuery);
$recStmt->execute($recParams);
$recipientLogs = $recStmt->fetchAll();

// Audit Event
$pdo->prepare("INSERT INTO audit_events(user_id, action, entity_type, entity_id, metadata_json) VALUES(?, 'outreach.log_viewed', 'email_batch', '0', ?)")
    ->execute([$user['id'], json_encode(['job_filter' => $jobFilter, 'status_filter' => $statusFilter, 'search' => $searchQuery])]);

$activePage = 'outreach';
$pageTitle = 'Interview Outreach Activity Hub · ResumeIQ';

$pageStyles = <<<'CSS'
<style>
.outreach-head {
    padding: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 16px;
}
.outreach-head h1 {
    margin: 4px 0 6px;
    font-size: 22px;
    font-weight: 700;
}
.outreach-head .muted {
    color: var(--muted);
    font-size: 12px;
}
.metrics-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}
.metric-card {
    padding: 18px 20px;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.metric-card small {
    display: block;
    color: var(--muted);
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 8px;
}
.metric-card strong {
    font-size: 26px;
    font-weight: 800;
    color: var(--text-dark, #1c182d);
}
.metric-card .subtext {
    font-size: 10px;
    color: var(--muted);
    margin-top: 4px;
}
.outreach-filters {
    padding: 16px 20px;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 12px;
    margin-bottom: 20px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
}
.outreach-filters .field {
    display: flex;
    flex-direction: column;
    gap: 5px;
    flex: 1;
    min-width: 180px;
}
.outreach-filters label {
    font-size: 10px;
    font-weight: 700;
    color: var(--muted);
    text-transform: uppercase;
}
.outreach-filters .control {
    height: 38px;
}
.section-title-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 24px 0 12px;
}
.section-title-bar h2 {
    font-size: 16px;
    font-weight: 700;
    margin: 0;
}
.table-panel {
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 24px;
}
.outreach-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}
.outreach-table th {
    background: #f8f8fc;
    color: var(--muted);
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid var(--line);
}
.outreach-table td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--line);
    vertical-align: middle;
}
.outreach-table tr:last-child td {
    border-bottom: 0;
}
.outreach-table tr:hover td {
    background: #fcfbff;
}
.cand-link {
    color: var(--primary, #6f45ff);
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.cand-link:hover {
    text-decoration: underline;
}
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 9px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 700;
}
.status-pill.sent {
    background: var(--green-soft, #e6f7f0);
    color: var(--green, #087a55);
}
.status-pill.partial {
    background: var(--amber-soft, #fff5e7);
    color: var(--amber, #8c610d);
}
.status-pill.failed {
    background: #fff1f3;
    color: var(--danger, #c43b51);
}
.status-pill.pending {
    background: #f0ebff;
    color: var(--primary, #6f45ff);
}
.empty-box {
    padding: 36px 20px;
    text-align: center;
    color: var(--muted);
    font-size: 12px;
}
@media (max-width: 900px) {
    .metrics-grid {
        grid-template-columns: 1fr 1fr;
    }
}
@media (max-width: 600px) {
    .metrics-grid {
        grid-template-columns: 1fr;
    }
    .outreach-head {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>
CSS;

require __DIR__ . '/views/partials/header.php';
?>

<section class="panel outreach-head">
    <div>
        <div class="kicker"><i class="fa-solid fa-paper-plane"></i> Recruiter Activity</div>
        <h1>Interview Outreach Hub</h1>
        <p class="muted">Monitor candidate email delivery logs, invitation batch progress, and direct recipient activity.</p>
    </div>
    <div style="display:flex;gap:8px">
        <a class="btn btn-primary" href="interview_invite.php<?=$jobFilter ? '?job_id='.$jobFilter : ''?>">
            <i class="fa-solid fa-calendar-plus"></i> Create New Invitations
        </a>
    </div>
</section>

<section class="metrics-grid">
    <article class="metric-card">
        <small>Total Batches</small>
        <strong><?=(int)$metrics['total_batches']?></strong>
        <span class="subtext">Outreach sessions initiated</span>
    </article>
    <article class="metric-card">
        <small>Emails Delivered</small>
        <strong style="color:var(--green, #087a55)"><?=$totalSent?></strong>
        <span class="subtext"><?=count($recipientLogs)?> recent logs loaded</span>
    </article>
    <article class="metric-card">
        <small>Failed Attempts</small>
        <strong style="color:<?=(int)$metrics['total_failed'] > 0 ? 'var(--danger, #c43b51)' : 'inherit'?>"><?=(int)$metrics['total_failed']?></strong>
        <span class="subtext">Requires SMTP/API check</span>
    </article>
    <article class="metric-card">
        <small>Delivery Success Rate</small>
        <strong><?=$successRate?>%</strong>
        <span class="subtext">Overall delivery percentage</span>
    </article>
</section>

<form class="outreach-filters" method="get">
    <div class="field">
        <label for="job_id">Filter by Job</label>
        <select class="control" name="job_id" id="job_id" onchange="this.form.submit()">
            <option value="">All Jobs</option>
            <?php foreach ($jobs as $j): ?>
                <option value="<?=$j['id']?>" <?=$jobFilter === (int)$j['id'] ? 'selected' : ''?>><?=htmlspecialchars($j['title'])?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="field">
        <label for="status">Delivery Status</label>
        <select class="control" name="status" id="status" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <option value="sent" <?=$statusFilter === 'sent' ? 'selected' : ''?>>Sent / Successful</option>
            <option value="partial" <?=$statusFilter === 'partial' ? 'selected' : ''?>>Partial Delivery</option>
            <option value="failed" <?=$statusFilter === 'failed' ? 'selected' : ''?>>Failed</option>
        </select>
    </div>
    <div class="field" style="flex:2">
        <label for="q">Search Recipient / Subject</label>
        <input class="control" name="q" id="q" value="<?=htmlspecialchars($searchQuery)?>" placeholder="Search by candidate name, email, or subject...">
    </div>
    <button class="btn btn-primary" type="submit" style="height:38px"><i class="fa-solid fa-filter"></i> Apply Filters</button>
    <?php if ($jobFilter || $statusFilter !== '' || $searchQuery !== ''): ?>
        <a class="btn" href="outreach.php" style="height:38px"><i class="fa-solid fa-xmark"></i> Clear</a>
    <?php endif ?>
</form>

<div class="section-title-bar">
    <h2><i class="fa-solid fa-list-check"></i> Candidate Recipient Activity</h2>
    <span class="tag">Click candidate name for full profile details</span>
</div>

<div class="table-panel">
    <table class="outreach-table">
        <thead>
            <tr>
                <th>Candidate Details</th>
                <th>Applied Role</th>
                <th>Email Address</th>
                <th>Interview Schedule</th>
                <th>Delivery Status</th>
                <th>Sent Timestamp</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recipientLogs as $log): ?>
                <tr>
                    <td>
                        <?php if ($log['candidate_id']): ?>
                            <a class="cand-link" href="candidate_detail.php?id=<?=urlencode($log['candidate_id'])?>" target="_blank" title="Open full candidate details in new tab">
                                <strong><?=htmlspecialchars($log['candidate_name'] ?: $log['email'])?></strong>
                                <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:10px"></i>
                            </a>
                        <?php else: ?>
                            <strong><?=htmlspecialchars($log['email'])?></strong>
                        <?php endif ?>
                        <small style="display:block;color:var(--muted)"><?=htmlspecialchars($log['role_title'] ?: 'Role unassigned')?></small>
                    </td>
                    <td><?=htmlspecialchars($log['job_title'] ?: 'General Recruitment')?></td>
                    <td><code><?=htmlspecialchars($log['email'])?></code></td>
                    <td>
                        <?php if ($log['interview_at']): ?>
                            <strong><?=htmlspecialchars(date('d M Y, g:i A', strtotime($log['interview_at'])))?></strong>
                            <small style="display:block;color:var(--muted)"><?=htmlspecialchars($log['timezone'] ?: 'UTC')?></small>
                        <?php else: ?>
                            <span class="muted">Unscheduled</span>
                        <?php endif ?>
                    </td>
                    <td>
                        <span class="status-pill <?=htmlspecialchars($log['status'])?>">
                            <?php if ($log['status'] === 'sent'): ?>
                                <i class="fa-solid fa-circle-check"></i> Sent
                            <?php elseif ($log['status'] === 'failed'): ?>
                                <i class="fa-solid fa-circle-xmark"></i> Failed
                            <?php else: ?>
                                <i class="fa-solid fa-clock"></i> Pending
                            <?php endif ?>
                        </span>
                        <?php if ($log['error_text']): ?>
                            <small style="display:block;color:var(--danger);margin-top:2px"><?=htmlspecialchars($log['error_text'])?></small>
                        <?php endif ?>
                    </td>
                    <td>
                        <?=$log['sent_at'] ? htmlspecialchars(date('d M Y, g:i:s A', strtotime($log['sent_at']))) : '<span class="muted">Not sent</span>'?>
                    </td>
                    <td>
                        <?php if ($log['candidate_id']): ?>
                            <a class="btn" href="candidate_detail.php?id=<?=urlencode($log['candidate_id'])?>" target="_blank">
                                <i class="fa-solid fa-user-gear"></i> Full Details
                            </a>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            <?php if (!$recipientLogs): ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-box">
                            <i class="fa-solid fa-inbox fa-2x" style="margin-bottom:8px"></i>
                            <p>No recipient outreach records match the active filters.</p>
                        </div>
                    </td>
                </tr>
            <?php endif ?>
        </tbody>
    </table>
</div>

<div class="section-title-bar">
    <h2><i class="fa-solid fa-layer-group"></i> Invitation Batches History</h2>
</div>

<div class="table-panel">
    <table class="outreach-table">
        <thead>
            <tr>
                <th>Batch #</th>
                <th>Job Title & Subject</th>
                <th>Created At</th>
                <th>Interview Date/Time</th>
                <th>Recipients Count</th>
                <th>Batch Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($batches as $batch): ?>
                <tr>
                    <td><strong>#<?=(int)$batch['id']?></strong></td>
                    <td>
                        <strong><?=htmlspecialchars($batch['job_title'] ?: 'Interview Batch')?></strong>
                        <small style="display:block;color:var(--muted)"><?=htmlspecialchars($batch['subject'] ?: 'Interview invitation')?></small>
                    </td>
                    <td><?=htmlspecialchars(date('d M Y, g:i A', strtotime($batch['created_at'])))?></td>
                    <td>
                        <?=$batch['interview_at'] ? htmlspecialchars(date('d M Y, g:i A', strtotime($batch['interview_at']))) : '<span class="muted">N/A</span>'?>
                    </td>
                    <td>
                        <strong><?=(int)$batch['sent_count']?> / <?=(int)$batch['recipient_count']?> delivered</strong>
                        <?php if ((int)$batch['failed_count'] > 0): ?>
                            <small style="display:block;color:var(--danger)"><?=(int)$batch['failed_count']?> failed</small>
                        <?php endif ?>
                    </td>
                    <td>
                        <span class="status-pill <?=htmlspecialchars($batch['status'])?>">
                            <?=htmlspecialchars(ucfirst($batch['status']))?>
                        </span>
                    </td>
                    <td>
                        <a class="btn" href="interview_batch.php?id=<?=(int)$batch['id']?>">
                            <i class="fa-solid fa-eye"></i> View Batch
                        </a>
                    </td>
                </tr>
            <?php endforeach ?>
            <?php if (!$batches): ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-box">
                            <p>No interview invitation batches recorded yet.</p>
                        </div>
                    </td>
                </tr>
            <?php endif ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
