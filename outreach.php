<?php
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/workspace.php';
require_once __DIR__ . '/lib/outreach_campaign.php';

$pdo = db();
$user = currentUser();
$csrfToken = csrfToken();

$activePage = 'outreach';
$pageTitle = 'Hiring Outreach Campaign · NonceBlox ATS';

$jobs = getEligibleHiringJobs($pdo);
$canonicalCareerUrl = getCanonicalCareersUrl($pdo);

// Check if there is an active running/sending or paused campaign to restore UI state
$activeCmpStmt = $pdo->query("SELECT id, name, status FROM email_campaigns WHERE status IN ('sending', 'paused', 'queued') ORDER BY id DESC LIMIT 1");
$activeCampaign = $activeCmpStmt->fetch(PDO::FETCH_ASSOC);

require_once __DIR__ . '/views/partials/header.php';
?>

<style>
.outreach-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
}
.outreach-header h1 {
  font-size: 22px;
  font-weight: 700;
  color: var(--text-dark, #0f172a);
  margin: 0;
  display: flex;
  align-items: center;
  gap: 10px;
}
.outreach-header h1 i {
  color: #7c3aed;
}

.outreach-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}
@media (max-width: 1024px) {
  .outreach-grid {
    grid-template-columns: 1fr;
  }
}

.card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 20px;
  margin-bottom: 20px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

.card-title {
  font-size: 15px;
  font-weight: 700;
  color: #1e293b;
  margin-bottom: 14px;
  display: flex;
  align-items: center;
  gap: 8px;
  padding-bottom: 10px;
  border-bottom: 1px solid #f1f5f9;
}

.form-group {
  margin-bottom: 14px;
}
.form-group label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: #475569;
  margin-bottom: 6px;
}
.form-control {
  width: 100%;
  padding: 10px 14px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  font-size: 13px;
  color: #0f172a;
  background: #fff;
  transition: border-color 0.15s ease;
}
.form-control:focus {
  outline: none;
  border-color: #7c3aed;
  box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
}

.jobs-select-list {
  max-height: 240px;
  overflow-y: auto;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 6px;
  background: #f8fafc;
}
.job-item-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
  border-radius: 6px;
  background: #fff;
  margin-bottom: 4px;
  border: 1px solid #f1f5f9;
  cursor: pointer;
  transition: background 0.15s;
}
.job-item-row:hover {
  background: #f1f5f9;
}
.job-item-label {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  font-weight: 600;
  color: #334155;
  cursor: pointer;
}
.applicant-count-badge {
  font-size: 11px;
  font-weight: 600;
  background: #ede9fe;
  color: #6d28d9;
  padding: 2px 8px;
  border-radius: 12px;
}

.stat-pills {
  display: flex;
  gap: 10px;
  margin-bottom: 14px;
  flex-wrap: wrap;
}
.stat-pill {
  flex: 1;
  min-width: 90px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 10px;
  text-align: center;
}
.stat-pill .num {
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
}
.stat-pill .lbl {
  font-size: 11px;
  font-weight: 600;
  color: #64748b;
  text-transform: uppercase;
}

.stat-pill.eligible .num { color: #16a34a; }
.stat-pill.excluded .num { color: #dc2626; }

.recipient-inspection-box {
  max-height: 200px;
  overflow-y: auto;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  background: #fff;
  font-size: 12px;
}
.recipient-row {
  display: flex;
  justify-content: space-between;
  padding: 6px 12px;
  border-bottom: 1px solid #f1f5f9;
}

.btn-primary-purple {
  background: #7c3aed;
  color: #ffffff;
  border: 0;
  padding: 10px 20px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: background 0.15s;
}
.btn-primary-purple:hover {
  background: #6d28d9;
}
.btn-secondary {
  background: #f1f5f9;
  color: #334155;
  border: 1px solid #cbd5e1;
  padding: 8px 16px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}
.btn-secondary:hover {
  background: #e2e8f0;
}

.dark-email-frame-wrap {
  background: #0f0c1b;
  border-radius: 8px;
  padding: 14px;
  overflow: hidden;
}
.email-preview-iframe {
  width: 100%;
  height: 480px;
  border: 0;
  border-radius: 6px;
  background: #0f0c1b;
}

/* Modal Styling */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.6);
  backdrop-filter: blur(4px);
  z-index: 999;
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.2s ease;
}
.modal-overlay.open {
  opacity: 1;
  pointer-events: auto;
}
.modal-content {
  background: #ffffff;
  border-radius: 12px;
  width: 90%;
  max-width: 520px;
  padding: 24px;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
}

