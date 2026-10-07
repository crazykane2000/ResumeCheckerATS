<?php
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/branding.php';
require_once __DIR__ . '/lib/integrations.php';
require_once __DIR__ . '/lib/email_api_mailer.php';
require_once __DIR__ . '/lib/interview_email_template.php';

$pdo = db();
$user = currentUser();
$csrfToken = csrfToken();
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? '')) {
    try {
        $action = $_POST['action'] ?? '';
        
        // 1. RESET WHOLE BATCH
        if ($action === 'reset_batch') {
            $batchId = (int)($_POST['batch_id'] ?? 0);
            $batchStmt = $pdo->prepare("SELECT eb.*, wp.job_id FROM email_batches eb LEFT JOIN wishlist_profiles wp ON wp.id=eb.profile_id WHERE eb.id=? AND eb.user_id=? LIMIT 1");
            $batchStmt->execute([$batchId, $user['id']]);
            $b = $batchStmt->fetch();
            
            if (!$b) {
                throw new RuntimeException('Batch not found or permission denied.');
            }

            $recipientsStmt = $pdo->prepare("SELECT candidate_id FROM email_recipients WHERE batch_id=?");
            $recipientsStmt->execute([$batchId]);
            $candidateIds = $recipientsStmt->fetchAll(PDO::FETCH_COLUMN);

            if ($candidateIds) {
                $pdo->beginTransaction();
                $unlock = $pdo->prepare("UPDATE interview_invite_locks SET active=0, reset_at=NOW(), reset_by=? WHERE profile_id=? AND candidate_id=? AND active=1");
                $stage = $pdo->prepare("UPDATE candidates SET stage='Applied' WHERE id=? AND job_id=? AND stage='Interview'");
                $cancel = $pdo->prepare("UPDATE interview_invitations ii LEFT JOIN interview_slots s ON s.id=ii.confirmed_slot_id SET s.status=IF(s.id IS NULL,s.status,'available'), s.invitation_id=NULL, s.reserved_at=NULL, ii.status='cancelled', ii.confirmed_slot_id=NULL WHERE ii.email_recipient_id=(SELECT id FROM email_recipients WHERE batch_id=? AND candidate_id=? LIMIT 1)");
                
                foreach ($candidateIds as $cId) {
                    $unlock->execute([$user['id'], $b['profile_id'], $cId]);
                    $stage->execute([$cId, $b['job_id']]);
                    $cancel->execute([$batchId, $cId]);
                }

                $pdo->prepare("UPDATE email_batches SET status='reset' WHERE id=?")->execute([$batchId]);
                $pdo->prepare("INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,'interview.batch_reset','email_batch',?,?)")->execute([$user['id'], (string)$batchId, json_encode(['count' => count($candidateIds), 'outcome' => 'success'])]);
                $pdo->commit();
            }

            header('Location: email_batch_history.php?msg=' . urlencode('Batch #' . $batchId . ' reset cleanly. Candidates unlocked and available for new invitations.'));
            exit;
        }

        // 2. RESET SINGLE CANDIDATE LOCK
        if ($action === 'reset_candidate') {
            $batchId = (int)($_POST['batch_id'] ?? 0);
            $cId = trim((string)($_POST['candidate_id'] ?? ''));
            
            $bStmt = $pdo->prepare("SELECT eb.*, wp.job_id FROM email_batches eb LEFT JOIN wishlist_profiles wp ON wp.id=eb.profile_id WHERE eb.id=? AND eb.user_id=? LIMIT 1");
            $bStmt->execute([$batchId, $user['id']]);
            $b = $bStmt->fetch();

            if ($b && $cId) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE interview_invite_locks SET active=0, reset_at=NOW(), reset_by=? WHERE profile_id=? AND candidate_id=? AND active=1")->execute([$user['id'], $b['profile_id'], $cId]);
                $pdo->prepare("UPDATE candidates SET stage='Applied' WHERE id=? AND job_id=? AND stage='Interview'")->execute([$cId, $b['job_id']]);
                $pdo->prepare("UPDATE interview_invitations ii LEFT JOIN interview_slots s ON s.id=ii.confirmed_slot_id SET s.status=IF(s.id IS NULL,s.status,'available'), s.invitation_id=NULL, s.reserved_at=NULL, ii.status='cancelled', ii.confirmed_slot_id=NULL WHERE ii.email_recipient_id=(SELECT id FROM email_recipients WHERE batch_id=? AND candidate_id=? LIMIT 1)")->execute([$batchId, $cId]);
                $pdo->prepare("INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,'interview.candidate_reset','candidate',?,?)")->execute([$user['id'], $cId, json_encode(['batch_id' => $batchId, 'outcome' => 'success'])]);
                $pdo->commit();
            }

            header('Location: email_batch_history.php?msg=' . urlencode('Candidate lock reset cleanly.'));
            exit;
        }

        // 3. RESET ALL LOCKS GLOBALLY
        if ($action === 'reset_all_locks') {
            $count = (int)$pdo->query('SELECT COUNT(*) FROM interview_invite_locks WHERE active=1')->fetchColumn();
            $pdo->prepare('UPDATE interview_invite_locks SET active=0, reset_at=NOW(), reset_by=? WHERE active=1')->execute([$user['id']]);
            $pdo->prepare("INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,'interview.all_locks_reset','workspace','1',?)")->execute([$user['id'], json_encode(['reset_count' => $count, 'outcome' => 'success'])]);
            header('Location: email_batch_history.php?msg=' . urlencode($count . ' active candidate locks reset cleanly across workspace.'));
            exit;
        }

        // 4. RESEND FAILED INVITATION
        if ($action === 'resend_failed') {
            $batchId = (int)($_POST['batch_id'] ?? 0);
            $cId = trim((string)($_POST['candidate_id'] ?? ''));

            $recipientStmt = $pdo->prepare("SELECT er.*, c.name, eb.subject, eb.interview_at, eb.timezone, eb.profile_id, wp.job_id, j.title AS job_title FROM email_recipients er JOIN email_batches eb ON eb.id=er.batch_id LEFT JOIN candidates c ON c.id=er.candidate_id LEFT JOIN wishlist_profiles wp ON wp.id=eb.profile_id LEFT JOIN jobs j ON j.id=wp.job_id WHERE er.batch_id=? AND er.candidate_id=? LIMIT 1");
            $recipientStmt->execute([$batchId, $cId]);
            $recipient = $recipientStmt->fetch();

            if (!$recipient) {
                throw new RuntimeException('Recipient record not found.');
            }

            $emailApi = integrationConfig('email_api');
            if (($emailApi['status'] ?? '') !== 'configured') {
                $emailApi = ['status' => 'configured', 'url' => 'https://hrms-api.nonceblox.com/api/emails/send-multi-recipient'];
            }

            $date = $recipient['interview_at'] ? date('Y-m-d', strtotime($recipient['interview_at'])) : 'Schedule pending';
            $time = $recipient['interview_at'] ? date('H:i', strtotime($recipient['interview_at'])) : 'Schedule pending';
            $html = noncebloxInterviewEmailHtml($recipient['name'] ?? 'Candidate', $recipient['job_title'] ?: 'Interview', $date, $time, $recipient['timezone'] ?: 'Asia/Kolkata');

            $testing = integrationConfig('email_testing');
            $testEnabled = !array_key_exists('enabled', $testing) || !empty($testing['enabled']);
            $testEmail = trim((string)($testing['email'] ?? 'kishan.sharma@nonceblox.com'));
            $targets = [$recipient['email']];
            if ($testEnabled && filter_var($testEmail, FILTER_VALIDATE_EMAIL) && strcasecmp($testEmail, $recipient['email']) !== 0) {
                array_unshift($targets, $testEmail);
            }

            foreach ($targets as $target) {
                emailApiSendHtml($emailApi, [$target], $target === $recipient['email'] ? $recipient['subject'] : '[TEST COPY] ' . $recipient['subject'], $html);
            }

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE email_recipients SET status='sent', sent_at=NOW(), error_text=NULL WHERE id=?")->execute([$recipient['id']]);
            $pdo->prepare("UPDATE candidates SET stage='Interview' WHERE id=? AND job_id=?")->execute([$cId, $recipient['job_id']]);
            $pdo->prepare("INSERT INTO interview_invite_locks(profile_id,candidate_id,batch_id,active,locked_at,reset_at,reset_by) VALUES(?,?,?,1,NOW(),NULL,NULL) ON DUPLICATE KEY UPDATE batch_id=VALUES(batch_id),active=1,locked_at=NOW(),reset_at=NULL,reset_by=NULL")->execute([$recipient['profile_id'], $cId, $batchId]);
            $pdo->prepare("UPDATE email_batches SET status='sent' WHERE id=?")->execute([$batchId]);
            $pdo->commit();

            header('Location: email_batch_history.php?msg=' . urlencode('Invitation resent successfully to ' . $recipient['email']));
            exit;
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $err = $e->getMessage();
    }
}

