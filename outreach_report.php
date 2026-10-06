<?php
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/workspace.php';
require_once __DIR__ . '/lib/outreach_campaign.php';

$pdo = db();
$user = currentUser();
$csrfToken = csrfToken();

$campaignId = (int)($_GET['id'] ?? 0);
if ($campaignId <= 0) {
    header('Location: outreach_history.php');
    exit;
}

// Refresh counts before displaying report
refreshCampaignCounts($pdo, $campaignId);

$stmt = $pdo->prepare("
    SELECT c.*, u.name AS creator_name
    FROM email_campaigns c
    LEFT JOIN users u ON u.id = c.created_by
    WHERE c.id = ?
");
$stmt->execute([$campaignId]);
$campaign = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$campaign) {
    http_response_code(404);
    exit('Campaign report not found.');
}

// Handle Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="campaign_' . $campaignId . '_recipients.csv"');
    
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Campaign ID', 'Campaign Name', 'Candidate ID', 'Candidate Name', 'Email', 'Status', 'Sent At', 'Attempts', 'Error Code', 'Error Message']);

    $rStmt = $pdo->prepare("SELECT candidate_id, candidate_name_snapshot, email, status, sent_at, attempt_count, error_code, error_message FROM email_campaign_recipients WHERE campaign_id = ? ORDER BY id ASC");
    $rStmt->execute([$campaignId]);
    while ($r = $rStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $campaign['id'],
            $campaign['name'],
            $r['candidate_id'],
            $r['candidate_name_snapshot'],
            $r['email'],
            $r['status'],
            $r['sent_at'] ?: '',
            $r['attempt_count'],
            $r['error_code'] ?: '',
            $r['error_message'] ?: ''
        ]);
    }
    fclose($out);
    exit;
}

// Fetch selected job snapshots
$jStmt = $pdo->prepare("SELECT job_title_snapshot FROM email_campaign_jobs WHERE campaign_id = ? ORDER BY id ASC");
$jStmt->execute([$campaignId]);
$jobSnapshots = $jStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch recipients
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$q = trim((string)($_GET['q'] ?? ''));

$sql = "SELECT * FROM email_campaign_recipients WHERE campaign_id = ?";
$params = [$campaignId];

if ($statusFilter !== '' && $statusFilter !== 'all') {
    $sql .= " AND status = ?";
    $params[] = $statusFilter;
}
if ($q !== '') {
    $sql .= " AND (candidate_name_snapshot LIKE ? OR email LIKE ?)";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
}
$sql .= " ORDER BY id ASC LIMIT 500";

$rStmt = $pdo->prepare($sql);
$rStmt->execute($params);
$recipients = $rStmt->fetchAll(PDO::FETCH_ASSOC);

$total = (int)$campaign['total_recipients'];
$sent = (int)$campaign['sent_count'];
$failed = (int)$campaign['failed_count'];
$skipped = (int)$campaign['skipped_count'];
$suppressed = (int)$campaign['suppressed_count'];

$processed = $sent + $failed + $skipped + $suppressed;
$successRate = ($processed > 0) ? round(($sent / $processed) * 100, 1) : 0;

$activePage = 'outreach_history';
$pageTitle = 'Campaign Report: ' . htmlspecialchars($campaign['name']) . ' · NonceBlox ATS';

require_once __DIR__ . '/views/partials/header.php';
?>

<style>
.report-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 24px;
}
.report-header h1 {
  font-size: 22px;
  font-weight: 700;
  color: #0f172a;
  margin: 0 0 6px 0;
}
.report-meta {
  font-size: 13px;
  color: #64748b;
}