/* Live Sending Full-screen Translucent Overlay */
.sending-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 12, 27, 0.92);
  backdrop-filter: blur(8px);
  z-index: 1000;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  color: #fff;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.3s ease;
}
.sending-overlay.active {
  opacity: 1;
  pointer-events: auto;
}
.overlay-card {
  background: #161224;
  border: 1px solid #2e2842;
  border-radius: 16px;
  width: 90%;
  max-width: 500px;
  padding: 32px;
  text-align: center;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
}
.overlay-progress-bar-track {
  width: 100%;
  height: 12px;
  background: #28223b;
  border-radius: 6px;
  overflow: hidden;
  margin: 20px 0;
}
.overlay-progress-bar-fill {
  height: 100%;
  width: 0%;
  background: linear-gradient(90deg, #7c3aed, #a78bfa);
  transition: width 0.4s ease;
}
.overlay-metrics {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 12px;
  margin-top: 20px;
}
.overlay-metric {
  background: #1e1930;
  border: 1px solid #2e2842;
  border-radius: 8px;
  padding: 12px;
}
.overlay-metric .val {
  font-size: 20px;
  font-weight: 700;
}
.overlay-metric .lbl {
  font-size: 11px;
  color: #94a3b8;
  text-transform: uppercase;
}
</color-box>
</style>

<div class="outreach-page">
  <div class="outreach-header">
    <h1><i class="fa-solid fa-paper-plane"></i> Hiring Outreach Campaign</h1>
    <div>
      <a href="outreach_history.php" class="btn-secondary" title="View Campaign History"><i class="fa-solid fa-clock-rotate-left"></i> Campaign History</a>
    </div>
  </div>

  <div class="outreach-grid">
    <!-- LEFT COLUMN: Campaign Creation Controls -->
    <div class="left-col">
      <!-- Card 1: Campaign Details -->
      <div class="card">
        <div class="card-title"><i class="fa-solid fa-pen-to-square"></i> 1. Campaign Details</div>
        <div class="form-group">
          <label for="campaignName">Campaign Name</label>
          <input type="text" id="campaignName" class="form-control" value="NonceBlox Hiring Outreach — <?=date('M Y')?>" placeholder="e.g. October Hiring Outreach">
        </div>
        <div class="form-group">
          <label for="campaignSubject">Email Subject</label>
          <input type="text" id="campaignSubject" class="form-control" value="We're hiring — explore opportunities at NonceBlox" placeholder="Enter email subject line">
        </div>
      </div>

      <!-- Card 2: Select Open Jobs -->
      <div class="card">
        <div class="card-title"><i class="fa-solid fa-briefcase"></i> 2. Select Open Hiring Jobs</div>
        <div class="jobs-select-list" id="jobsList">
          <?php if (empty($jobs)): ?>
            <div style="padding: 12px; text-align: center; color: #64748b; font-size: 13px;">No open hiring jobs found in database.</div>
          <?php else: ?>
            <?php foreach ($jobs as $index => $job): ?>
              <div class="job-item-row" onclick="toggleJobCheckbox(<?=$job['id']?>)">
                <label class="job-item-label">
                  <input type="checkbox" class="job-checkbox" value="<?=$job['id']?>" <?= $index < 3 ? 'checked' : '' ?> onchange="onRecipientFilterChange()">
                  <span><?=htmlspecialchars($job['title'])?></span>
                </label>
                <span class="applicant-count-badge"><?=(int)$job['applicant_count']?> Applicants</span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Card 3: Recipient Selection & Calculation -->
      <div class="card">
        <div class="card-title"><i class="fa-solid fa-user-group"></i> 3. Recipient Selection</div>
        
        <div class="stat-pills">
          <div class="stat-pill"><div class="num" id="statTotal">0</div><div class="lbl">Total Found</div></div>
          <div class="stat-pill eligible"><div class="num" id="statEligible">0</div><div class="lbl">Eligible</div></div>
          <div class="stat-pill excluded"><div class="num" id="statExcluded">0</div><div class="lbl">Excluded</div></div>
        </div>

        <div class="form-group" style="margin-top: 10px;">
          <label>Optional Safety Exclusions</label>
          <div style="display: flex; gap: 12px; font-size: 12px; color: #475569;">
            <label><input type="checkbox" id="exOffered" onchange="onRecipientFilterChange()" checked> Already Offered</label>
            <label><input type="checkbox" id="exJoined" onchange="onRecipientFilterChange()" checked> Already Joined</label>
            <label><input type="checkbox" id="exRejected" onchange="onRecipientFilterChange()" checked> Rejected</label>
          </div>
        </div>

        <div style="margin-top: 12px;">
          <button class="btn-secondary" style="width: 100%; text-align: center;" onclick="toggleRecipientInspection()">
            <i class="fa-solid fa-eye"></i> Inspect Recipient Candidates List (<span id="eligibleBtnCount">0</span>)
          </button>
        </div>

        <div id="recipientInspectionBox" class="recipient-inspection-box" style="display: none; margin-top: 10px;">
          <div id="recipientListRows" style="padding: 4px;"></div>
        </div>
      </div>

      <!-- Card 4: Send Test Email -->
      <div class="card">
        <div class="card-title"><i class="fa-solid fa-vial"></i> 4. Send Test Email</div>
        <div style="display: flex; gap: 10px;">
          <input type="email" id="testEmail" class="form-control" value="<?=htmlspecialchars($user['email'] ?? '')?>" placeholder="Enter test recipient email">
          <button class="btn-secondary" onclick="sendTestEmail()" id="btnSendTest"><i class="fa-solid fa-paper-plane"></i> Send Test</button>
        </div>
        <div id="testEmailFeedback" style="margin-top: 8px; font-size: 12px;"></div>
      </div>
    </div>

    <!-- RIGHT COLUMN: Live Template Preview & Launch -->
    <div class="right-col">
      <div class="card">
        <div class="card-title" style="justify-content: space-between;">
          <span><i class="fa-solid fa-envelope-open-text"></i> 5. Dark Email Template Preview</span>
          <span style="font-size: 11px; font-weight: 500; color: #64748b;">Responsive Dark Layout</span>
        </div>
        
        <div class="dark-email-frame-wrap">
          <iframe id="emailPreviewIframe" class="email-preview-iframe" src="about:blank"></iframe>
        </div>

        <div style="margin-top: 20px; display: flex; justify-content: flex-end;">
          <button class="btn-primary-purple" id="btnStartCampaign" onclick="openConfirmationModal()">
            <i class="fa-solid fa-rocket"></i> Start Hiring Campaign
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- CONFIRMATION MODAL -->
<div class="modal-overlay" id="confirmModal">
  <div class="modal-content">
    <h3 style="margin-top: 0; font-size: 18px; color: #0f172a;"><i class="fa-solid fa-circle-question" style="color: #7c3aed;"></i> Confirm Campaign Dispatch</h3>
    <p style="font-size: 13px; color: #475569; line-height: 1.6;">You are about to start a background hiring outreach campaign:</p>
    
    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; font-size: 13px; margin: 16px 0;">
      <div><strong>Campaign:</strong> <span id="confirmName"></span></div>
      <div style="margin-top: 6px;"><strong>Recipients:</strong> <span id="confirmRecipients" style="color: #16a34a; font-weight: 700;"></span> candidates</div>
      <div style="margin-top: 6px;"><strong>Selected Jobs:</strong> <span id="confirmJobs"></span></div>
      <div style="margin-top: 6px;"><strong>CTA Destination:</strong> <code style="font-size: 11px; background: #e2e8f0; padding: 2px 4px; border-radius: 4px;"><?=htmlspecialchars($canonicalCareerUrl)?></code></div>
    </div>

    <div style="display: flex; justify-content: flex-end; gap: 10px;">
      <button class="btn-secondary" onclick="closeConfirmationModal()">Cancel</button>
      <button class="btn-primary-purple" onclick="executeCreateAndStartCampaign()"><i class="fa-solid fa-paper-plane"></i> Confirm & Send</button>
    </div>
  </div>
</div>

<!-- FULL-SCREEN LIVE SENDING OVERLAY -->
<div class="sending-overlay" id="sendingOverlay">
  <div class="overlay-card">
    <div style="font-size: 32px; color: #a78bfa; margin-bottom: 12px;"><i class="fa-solid fa-paper-plane fa-bounce"></i></div>
    <h2 style="margin: 0; font-size: 20px; font-weight: 700;">Sending Hiring Campaign</h2>
    <div id="overlayCampaignName" style="font-size: 13px; color: #94a3b8; margin-top: 4px;">NonceBlox Hiring Outreach</div>

    <div class="overlay-progress-bar-track">
      <div class="overlay-progress-bar-fill" id="overlayProgressFill"></div>
    </div>

    <div style="font-size: 24px; font-weight: 800; color: #f3f0ff;" id="overlayProgressText">0 / 0</div>
    <div style="font-size: 13px; color: #a78bfa; font-weight: 600;" id="overlayPercent">0%</div>

    <div class="overlay-metrics">
      <div class="overlay-metric">
        <div class="val" id="overlaySent" style="color: #4ade80;">0</div>
        <div class="lbl">Sent</div>
      </div>
      <div class="overlay-metric">
        <div class="val" id="overlayFailed" style="color: #f87171;">0</div>
        <div class="lbl">Failed</div>
      </div>
      <div class="overlay-metric">
        <div class="val" id="overlayRemaining" style="color: #38bdf8;">0</div>
        <div class="lbl">Remaining</div>
      </div>
    </div>

    <div style="margin-top: 24px; display: flex; justify-content: center; gap: 12px;">
      <button class="btn-secondary" style="background: #28223b; color: #fff; border-color: #3b3354;" onclick="pauseCampaign()" id="btnPause"><i class="fa-solid fa-pause"></i> Pause</button>
      <button class="btn-secondary" style="background: #28223b; color: #fff; border-color: #3b3354; display: none;" onclick="resumeCampaign()" id="btnResume"><i class="fa-solid fa-play"></i> Resume</button>
      <button class="btn-secondary" style="background: #3f1d24; color: #fca5a5; border-color: #5c2630;" onclick="cancelCampaign()"><i class="fa-solid fa-xmark"></i> Cancel Campaign</button>
    </div>

    <div style="font-size: 11px; color: #64748b; margin-top: 16px;">
      <i class="fa-solid fa-info-circle"></i> Background processing is active. You may safely close or navigate away from this page.
    </div>
  </div>
</div>

<script>
const CSRF_TOKEN = '<?=$csrfToken?>';
let currentRecipientsData = null;
let activeCampaignId = <?= (int)($activeCampaign['id'] ?? 0) ?>;
let pollTimer = null;

document.addEventListener('DOMContentLoaded', () => {
  onRecipientFilterChange();
  if (activeCampaignId > 0) {
    showSendingOverlay();
    startPollingProgress();
  }
});

function toggleJobCheckbox(jobId) {
  const cb = document.querySelector(`.job-checkbox[value="${jobId}"]`);
  if (cb && event.target !== cb) {
    cb.checked = !cb.checked;
    onRecipientFilterChange();
  }
}

function getSelectedJobIds() {
  const checkboxes = document.querySelectorAll('.job-checkbox:checked');
  return Array.from(checkboxes).map(cb => parseInt(cb.value));
}

function onRecipientFilterChange() {
  const selectedJobIds = getSelectedJobIds();
  const excludeStages = [];
  if (document.getElementById('exOffered').checked) excludeStages.push('Offered');
  if (document.getElementById('exJoined').checked) excludeStages.push('Joined');
  if (document.getElementById('exRejected').checked) excludeStages.push('Rejected');

  fetch('outreach_action.php?action=calculate_recipients', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
    body: JSON.stringify({
      csrf: CSRF_TOKEN,
      selected_job_ids: selectedJobIds,
      filters: { exclude_stages: excludeStages }
    })
  })
  .then(res => res.json())
  .then(res => {
    if (res.success) {
      currentRecipientsData = res.data;
      document.getElementById('statTotal').innerText = res.data.total_found;
      document.getElementById('statEligible').innerText = res.data.eligible_count;
      document.getElementById('statExcluded').innerText = res.data.excluded_count;
      document.getElementById('eligibleBtnCount').innerText = res.data.eligible_count;

      renderRecipientInspectionList(res.data.eligible);
      updateLivePreview();
    }
  });
}