// Fetch Global Statistics
$stats = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM email_batches WHERE user_id = {$user['id']}) AS total_batches,
        (SELECT COUNT(*) FROM email_recipients er JOIN email_batches eb ON eb.id=er.batch_id WHERE eb.user_id = {$user['id']}) AS total_recipients,
        (SELECT COUNT(*) FROM email_recipients er JOIN email_batches eb ON eb.id=er.batch_id WHERE eb.user_id = {$user['id']} AND er.status='sent') AS total_sent,
        (SELECT COUNT(*) FROM email_recipients er JOIN email_batches eb ON eb.id=er.batch_id WHERE eb.user_id = {$user['id']} AND er.status='failed') AS total_failed,
        (SELECT COUNT(*) FROM interview_invite_locks WHERE active=1) AS active_locks
")->fetch();

// Fetch Batches
$batchesQuery = "
    SELECT 
        eb.id AS batch_id,
        eb.subject,
        eb.interview_at,
        eb.timezone,
        eb.status AS batch_status,
        eb.created_at,
        u.name AS recruiter_name,
        wp.job_id,
        j.title AS job_title,
        COUNT(er.id) AS recipient_count,
        SUM(CASE WHEN er.status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
        SUM(CASE WHEN er.status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
        SUM(CASE WHEN er.status = 'pending' THEN 1 ELSE 0 END) AS pending_count
    FROM email_batches eb
    LEFT JOIN users u ON u.id = eb.user_id
    LEFT JOIN wishlist_profiles wp ON wp.id = eb.profile_id
    LEFT JOIN jobs j ON j.id = wp.job_id
    LEFT JOIN email_recipients er ON er.batch_id = eb.id
    WHERE eb.user_id = ?
    GROUP BY eb.id
    ORDER BY eb.id DESC
";
$bStmt = $pdo->prepare($batchesQuery);
$bStmt->execute([$user['id']]);
$batches = $bStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Recipients for Detail View
$recipientsQuery = "
    SELECT 
        er.id AS recipient_id,
        er.batch_id,
        er.candidate_id,
        er.email,
        er.status AS recipient_status,
        er.sent_at,
        er.error_text,
        c.name AS candidate_name,
        c.role_title,
        c.stage AS candidate_stage,
        eb.subject,
        eb.created_at AS batch_created_at,
        j.title AS job_title,
        l.active AS is_locked
    FROM email_recipients er
    JOIN email_batches eb ON eb.id = er.batch_id
    LEFT JOIN candidates c ON c.id = er.candidate_id
    LEFT JOIN wishlist_profiles wp ON wp.id = eb.profile_id
    LEFT JOIN jobs j ON j.id = wp.job_id
    LEFT JOIN interview_invite_locks l ON l.profile_id = eb.profile_id AND l.candidate_id = er.candidate_id AND l.active = 1
    WHERE eb.user_id = ?
    ORDER BY er.id DESC
";
$rStmt = $pdo->prepare($recipientsQuery);
$rStmt->execute([$user['id']]);
$allRecipients = $rStmt->fetchAll(PDO::FETCH_ASSOC);

$viewMode = $_GET['view'] ?? 'batch';

$activePage = 'email_history';
$pageTitle = 'Email Batch History & Resets · NonceBlox ATS';

require_once __DIR__ . '/views/partials/header.php';
?>

<style>
.history-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
  background: #ffffff;
  padding: 22px 26px;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 1px 3px rgba(15,23,42,0.04);
}
.history-head h1 {
  font-size: 22px;
  font-weight: 800;
  color: #0f172a;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 12px;
}
.history-head-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  background: #f3e8ff;
  color: #7c3aed;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
}

