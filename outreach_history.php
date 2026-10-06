<?php
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/workspace.php';
require_once __DIR__ . '/lib/outreach_campaign.php';

$pdo = db();
$user = currentUser();
$csrfToken = csrfToken();

// Handle Clone Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clone' && verifyCsrf($_POST['csrf'] ?? '')) {
    $cloneId = (int)($_POST['campaign_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM email_campaigns WHERE id = ?");
    $stmt->execute([$cloneId]);
    $src = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($src) {
        $jStmt = $pdo->prepare("SELECT job_id FROM email_campaign_jobs WHERE campaign_id = ?");
        $jStmt->execute([$cloneId]);
        $jobIds = $jStmt->fetchAll(PDO::FETCH_COLUMN);

        header('Location: outreach.php?clone_name=' . urlencode($src['name'] . ' (Copy)') . '&clone_subject=' . urlencode($src['subject']) . '&jobs=' . implode(',', $jobIds));
        exit;
    }
}

// Fetch all campaigns
$stmt = $pdo->query("
    SELECT c.*, u.name AS creator_name, COUNT(DISTINCT j.job_id) as job_count
    FROM email_campaigns c
    LEFT JOIN users u ON u.id = c.created_by
    LEFT JOIN email_campaign_jobs j ON j.campaign_id = c.id
    GROUP BY c.id
    ORDER BY c.id DESC
");
$campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

$activePage = 'outreach_history';
$pageTitle = 'Outreach Campaign History · NonceBlox ATS';

require_once __DIR__ . '/views/partials/header.php';
?>

<style>
.history-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
}
.history-header h1 {
  font-size: 22px;
  font-weight: 700;
  color: #0f172a;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 10px;
}

.card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 20px;
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
  padding: 12px 14px;
  border-bottom: 1px solid #e2e8f0;
}
.data-table td {
  padding: 14px;
  border-bottom: 1px solid #f1f5f9;
  color: #1e293b;
}

.status-badge {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
}
.status-badge.completed { background: #dcfce7; color: #15803d; }
.status-badge.completed_with_errors { background: #fef9c3; color: #a16207; }
.status-badge.sending { background: #e0e7ff; color: #4338ca; }
.status-badge.paused { background: #ffedd5; color: #c2410c; }
.status-badge.cancelled { background: #fee2e2; color: #b91c1c; }
.status-badge.draft { background: #f1f5f9; color: #475569; }

.btn-sm {
  padding: 4px 10px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  border: 1px solid #cbd5e1;
  background: #fff;
  color: #334155;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.btn-sm:hover { background: #f8fafc; }
.btn-purple-sm {
  background: #7c3aed;
  color: #fff;
  border: 0;
}
.btn-purple-sm:hover { background: #6d28d9; }
</style>

<div class="history-page">
  <div class="history-header">
    <h1><i class="fa-solid fa-clock-rotate-left" style="color: #7c3aed;"></i> Outreach Campaign History</h1>
    <div>
      <a href="outreach.php" class="btn-sm btn-purple-sm" style="padding: 8px 16px;"><i class="fa-solid fa-plus"></i> New Outreach Campaign</a>
    </div>
  </div>

  <div class="card">
    <div style="overflow-x: auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Campaign Name</th>
            <th>Created By</th>
            <th>Promoted Jobs</th>
            <th>Recipients</th>
            <th>Sent / Failed</th>
            <th>Status</th>
            <th>Created Date</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($campaigns)): ?>
            <tr>
              <td colspan="8" style="text-align: center; color: #64748b; padding: 24px;">
                No campaign history found. <a href="outreach.php" style="color: #7c3aed; font-weight: 600;">Create your first campaign &rarr;</a>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($campaigns as $c): ?>
              <tr>
                <td>
                  <strong><a href="outreach_report.php?id=<?=$c['id']?>" style="color: #0f172a; text-decoration: none;"><?=htmlspecialchars($c['name'])?></a></strong>
                  <div style="font-size: 11px; color: #64748b; margin-top: 2px;"><?=htmlspecialchars($c['subject'])?></div>
                </td>
                <td><?=htmlspecialchars($c['creator_name'] ?? 'Admin')?></td>
                <td><span style="font-weight: 600; color: #6d28d9;"><?=(int)$c['job_count']?> Jobs</span></td>
                <td><strong><?=(int)$c['total_recipients']?></strong></td>
                <td>
                  <span style="color: #16a34a; font-weight: 700;"><?=(int)$c['sent_count']?></span> / 
                  <span style="color: #dc2626; font-weight: 700;"><?=(int)$c['failed_count']?></span>
                </td>
                <td>
                  <span class="status-badge <?=htmlspecialchars($c['status'])?>"><?=htmlspecialchars(str_replace('_', ' ', $c['status']))?></span>
                </td>
                <td><?=date('d M Y, h:i A', strtotime($c['created_at']))?></td>
                <td>
                  <div style="display: flex; gap: 6px;">
                    <a href="outreach_report.php?id=<?=$c['id']?>" class="btn-sm" title="View Detailed Report"><i class="fa-solid fa-chart-pie"></i> Report</a>
                    <form method="post" action="outreach_history.php" style="display: inline;">
                      <input type="hidden" name="csrf" value="<?=$csrfToken?>">
                      <input type="hidden" name="action" value="clone">
                      <input type="hidden" name="campaign_id" value="<?=$c['id']?>">
                      <button type="submit" class="btn-sm" title="Clone into New Draft"><i class="fa-solid fa-copy"></i> Clone</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/views/partials/footer.php'; ?>