function renderRecipientInspectionList(eligible) {
  const container = document.getElementById('recipientListRows');
  if (!eligible || eligible.length === 0) {
    container.innerHTML = '<div style="padding: 8px; color: #64748b;">No eligible candidates selected.</div>';
    return;
  }
  let html = '';
  eligible.slice(0, 50).forEach(c => {
    html += `<div class="recipient-row"><span><strong>${escapeHtml(c.name)}</strong> (${escapeHtml(c.email)})</span><span style="color:#64748b;">${escapeHtml(c.stage)}</span></div>`;
  });
  if (eligible.length > 50) {
    html += `<div style="padding: 6px; text-align: center; color: #64748b;">...and ${eligible.length - 50} more candidates</div>`;
  }
  container.innerHTML = html;
}

function toggleRecipientInspection() {
  const box = document.getElementById('recipientInspectionBox');
  box.style.display = (box.style.display === 'none') ? 'block' : 'none';
}

function updateLivePreview() {
  const selectedJobIds = getSelectedJobIds();
  fetch('outreach_action.php?action=preview', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
    body: JSON.stringify({
      csrf: CSRF_TOKEN,
      selected_job_ids: selectedJobIds,
      candidate_name: 'Alex Rivera'
    })
  })
  .then(res => res.json())
  .then(res => {
    if (res.success) {
      const iframe = document.getElementById('emailPreviewIframe');
      const doc = iframe.contentWindow.document;
      doc.open();
      doc.write(res.html);
      doc.close();
    }
  });
}