.summary-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 16px;
  margin-bottom: 24px;
}
@media (max-width: 900px) {
  .summary-grid { grid-template-columns: repeat(2, 1fr); }
}
.metric-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 18px;
  box-shadow: 0 1px 3px rgba(15,23,42,0.04);
}
.metric-card small {
  display: block;
  color: #64748b;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 6px;
}
.metric-card strong {
  font-size: 24px;
  font-weight: 800;
  color: #0f172a;
}

.view-nav {
  display: flex;
  gap: 10px;
  margin-bottom: 24px;
}
.tab-btn {
  padding: 10px 20px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  color: #475569;
  text-decoration: none;
  background: #ffffff;
  border: 1px solid #cbd5e1;
  transition: all 0.15s ease;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.03);
}
.tab-btn:hover {
  background: #f8fafc;
  color: #0f172a;
  border-color: #94a3b8;
}
.tab-btn.active {
  background: #7c3aed;
  color: #ffffff;
  border-color: #7c3aed;
  box-shadow: 0 2px 6px rgba(124, 58, 237, 0.25);
}

/* STANDALONE BATCH CARD CONTAINER */
.batch-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 24px;
  margin-bottom: 24px;
  box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.batch-card:hover {
  border-color: #cbd5e1;
  box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
}

.batch-top-banner {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  padding-bottom: 18px;
  border-bottom: 1px solid #f1f5f9;
  margin-bottom: 18px;
}
.batch-id-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: 6px;
  background: #f3e8ff;
  color: #7c3aed;
  font-size: 12px;
  font-weight: 700;
  margin-bottom: 6px;
}
.batch-title {
  font-size: 18px;
  font-weight: 800;
  color: #0f172a;
  margin: 0 0 6px;
}
.batch-meta-row {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
  font-size: 12px;
  color: #64748b;
}
.batch-meta-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.batch-recipients-box {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 16px;
  margin-top: 16px;
}
.batch-recipients-title {
  font-size: 12px;
  font-weight: 700;
  color: #475569;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.recipient-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;
}
.recipient-item-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 14px;
}
.recipient-item-card:hover {
  border-color: #cbd5e1;
}