.status-tag {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
}
.status-tag.completed { background: #dcfce7; color: #15803d; }
.status-tag.completed_with_errors { background: #fef9c3; color: #a16207; }
.status-tag.sending { background: #e0e7ff; color: #4338ca; }
.status-tag.paused { background: #ffedd5; color: #c2410c; }
.status-tag.cancelled { background: #fee2e2; color: #b91c1c; }

.metrics-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 14px;
  margin-bottom: 24px;
}
@media (max-width: 900px) {
  .metrics-grid { grid-template-columns: repeat(2, 1fr); }
}

.metric-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 16px;
  text-align: center;
}
.metric-card .num {
  font-size: 22px;
  font-weight: 800;
  color: #0f172a;
}
.metric-card .lbl {
  font-size: 11px;
  font-weight: 600;
  color: #64748b;
  text-transform: uppercase;
  margin-top: 4px;
}
.metric-card.success .num { color: #16a34a; }
.metric-card.failed .num { color: #dc2626; }
.metric-card.rate .num { color: #7c3aed; }

.card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 20px;
  margin-bottom: 20px;
}

.table-responsive {
  width: 100%;
  overflow-x: auto;
}
.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.data-table th {
  background: #f8fafc;
  color: #475569;
  font-weight: 600;
  text-align: left;
  padding: 10px 14px;
  border-bottom: 1px solid #e2e8f0;
}
.data-table td {
  padding: 12px 14px;
  border-bottom: 1px solid #f1f5f9;
  color: #1e293b;
}

.badge-status {
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
}
.badge-status.sent { background: #dcfce7; color: #166534; }
.badge-status.failed { background: #fee2e2; color: #991b1b; }
.badge-status.pending { background: #fef3c7; color: #92400e; }
.badge-status.processing { background: #e0e7ff; color: #3730a3; }
.badge-status.skipped { background: #f1f5f9; color: #475569; }
.badge-status.suppressed { background: #f3e8ff; color: #6b21a8; }
.badge-status.cancelled { background: #fee2e2; color: #991b1b; }

.btn-secondary {
  background: #f1f5f9;
  color: #334155;
  border: 1px solid #cbd5e1;
  padding: 8px 14px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.btn-secondary:hover { background: #e2e8f0; }

.btn-purple {
  background: #7c3aed;
  color: #fff;
  border: 0;
  padding: 8px 14px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.btn-purple:hover { background: #6d28d9; }
</style>

<div class="report-page">
  <div class="report-header">
    <div>
      <h1>
        <?=htmlspecialchars($campaign['name'])?> 
        <span class="status-tag <?=htmlspecialchars($campaign['status'])?>"><?=htmlspecialchars(str_replace('_', ' ', $campaign['status']))?></span>
      </h1>
      <div class="report-meta">
        Created by <strong><?=htmlspecialchars($campaign['creator_name'] ?? 'Admin')?></strong> on <?=date('d M Y · h:i A', strtotime($campaign['created_at']))?>
        <?php if ($campaign['completed_at']): ?>
          • Completed on <?=date('d M Y · h:i A', strtotime($campaign['completed_at']))?>
        <?php endif; ?>
      </div>
    </div>
    <div style="display: flex; gap: 10px;">
      <button class="btn-secondary" onclick="openSnapshotModal()"><i class="fa-solid fa-code"></i> View Email Snapshot</button>
      <a href="outreach_report.php?id=<?=$campaignId?>&export=csv" class="btn-secondary"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
      <?php if ($failed > 0): ?>
        <button class="btn-purple" onclick="retryFailedRecipients()"><i class="fa-solid fa-rotate-right"></i> Retry Failed (<?=$failed?>)</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="metrics-grid">
    <div class="metric-card"><div class="num"><?=$total?></div><div class="lbl">Total Recipients</div></div>
    <div class="metric-card success"><div class="num"><?=$sent?></div><div class="lbl">Sent</div></div>
    <div class="metric-card failed"><div class="num"><?=$failed?></div><div class="lbl">Failed</div></div>
    <div class="metric-card"><div class="num"><?=$suppressed?></div><div class="lbl">Suppressed</div></div>
    <div class="metric-card rate"><div class="num"><?=$successRate?>%</div><div class="lbl">Success Rate</div></div>
  </div>

  <div class="card">
    <div style="font-size: 14px; font-weight: 700; color: #1e293b; margin-bottom: 10px;">Campaign Details & Promoted Jobs</div>
    <div style="font-size: 13px; color: #475569; margin-bottom: 12px;">
      <strong>Subject:</strong> <?=htmlspecialchars($campaign['subject'])?><br>
      <strong>Career URL:</strong> <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?=htmlspecialchars($campaign['career_url'])?></code>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
      <?php foreach ($jobSnapshots as $title): ?>
        <span style="background: #ede9fe; color: #6d28d9; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 6px;">
          <i class="fa-solid fa-briefcase"></i> <?=htmlspecialchars($title)?>
        </span>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Recipient Table -->
  <div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
      <div style="font-size: 15px; font-weight: 700; color: #0f172a;">Recipient Delivery Log</div>
      
      <form method="get" action="outreach_report.php" style="display: flex; gap: 10px;">
        <input type="hidden" name="id" value="<?=$campaignId?>">
        <input type="text" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Search name or email..." style="padding: 6px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
        <select name="status" onchange="this.form.submit()" style="padding: 6px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
          <option value="all" <?=$statusFilter==='all'?'selected':''?>>All Statuses</option>
          <option value="sent" <?=$statusFilter==='sent'?'selected':''?>>Sent</option>
          <option value="failed" <?=$statusFilter==='failed'?'selected':''?>>Failed</option>
          <option value="pending" <?=$statusFilter==='pending'?'selected':''?>>Pending</option>
          <option value="suppressed" <?=$statusFilter==='suppressed'?'selected':''?>>Suppressed</option>
        </select>
      </form>
    </div>

    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Candidate</th>
            <th>Email</th>
            <th>Status</th>
            <th>Sent At</th>
            <th>Attempts</th>
            <th>Error Info</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recipients)): ?>
            <tr>
              <td colspan="6" style="text-align: center; color: #64748b; padding: 20px;">No recipient records matching your filter.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($recipients as $r): ?>
              <tr>
                <td><strong><?=htmlspecialchars($r['candidate_name_snapshot'])?></strong></td>
                <td><?=htmlspecialchars($r['email'])?></td>
                <td><span class="badge-status <?=htmlspecialchars($r['status'])?>"><?=htmlspecialchars($r['status'])?></span></td>
                <td><?=$r['sent_at'] ? date('d M H:i', strtotime($r['sent_at'])) : '-'?></td>
                <td><?=$r['attempt_count']?></td>
                <td>
                  <?php if ($r['error_message']): ?>
                    <span style="color: #dc2626; font-size: 11px;" title="<?=htmlspecialchars($r['error_message'])?>"><?=htmlspecialchars(mb_strimwidth($r['error_message'], 0, 45, '...'))?></span>
                  <?php else: ?>
                    <span style="color: #94a3b8;">-</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- EMAIL SNAPSHOT MODAL -->
<div id="snapshotModal" style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 999; display: none; align-items: center; justify-content: center;">
  <div style="background: #0f0c1b; border-radius: 12px; width: 90%; max-width: 650px; padding: 20px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); color: #fff;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; border-bottom: 1px solid #2e2842; padding-bottom: 10px;">
      <h3 style="margin: 0; font-size: 16px; color: #a78bfa;">Exact HTML Email Snapshot Sent</h3>
      <button onclick="closeSnapshotModal()" style="background: transparent; border: 0; color: #94a3b8; font-size: 18px; cursor: pointer;">&times;</button>
    </div>
    <iframe id="snapshotIframe" style="width: 100%; height: 500px; border: 0; border-radius: 6px; background: #0f0c1b;"></iframe>
  </div>
</div>

<script>
const CSRF_TOKEN = '<?=$csrfToken?>';

function openSnapshotModal() {
  const modal = document.getElementById('snapshotModal');
  const iframe = document.getElementById('snapshotIframe');
  modal.style.display = 'flex';
  const doc = iframe.contentWindow.document;
  doc.open();
  doc.write(<?=json_encode($campaign['html_snapshot'])?>);
  doc.close();
}

function closeSnapshotModal() {
  document.getElementById('snapshotModal').style.display = 'none';
}

function retryFailedRecipients() {
  if (!confirm('Are you sure you want to retry sending emails to all failed recipients in this campaign?')) return;

  fetch('outreach_action.php?action=retry_failed', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
    body: JSON.stringify({ csrf: CSRF_TOKEN, campaign_id: <?=$campaignId?> })
  })
  .then(res => res.json())
  .then(res => {
    if (res.success) {
      alert(`Reset ${res.reset_count} failed recipient(s) to retry. Outreach background worker resumed.`);
      window.location.reload();
    } else {
      alert(res.error || 'Failed to trigger retry.');
    }
  });
}
</script>

<?php require_once __DIR__ . '/views/partials/footer.php'; ?>