function sendTestEmail() {
  const testEmail = document.getElementById('testEmail').value.trim();
  const feedback = document.getElementById('testEmailFeedback');
  const btn = document.getElementById('btnSendTest');

  if (!testEmail) {
    feedback.innerHTML = '<span style="color: #dc2626;">Please enter a recipient email address.</span>';
    return;
  }

  feedback.innerHTML = '<span style="color: #64748b;"><i class="fa-solid fa-spinner fa-spin"></i> Sending test email...</span>';
  btn.disabled = true;

  fetch('outreach_action.php?action=send_test', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
    body: JSON.stringify({
      csrf: CSRF_TOKEN,
      test_email: testEmail,
      subject: document.getElementById('campaignSubject').value,
      selected_job_ids: getSelectedJobIds()
    })
  })
  .then(res => res.json())
  .then(res => {
    btn.disabled = false;
    if (res.success) {
      feedback.innerHTML = `<span style="color: #16a34a;"><i class="fa-solid fa-circle-check"></i> ${escapeHtml(res.message)}</span>`;
    } else {
      feedback.innerHTML = `<span style="color: #dc2626;"><i class="fa-solid fa-circle-exclamation"></i> ${escapeHtml(res.error || 'Test send failed.')}</span>`;
    }
  })
  .catch(() => {
    btn.disabled = false;
    feedback.innerHTML = '<span style="color: #dc2626;">Server error while sending test email.</span>';
  });
}