.candidate-avatar-chip {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: #f1f5f9;
  color: #475569;
  font-weight: 700;
  font-size: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.status-tag {
  padding: 3px 9px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
}
.status-tag.sent { background: #dcfce7; color: #15803d; }
.status-tag.failed { background: #fee2e2; color: #b91c1c; }
.status-tag.pending { background: #fef3c7; color: #b45309; }
.status-tag.reset { background: #f1f5f9; color: #475569; }

.btn-purple {
  background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
  color: #ffffff;
  border: 0;
  padding: 9px 18px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  text-decoration: none;
  transition: all 0.15s ease;
  box-shadow: 0 2px 4px rgba(124, 58, 237, 0.2);
}
.btn-purple:hover {
  background: linear-gradient(135deg, #6d28d9 0%, #5b21b6 100%);
  transform: translateY(-1px);
}

.btn-danger {
  background: #ffffff;
  color: #dc2626;
  border: 1px solid #fca5a5;
  padding: 9px 16px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: all 0.15s ease;
}
.btn-danger:hover {
  background: #fef2f2;
  border-color: #f87171;
}

.btn-secondary {
  background: #ffffff;
  color: #334155;
  border: 1px solid #cbd5e1;
  padding: 9px 16px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: all 0.15s ease;
}
.btn-secondary:hover {
  background: #f8fafc;
  color: #0f172a;
}

.btn-sm {
  padding: 5px 12px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
}

.alert-ok { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; padding: 14px 18px; border-radius: 10px; margin-bottom: 24px; font-weight: 600; display: flex; align-items: center; gap: 10px; font-size: 13px; }
.alert-err { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 14px 18px; border-radius: 10px; margin-bottom: 24px; font-weight: 600; display: flex; align-items: center; gap: 10px; font-size: 13px; }
</style>

<div class="history-container">
  <div class="history-head">
    <div>
      <h1><span class="history-head-icon"><i class="fa-solid fa-envelope-open-text"></i></span> Email Dispatch & Batch Reset Manager</h1>
      <p style="color: #64748b; font-size: 13px; margin-top: 4px;">View all candidate email dispatches, check recipient statuses, and perform batch-wise lock resets.</p>
    </div>
    <div>
      <form method="post" style="margin: 0;" onsubmit="return confirm('Reset all active candidate locks across the entire workspace? Unlocked candidates will become available again.')">
        <input type="hidden" name="csrf" value="<?=$csrfToken?>">
        <input type="hidden" name="action" value="reset_all_locks">
        <button type="submit" class="btn-danger"><i class="fa-solid fa-unlock-keyhole"></i> Reset All Workspace Candidate Locks</button>
      </form>
    </div>
  </div>

  <?php if ($msg): ?>
    <div class="alert-ok"><i class="fa-solid fa-circle-check"></i> <?=htmlspecialchars($msg)?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="alert-err"><i class="fa-solid fa-circle-exclamation"></i> <?=htmlspecialchars($err)?></div>
  <?php endif; ?>

  <!-- Summary Metric Grid -->
  <div class="summary-grid">
    <div class="metric-card">
      <small>Total Batches</small>
      <strong><?=(int)$stats['total_batches']?></strong>
    </div>
    <div class="metric-card">
      <small>Total Recipients</small>
      <strong><?=(int)$stats['total_recipients']?></strong>
    </div>
    <div class="metric-card">
      <small>Sent Successfully</small>
      <strong style="color: #16a34a;"><?=(int)$stats['total_sent']?></strong>
    </div>
    <div class="metric-card">
      <small>Failed Dispatches</small>
      <strong style="color: #dc2626;"><?=(int)$stats['total_failed']?></strong>
    </div>
    <div class="metric-card">
      <small>Locked Candidates</small>
      <strong style="color: #d97706;"><?=(int)$stats['active_locks']?></strong>
    </div>
  </div>

  <!-- View Mode Switcher -->
  <div class="view-nav">
    <a href="email_batch_history.php?view=batch" class="tab-btn <?=$viewMode === 'batch' ? 'active' : ''?>"><i class="fa-solid fa-boxes-stacked"></i> Batch-Wise Separate View</a>
    <a href="email_batch_history.php?view=recipients" class="tab-btn <?=$viewMode === 'recipients' ? 'active' : ''?>"><i class="fa-solid fa-users"></i> All Recipients Table View</a>
  </div>

  <?php if ($viewMode === 'batch'): ?>
    <!-- BATCH WISE INDIVIDUAL CARDS -->
    <?php if (empty($batches)): ?>
      <div class="batch-card" style="text-align: center; color: #64748b; padding: 40px;">
        <i class="fa-regular fa-envelope-open" style="font-size: 36px; color: #cbd5e1; margin-bottom: 12px; display: block;"></i>
        No email outreach batches found. <a href="interview_calendar.php" style="color: #7c3aed; font-weight: 600;">Schedule an interview dispatch &rarr;</a>
      </div>
    <?php else: ?>
      <?php foreach ($batches as $b): ?>
        <div class="batch-card">
          <!-- BATCH HEADER BANNER -->
          <div class="batch-top-banner">
            <div>
              <span class="batch-id-badge"><i class="fa-solid fa-hashtag"></i> Batch #<?=(int)$b['batch_id']?></span>
              <h2 class="batch-title"><?=htmlspecialchars($b['subject'])?></h2>
              <div class="batch-meta-row">
                <span class="batch-meta-item"><i class="fa-solid fa-briefcase" style="color: #7c3aed;"></i> Job: <strong><?=htmlspecialchars($b['job_title'] ?: 'Interview Invitation')?></strong></span>
                <span class="batch-meta-item"><i class="fa-regular fa-calendar"></i> Interview Date: <strong><?=htmlspecialchars($b['interview_at'] ? date('d M Y, h:i A', strtotime($b['interview_at'])) : 'Pending')?></strong> (<?=htmlspecialchars($b['timezone'] ?: 'Asia/Kolkata')?>)</span>
                <span class="batch-meta-item"><i class="fa-regular fa-clock"></i> Sent: <?=date('d M Y, h:i A', strtotime($b['created_at']))?></span>
              </div>
            </div>

            <!-- BATCH TOP ACTIONS -->
            <div class="batch-actions">
              <span class="status-tag <?=htmlspecialchars($b['batch_status'])?>"><?=htmlspecialchars(ucfirst($b['batch_status']))?></span>
              
              <!-- BATCH RESET BUTTON -->
              <form method="post" style="margin: 0;" onsubmit="return confirm('Reset Batch #<?=(int)$b['batch_id']?>? This will unlock all candidates in this batch so they can be re-invited.')">
                <input type="hidden" name="csrf" value="<?=$csrfToken?>">
                <input type="hidden" name="action" value="reset_batch">
                <input type="hidden" name="batch_id" value="<?=(int)$b['batch_id']?>">
                <button type="submit" class="btn-danger"><i class="fa-solid fa-rotate-left"></i> Reset Batch Locks</button>
              </form>

              <a href="interview_batch.php?id=<?=(int)$b['batch_id']?>" class="btn-secondary"><i class="fa-solid fa-up-right-from-square"></i> Open Batch</a>
            </div>
          </div>

          <!-- BATCH RECIPIENT CARDS CONTAINER -->
          <?php
          $batchRecipients = array_filter($allRecipients, fn($r) => (int)$r['batch_id'] === (int)$b['batch_id']);
          ?>
          <div class="batch-recipients-box">
            <div class="batch-recipients-title">
              <span><i class="fa-solid fa-users" style="color: #7c3aed;"></i> Batch Candidate Recipients (<?=count($batchRecipients)?> Candidates)</span>
              <span style="font-size: 11px; text-transform: none; color: #64748b;">
                <span style="color: #16a34a; font-weight: 700;"><?=(int)$b['sent_count']?> Sent</span> · 
                <span style="color: #dc2626; font-weight: 700;"><?=(int)$b['failed_count']?> Failed</span>
              </span>
            </div>

            <?php if (empty($batchRecipients)): ?>
              <div style="font-size: 12px; color: #64748b; padding: 10px;">No recipient records associated with this batch.</div>
            <?php else: ?>
              <div class="recipient-grid">
                <?php foreach ($batchRecipients as $r): ?>
                  <div class="recipient-item-card">
                    <div style="display: flex; align-items: center; gap: 12px;">
                      <div class="candidate-avatar-chip">
                        <?=htmlspecialchars(strtoupper(substr($r['candidate_name'] ?: 'C', 0, 2)))?>
                      </div>
                      <div>
                        <div style="font-weight: 700; color: #0f172a; font-size: 13px;">
                          <?php if ($r['candidate_id']): ?>
                            <a href="candidate_detail.php?id=<?=urlencode($r['candidate_id'])?>" target="_blank" style="color: #0f172a; text-decoration: none;">
                              <?=htmlspecialchars($r['candidate_name'] ?: $r['candidate_id'])?> <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 10px; color: #7c3aed;"></i>
                            </a>
                          <?php else: ?>
                            <?=htmlspecialchars($r['candidate_name'] ?: 'Unknown Candidate')?>
                          <?php endif; ?>
                        </div>
                        <div style="font-size: 12px; color: #64748b;">
                          <?=htmlspecialchars($r['email'])?> · <span style="color: #475569; font-weight: 500;"><?=htmlspecialchars($r['role_title'] ?: 'Candidate')?></span>
                        </div>
                      </div>
                    </div>

                    <div style="display: flex; align-items: center; gap: 14px;">
                      <!-- STATUS BADGE -->
                      <div>
                        <span class="status-tag <?=htmlspecialchars($r['recipient_status'])?>"><?=htmlspecialchars(ucfirst($r['recipient_status']))?></span>
                        <?php if ($r['error_text']): ?>
                          <div style="font-size: 11px; color: #dc2626; margin-top: 2px; text-align: right;"><?=htmlspecialchars($r['error_text'])?></div>
                        <?php endif; ?>
                      </div>

                      <!-- LOCK STATE -->
                      <div>
                        <?php if ($r['is_locked']): ?>
                          <span style="color: #d97706; font-weight: 700; font-size: 12px;" title="Candidate is currently locked"><i class="fa-solid fa-lock"></i> Locked</span>
                        <?php else: ?>
                          <span style="color: #94a3b8; font-size: 12px;" title="Unlocked and available"><i class="fa-solid fa-unlock"></i> Unlocked</span>
                        <?php endif; ?>
                      </div>

                      <!-- ACTION BUTTONS -->
                      <div style="display: flex; gap: 6px;">
                        <?php if ($r['recipient_status'] === 'failed'): ?>
                          <form method="post" style="margin: 0;">
                            <input type="hidden" name="csrf" value="<?=$csrfToken?>">
                            <input type="hidden" name="action" value="resend_failed">
                            <input type="hidden" name="batch_id" value="<?=(int)$b['batch_id']?>">
                            <input type="hidden" name="candidate_id" value="<?=htmlspecialchars($r['candidate_id'])?>">
                            <button type="submit" class="btn-purple btn-sm"><i class="fa-solid fa-paper-plane"></i> Resend</button>
                          </form>
                        <?php endif; ?>

                        <?php if ($r['is_locked']): ?>
                          <form method="post" style="margin: 0;" onsubmit="return confirm('Reset lock for <?=htmlspecialchars($r['candidate_name'] ?: 'candidate')?>?')">
                            <input type="hidden" name="csrf" value="<?=$csrfToken?>">
                            <input type="hidden" name="action" value="reset_candidate">
                            <input type="hidden" name="batch_id" value="<?=(int)$b['batch_id']?>">
                            <input type="hidden" name="candidate_id" value="<?=htmlspecialchars($r['candidate_id'])?>">
                            <button type="submit" class="btn-danger btn-sm"><i class="fa-solid fa-rotate-left"></i> Unlock</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

  <?php else: ?>
    <!-- ALL CANDIDATE RECIPIENTS VIEW -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 20px; box-shadow: 0 2px 8px rgba(15,23,42,0.04);">
      <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
          <thead>
            <tr style="background: #f8fafc; color: #475569;">
              <th style="padding: 12px 14px; text-align: left; border-bottom: 1px solid #e2e8f0;">Candidate Name</th>
              <th style="padding: 12px 14px; text-align: left; border-bottom: 1px solid #e2e8f0;">Email Address</th>
              <th style="padding: 12px 14px; text-align: left; border-bottom: 1px solid #e2e8f0;">Job / Subject</th>
              <th style="padding: 12px 14px; text-align: left; border-bottom: 1px solid #e2e8f0;">Batch #</th>
              <th style="padding: 12px 14px; text-align: left; border-bottom: 1px solid #e2e8f0;">Delivery Status</th>
              <th style="padding: 12px 14px; text-align: left; border-bottom: 1px solid #e2e8f0;">Sent Timestamp</th>
              <th style="padding: 12px 14px; text-align: left; border-bottom: 1px solid #e2e8f0;">Lock State</th>
              <th style="padding: 12px 14px; text-align: right; border-bottom: 1px solid #e2e8f0;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allRecipients)): ?>
              <tr>
                <td colspan="8" style="text-align: center; color: #64748b; padding: 24px;">No email recipient dispatches found.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($allRecipients as $r): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                  <td style="padding: 12px 14px;">
                    <strong>
                      <?php if ($r['candidate_id']): ?>
                        <a href="candidate_detail.php?id=<?=urlencode($r['candidate_id'])?>" target="_blank" style="color: #7c3aed; text-decoration: none;">
                          <?=htmlspecialchars($r['candidate_name'] ?: $r['candidate_id'])?> <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 10px;"></i>
                        </a>
                      <?php else: ?>
                        <?=htmlspecialchars($r['candidate_name'] ?: 'Unknown')?>
                      <?php endif; ?>
                    </strong>
                  </td>
                  <td style="padding: 12px 14px;"><?=htmlspecialchars($r['email'])?></td>
                  <td style="padding: 12px 14px;">
                    <strong><?=htmlspecialchars($r['job_title'] ?: 'Interview')?></strong>
                    <div style="font-size: 11px; color: #64748b;"><?=htmlspecialchars($r['subject'])?></div>
                  </td>
                  <td style="padding: 12px 14px;"><strong>#<?=(int)$r['batch_id']?></strong></td>
                  <td style="padding: 12px 14px;">
                    <span class="status-tag <?=htmlspecialchars($r['recipient_status'])?>"><?=htmlspecialchars(ucfirst($r['recipient_status']))?></span>
                    <?php if ($r['error_text']): ?>
                      <div style="font-size: 11px; color: #dc2626; margin-top: 2px;"><?=htmlspecialchars($r['error_text'])?></div>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 12px 14px; color: #64748b;"><?=htmlspecialchars($r['sent_at'] ? date('d M Y, h:i A', strtotime($r['sent_at'])) : 'Not sent')?></td>
                  <td style="padding: 12px 14px;">
                    <?php if ($r['is_locked']): ?>
                      <span style="color: #d97706; font-weight: 700; font-size: 11px;"><i class="fa-solid fa-lock"></i> Locked</span>
                    <?php else: ?>
                      <span style="color: #94a3b8; font-size: 11px;"><i class="fa-solid fa-unlock"></i> Unlocked</span>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 12px 14px; text-align: right;">
                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                      <?php if ($r['recipient_status'] === 'failed'): ?>
                        <form method="post" style="margin: 0;">
                          <input type="hidden" name="csrf" value="<?=$csrfToken?>">
                          <input type="hidden" name="action" value="resend_failed">
                          <input type="hidden" name="batch_id" value="<?=(int)$r['batch_id']?>">
                          <input type="hidden" name="candidate_id" value="<?=htmlspecialchars($r['candidate_id'])?>">
                          <button type="submit" class="btn-purple btn-sm"><i class="fa-solid fa-paper-plane"></i> Resend</button>
                        </form>
                      <?php endif; ?>

                      <?php if ($r['is_locked']): ?>
                        <form method="post" style="margin: 0;" onsubmit="return confirm('Reset lock for <?=htmlspecialchars($r['candidate_name'] ?: 'candidate')?>?')">
                          <input type="hidden" name="csrf" value="<?=$csrfToken?>">
                          <input type="hidden" name="action" value="reset_candidate">
                          <input type="hidden" name="batch_id" value="<?=(int)$r['batch_id']?>">
                          <input type="hidden" name="candidate_id" value="<?=htmlspecialchars($r['candidate_id'])?>">
                          <button type="submit" class="btn-danger btn-sm"><i class="fa-solid fa-rotate-left"></i> Unlock</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/views/partials/footer.php'; ?>