function openConfirmationModal() {
  const selectedJobIds = getSelectedJobIds();
  if (selectedJobIds.length === 0) {
    alert('Please select at least one open hiring job.');
    return;
  }
  if (!currentRecipientsData || currentRecipientsData.eligible_count === 0) {
    alert('No eligible candidate recipients found for the selected jobs.');
    return;
  }

  document.getElementById('confirmName').innerText = document.getElementById('campaignName').value || 'Hiring Outreach Campaign';
  document.getElementById('confirmRecipients').innerText = currentRecipientsData.eligible_count;
  document.getElementById('confirmJobs').innerText = selectedJobIds.length + ' open job(s)';

  document.getElementById('confirmModal').classList.add('open');
}

function closeConfirmationModal() {
  document.getElementById('confirmModal').classList.remove('open');
}

function executeCreateAndStartCampaign() {
  closeConfirmationModal();

  const selectedJobIds = getSelectedJobIds();
  const name = document.getElementById('campaignName').value;
  const subject = document.getElementById('campaignSubject').value;

  fetch('outreach_action.php?action=create_campaign', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
    body: JSON.stringify({
      csrf: CSRF_TOKEN,
      name: name,
      subject: subject,
      selected_job_ids: selectedJobIds
    })
  })
  .then(res => res.json())
  .then(res => {
    if (res.success) {
      activeCampaignId = res.campaign_id;
      showSendingOverlay();
      startPollingProgress();
    } else {
      alert(res.error || 'Failed to start campaign.');
    }
  });
}

function showSendingOverlay() {
  document.getElementById('sendingOverlay').classList.add('active');
  document.getElementById('btnStartCampaign').disabled = true;
}

function hideSendingOverlay() {
  document.getElementById('sendingOverlay').classList.remove('active');
  document.getElementById('btnStartCampaign').disabled = false;
}

function startPollingProgress() {
  if (pollTimer) clearInterval(pollTimer);
  pollProgress();
  pollTimer = setInterval(pollProgress, 2000);
}

function pollProgress() {
  if (!activeCampaignId) return;

  fetch(`outreach_action.php?action=progress&id=${activeCampaignId}`)
  .then(res => res.json())
  .then(data => {
    if (data.error) {
      clearInterval(pollTimer);
      return;
    }

    document.getElementById('overlayCampaignName').innerText = data.name;
    document.getElementById('overlayProgressFill').style.width = data.percent + '%';
    document.getElementById('overlayProgressText').innerText = `${data.processed} / ${data.total}`;
    document.getElementById('overlayPercent').innerText = data.percent + '%';

    document.getElementById('overlaySent').innerText = data.sent;
    document.getElementById('overlayFailed').innerText = data.failed;
    document.getElementById('overlayRemaining').innerText = data.pending + data.processing;

    if (data.status === 'paused') {
      document.getElementById('btnPause').style.display = 'none';
      document.getElementById('btnResume').style.display = 'inline-flex';
    } else {
      document.getElementById('btnPause').style.display = 'inline-flex';
      document.getElementById('btnResume').style.display = 'none';
    }

    if (data.status === 'completed' || data.status === 'completed_with_errors' || data.status === 'cancelled') {
      clearInterval(pollTimer);
      setTimeout(() => {
        window.location.href = `outreach_report.php?id=${activeCampaignId}`;
      }, 1500);
    }
  });
}

function pauseCampaign() {
  fetch('outreach_action.php?action=pause', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
    body: JSON.stringify({ csrf: CSRF_TOKEN, campaign_id: activeCampaignId })
  }).then(() => pollProgress());
}

function resumeCampaign() {
  fetch('outreach_action.php?action=resume', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
    body: JSON.stringify({ csrf: CSRF_TOKEN, campaign_id: activeCampaignId })
  }).then(() => pollProgress());
}

function cancelCampaign() {
  if (!confirm('Are you sure you want to cancel this campaign? Pending emails will not be sent.')) return;

  fetch('outreach_action.php?action=cancel', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
    body: JSON.stringify({ csrf: CSRF_TOKEN, campaign_id: activeCampaignId })
  }).then(() => {
    hideSendingOverlay();
    window.location.href = `outreach_report.php?id=${activeCampaignId}`;
  });
}

function escapeHtml(str) {
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

<?php require_once __DIR__ . '/views/partials/footer.php'; ?>
