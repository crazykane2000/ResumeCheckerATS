<?php
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/workspace.php';
require_once __DIR__ . '/lib/branding.php';
require_once __DIR__ . '/lib/lifecycle_mailer.php';

$pdo = db();
$user = currentUser();
$brand = organizationBrand();

$jobId = (int)($_GET['job_id'] ?? 0);
$batchId = (int)($_GET['batch_id'] ?? 0);
$viewMode = trim((string)($_GET['view'] ?? 'active')); // 'active' (interview funnel) or 'pool' (raw applicants)
if (!in_array($viewMode, ['active', 'pool'], true)) {
    $viewMode = 'active';
}

// Fetch all jobs for Job Filter
$jobs = $pdo->query("
    SELECT j.id, j.title, COUNT(c.id) AS applicant_count 
    FROM jobs j 
    LEFT JOIN candidates c ON c.job_id = j.id 
    GROUP BY j.id 
    ORDER BY applicant_count DESC, j.title ASC
")->fetchAll();

$jobName = 'All Jobs';
if ($jobId) {
    $s = $pdo->prepare('SELECT title FROM jobs WHERE id = ?');
    $s->execute([$jobId]);
    $jobName = $s->fetchColumn() ?: 'Selected Job';
}

// Fetch available interview batches for Batch Filter
$batchSql = "
    SELECT ib.id, ib.name, ib.availability_start, ib.availability_end, j.title AS job_title, COUNT(ii.id) AS invite_count
    FROM interview_batches ib
    JOIN jobs j ON j.id = ib.job_id
    LEFT JOIN interview_invitations ii ON ii.batch_id = ib.id
    " . ($jobId ? "WHERE ib.job_id = ?" : "") . "
    GROUP BY ib.id
    ORDER BY ib.id DESC
";
$bStmt = $pdo->prepare($batchSql);
$bStmt->execute($jobId ? [$jobId] : []);
$batches = $bStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch candidates with batch, invitation, and slot details
$whereParts = [];
$params = [];
if ($jobId) {
    $whereParts[] = "c.job_id = ?";
    $params[] = $jobId;
}
if ($batchId) {
    $whereParts[] = "ib.id = ?";
    $params[] = $batchId;
}
$whereSql = $whereParts ? ("WHERE " . implode(" AND ", $whereParts)) : "";

$candidatesSql = "
    SELECT 
        c.id, c.name, c.email, c.stage, c.role_title, c.score, c.source_name, c.created_at,
        j.id AS job_id, j.title AS job_title,
        ib.id AS batch_id, ib.name AS batch_name,
        ii.id AS invitation_id, ii.status AS invitation_status, ii.expires_at,
        isl.starts_at AS slot_starts_at, isl.ends_at AS slot_ends_at
    FROM candidates c
    LEFT JOIN jobs j ON j.id = c.job_id
    LEFT JOIN interview_invitations ii ON ii.candidate_id = c.id
    LEFT JOIN interview_batches ib ON ib.id = ii.batch_id
    LEFT JOIN interview_slots isl ON isl.id = ii.confirmed_slot_id
    $whereSql
    ORDER BY COALESCE(isl.starts_at, c.created_at) DESC
";
$cStmt = $pdo->prepare($candidatesSql);
$cStmt->execute($params);
$rawCandidates = $cStmt->fetchAll(PDO::FETCH_ASSOC);

// Categorize candidates into the Active Pipeline stages
$activeColumns = [
    'Invited' => [
        'key' => 'Invited',
        'label' => 'Invited',
        'sub' => 'Awaiting Slot Booking',
        'color' => '#7c3aed',
        'bg' => '#ede9fe',
        'icon' => 'fa-envelope-circle-check',
        'items' => []
    ],
    'Round 1' => [
        'key' => 'Round 1',
        'label' => 'Round 1 Confirmed',
        'sub' => 'Candidate Booked Slot',
        'color' => '#0284c7',
        'bg' => '#e0f2fe',
        'icon' => 'fa-calendar-check',
        'items' => []
    ],
    'Round 2' => [
        'key' => 'Round 2',
        'label' => 'Round 2',
        'sub' => 'Technical / Leadership Discussion',
        'color' => '#9333ea',
        'bg' => '#f3e8ff',
        'icon' => 'fa-star',
        'items' => []
    ],
    'Offer' => [
        'key' => 'Offer',
        'label' => 'Offer Extended',
        'sub' => 'Selected Candidates',
        'color' => '#16a34a',
        'bg' => '#dcfce7',
        'icon' => 'fa-award',
        'items' => []
    ],
    'Rejected' => [
        'key' => 'Rejected',
        'label' => 'Rejected',
        'sub' => 'Not Moving Forward',
        'color' => '#dc2626',
        'bg' => '#fee2e2',
        'icon' => 'fa-ban',
        'items' => []
    ]
];

$poolItems = []; // Raw uncontacted applicants

foreach ($rawCandidates as $cand) {
    $stage = trim((string)$cand['stage']);
    $invStatus = trim((string)($cand['invitation_status'] ?? ''));
    $hasSlot = !empty($cand['slot_starts_at']);

    if (strcasecmp($stage, 'Rejected') === 0) {
        $activeColumns['Rejected']['items'][] = $cand;
    } elseif (strcasecmp($stage, 'Offer') === 0) {
        $activeColumns['Offer']['items'][] = $cand;
    } elseif (strcasecmp($stage, 'Round 2') === 0) {
        $activeColumns['Round 2']['items'][] = $cand;
    } elseif ($hasSlot || strcasecmp($invStatus, 'confirmed') === 0 || strcasecmp($stage, 'Round 1') === 0) {
        $activeColumns['Round 1']['items'][] = $cand;
    } elseif (!empty($cand['invitation_id']) || strcasecmp($invStatus, 'awaiting_confirmation') === 0 || strcasecmp($stage, 'Interview') === 0 || strcasecmp($stage, 'Invited') === 0) {
        $activeColumns['Invited']['items'][] = $cand;
    } else {
        // Raw applicant pool (Applied / Screening)
        $poolItems[] = $cand;
    }
}

$activeTotal = array_sum(array_map(fn($col) => count($col['items']), $activeColumns));
$poolTotal = count($poolItems);

$activePage = 'pipeline';
$pageTitle = 'Hiring Pipeline & Batch Tracker · NonceBlox ATS';

$pageStyles = <<<'CSS'
<style>
/* Page Layout & Toolbar */
.pipeline-top-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 20px;
  padding-bottom: 16px;
  border-bottom: 1px solid #eef2f6;
}
.header-meta-wrap h1 {
  margin: 0;
  font-size: 22px;
  font-weight: 800;
  color: #0f172a;
  letter-spacing: -0.3px;
}
.header-meta-wrap p {
  margin: 4px 0 0;
  font-size: 13px;
  color: #64748b;
}

.filters-cluster {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

/* View Mode Switcher Pills */
.view-switch {
  display: inline-flex;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 3px;
  gap: 3px;
  height: 38px;
  align-items: center;
}
.view-switch-btn {
  border: none;
  background: transparent;
  padding: 6px 14px;
  font-size: 12px;
  font-weight: 600;
  color: #64748b;
  border-radius: 6px;
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: all 0.15s ease;
  white-space: nowrap;
}
.view-switch-btn.active {
  background: #ffffff;
  color: #6f45ff;
  font-weight: 700;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

/* Minimal Dropdown Filters */
.filter-box {
  display: inline-flex;
  align-items: center;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  height: 38px;
  padding: 0 4px 0 10px;
  gap: 8px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.02);
}
.filter-box label {
  font-size: 11px;
  font-weight: 700;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  white-space: nowrap;
}
.pipeline-select {
  height: 32px;
  min-width: 170px;
  border: none;
  background: transparent;
  padding: 0 6px;
  font-size: 12.5px;
  font-weight: 600;
  color: #1e293b;
  outline: none;
  cursor: pointer;
}

/* Kanban Board Layout */
.kanban-board-wrapper {
  overflow-x: auto;
  padding-bottom: 20px;
}
.kanban-board {
  display: grid;
  grid-template-columns: repeat(5, minmax(240px, 1fr));
  gap: 12px;
  align-items: start;
  min-width: 1220px;
}

/* Kanban Column */
.kanban-col {
  background: #f8fafc;
  border: 1px solid #eaedf3;
  border-radius: 10px;
  display: flex;
  flex-direction: column;
  min-height: calc(100vh - 220px);
  max-height: calc(100vh - 200px);
  transition: border-color 0.15s ease, background-color 0.15s ease;
}
.kanban-col.drag-over {
  border-color: #6f45ff !important;
  background: #fbfaff !important;
}
.kanban-col-head {
  padding: 12px 14px;
  border-bottom: 1px solid #f1f5f9;
  background: #ffffff;
  border-radius: 9px 9px 0 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.col-title-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
}
.col-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.col-title-wrap strong {
  font-size: 13px;
  font-weight: 700;
  color: #0f172a;
}
.kanban-badge {
  font-size: 11px;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 6px;
  color: #64748b;
  background: #f1f5f9;
}
.kanban-dropzone {
  flex: 1;
  padding: 10px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.kanban-dropzone::-webkit-scrollbar {
  width: 4px;
}
.kanban-dropzone::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 4px;
}

/* Candidate Card - Steady & Minimal */
.cand-card {
  background: #ffffff;
  border: 1px solid #eef2f6;
  border-radius: 8px;
  padding: 12px;
  cursor: grab;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
  position: relative;
  text-decoration: none;
  display: flex;
  flex-direction: column;
  color: inherit;
  user-select: none;
}
.cand-card:hover {
  border-color: #cbd5e1;
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
}
.cand-card.is-dragging {
  opacity: 0.35;
  cursor: grabbing;
}
.cand-card.is-dragging * {
  pointer-events: none;
}
.cand-card-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 8px;
  margin-bottom: 2px;
}
.cand-name {
  font-size: 13px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.3;
  text-decoration: none;
}
.cand-name:hover {
  color: #6f45ff;
}
.cand-score {
  font-size: 10.5px;
  font-weight: 700;
  color: #6f45ff;
  background: #f5f3ff;
  padding: 2px 5px;
  border-radius: 4px;
  white-space: nowrap;
}
.cand-role {
  font-size: 11.5px;
  color: #64748b;
  margin-bottom: 8px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Minimal Slot Badge */
.slot-badge-confirmed {
  background: #ecfdf5;
  color: #047857;
  border: 1px solid #a7f3d0;
  border-radius: 5px;
  padding: 4px 7px;
  font-size: 11px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 8px;
  align-self: flex-start;
}
.slot-badge-awaiting {
  background: #fffbeb;
  color: #b45309;
  border: 1px solid #fde68a;
  border-radius: 5px;
  padding: 4px 7px;
  font-size: 11px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 8px;
  align-self: flex-start;
}

/* Card Action Row */
.cand-actions-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 4px;
  padding-top: 8px;
  border-top: 1px solid #f8fafc;
}
.cand-email-text {
  font-size: 11px;
  color: #94a3b8;
  max-width: 130px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.mail-action-btn {
  font-size: 11px;
  font-weight: 600;
  color: #6f45ff;
  background: #f5f3ff;
  border: 1px solid #ede9fe;
  border-radius: 5px;
  padding: 3px 8px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  cursor: pointer;
  transition: all 0.15s ease;
}
.mail-action-btn:hover {
  background: #6f45ff;
  color: #ffffff;
  border-color: #6f45ff;
}

/* Modal Styling */
.lifecycle-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.65);
  backdrop-filter: blur(4px);
  z-index: 1200;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.2s ease;
}
.lifecycle-modal-backdrop.active {
  opacity: 1;
  pointer-events: auto;
}
.lifecycle-modal-card {
  width: 100%;
  max-width: 980px;
  max-height: 92vh;
  background: #ffffff;
  border-radius: 12px;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.modal-header-bar {
  padding: 16px 22px;
  background: #ffffff;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.modal-header-bar h3 {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
  display: flex;
  align-items: center;
  gap: 8px;
}
.modal-close-btn {
  background: transparent;
  border: none;
  font-size: 18px;
  color: #94a3b8;
  cursor: pointer;
  border-radius: 6px;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.modal-close-btn:hover {
  background: #f1f5f9;
  color: #0f172a;
}
.modal-body-grid {
  display: grid;
  grid-template-columns: 46% 54%;
  flex: 1;
  overflow: hidden;
  min-height: 480px;
}
@media (max-width: 820px) {
  .modal-body-grid { grid-template-columns: 1fr; overflow-y: auto; }
}
.modal-composer {
  padding: 20px;
  border-right: 1px solid #e2e8f0;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.modal-preview-pane {
  background: #f8fafc;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.preview-pane-header {
  padding: 10px 18px;
  background: #ffffff;
  border-bottom: 1px solid #e2e8f0;
  font-size: 11.5px;
  font-weight: 700;
  color: #64748b;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.preview-pane-iframe {
  flex: 1;
  width: 100%;
  height: 100%;
  border: 0;
  background: #f8fafc;
}
.modal-footer-bar {
  padding: 14px 22px;
  background: #ffffff;
  border-top: 1px solid #e2e8f0;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.modal-footer-right {
  display: flex;
  align-items: center;
  gap: 10px;
}
.btn-primary-send {
  background: #6f45ff;
  color: #ffffff;
  border: none;
  border-radius: 6px;
  padding: 9px 18px;
  font-size: 12.5px;
  font-weight: 700;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: background 0.15s ease;
}
.btn-primary-send:hover {
  background: #5d34ef;
}
.btn-primary-send:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.btn-move-only {
  background: #f8fafc;
  color: #475569;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 9px 16px;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s ease;
}
.btn-move-only:hover {
  background: #f1f5f9;
  color: #0f172a;
}
.toast-msg {
  position: fixed;
  bottom: 24px;
  right: 24px;
  background: #0f172a;
  color: #ffffff;
  padding: 10px 18px;
  border-radius: 8px;
  font-size: 12.5px;
  font-weight: 600;
  box-shadow: 0 10px 25px rgba(0,0,0,0.15);
  z-index: 1000;
  display: flex;
  align-items: center;
  gap: 8px;
  animation: toastIn 0.2s ease;
}
@keyframes toastIn {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Candidate Sliding Drawer / Overlay */
.drawer-shade {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  backdrop-filter: blur(3px);
  z-index: 1050;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.22s ease;
}
.drawer-shade.open {
  opacity: 1;
  pointer-events: auto;
}
.candidate-drawer {
  position: fixed;
  right: 0;
  top: 0;
  bottom: 0;
  width: min(620px, 94vw);
  background: #ffffff;
  z-index: 1060;
  box-shadow: -20px 0 60px rgba(15, 23, 42, 0.2);
  transform: translateX(102%);
  transition: transform 0.24s cubic-bezier(0.16, 1, 0.3, 1);
  display: flex;
  flex-direction: column;
}
.candidate-drawer.open {
  transform: translateX(0);
}
.drawer-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  padding: 18px 22px;
  border-bottom: 1px solid #eef2f6;
  background: linear-gradient(135deg, #ffffff 40%, #f5f3ff 100%);
}
.drawer-rank-tag {
  font-size: 10.5px;
  font-weight: 700;
  color: #6f45ff;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.drawer-close-btn {
  width: 32px;
  height: 32px;
  border: 1px solid #e2e8f0;
  background: #ffffff;
  border-radius: 8px;
  color: #64748b;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
  transition: all 0.15s ease;
}
.drawer-close-btn:hover {
  background: #f1f5f9;
  color: #0f172a;
}

/* Speedometer Gauge in Drawer */
.drawer-score {
  display: grid;
  grid-template-columns: 175px 1fr;
  gap: 16px;
  align-items: center;
  padding: 16px 22px;
  border-bottom: 1px solid #f1f5f9;
  background: #ffffff;
}
.gauge {
  position: relative;
  width: 170px;
  height: 90px;
  overflow: hidden;
}
.gauge-ring {
  position: absolute;
  width: 170px;
  height: 170px;
  border-radius: 50%;
  background: conic-gradient(from 270deg, #ff4b2b 0 18%, #ffac12 18% 35%, #ffe600 35% 50%, #9bd000 50% 72%, #17ac16 72% 100%);
  clip-path: inset(0 0 50%);
}
.gauge-cut {
  position: absolute;
  left: 26px;
  top: 26px;
  width: 118px;
  height: 118px;
  background: #ffffff;
  border-radius: 50%;
}
.gauge-needle {
  position: absolute;
  left: 85px;
  bottom: 6px;
  width: 62px;
  height: 3px;
  background: #6f45ff;
  transform-origin: left;
  transform: rotate(var(--needle, -180deg));
  transition: transform 0.5s ease;
}
.gauge-needle:before {
  content: '';
  position: absolute;
  left: -5px;
  top: -4px;
  width: 11px;
  height: 11px;
  border-radius: 50%;
  background: #6f45ff;
}
.gauge-value {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  text-align: center;
  font-size: 22px;
  font-weight: 800;
  color: #0f172a;
}
.score-copy strong {
  display: block;
  font-size: 13px;
  font-weight: 700;
  color: #0f172a;
  margin-bottom: 3px;
}
.score-copy p {
  margin: 0;
  font-size: 11px;
  color: #64748b;
  line-height: 1.5;
}

/* Drawer Tabs */
.drawer-tabs {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  padding: 6px 14px;
  gap: 4px;
  background: #fafbfe;
  border-bottom: 1px solid #eef2f6;
}
.drawer-tabs button {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  padding: 8px 4px;
  border: 1px solid transparent;
  border-radius: 6px;
  font-size: 11px;
  color: #64748b;
  font-weight: 600;
  background: transparent;
  cursor: pointer;
  transition: all 0.15s ease;
  white-space: nowrap;
}
.drawer-tabs button.active {
  background: #f0edff;
  border-color: #d9ceff;
  color: #6f45ff;
  font-weight: 700;
}

/* Drawer Panes & Content */
.drawer-body {
  padding: 18px 22px;
  overflow-y: auto;
  flex: 1;
}
.drawer-pane {
  display: none;
}
.drawer-pane.active {
  display: block;
}
.detail-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.detail-card {
  padding: 10px 12px;
  border: 1px solid #eef2f6;
  border-radius: 8px;
  background: #ffffff;
}
.detail-card small {
  display: block;
  font-size: 10px;
  color: #94a3b8;
  margin-bottom: 3px;
  font-weight: 700;
  text-transform: uppercase;
}
.detail-card strong {
  font-size: 12.5px;
  color: #0f172a;
}
.overview-section {
  margin-top: 16px;
  padding-top: 14px;
  border-top: 1px solid #f1f5f9;
}
.overview-section h3 {
  margin: 0 0 10px;
  font-size: 12px;
  font-weight: 700;
  color: #1e293b;
}

/* Chips & Badges */
.chips { display: flex; flex-wrap: wrap; gap: 6px; }
.chip { padding: 4px 8px; border-radius: 5px; background: #ecfdf5; color: #047857; font-size: 10.5px; font-weight: 600; }
.chip.missing { background: #fee2e2; color: #b91c1c; }
.chip.extra { background: #eff6ff; color: #1d4ed8; }

.gap-highlight {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  padding: 10px 12px;
  border: 1px solid #fde68a;
  border-radius: 8px;
  background: #fffbeb;
  color: #92400e;
  font-size: 11px;
}
.gap-highlight.clear { border-color: #a7f3d0; background: #ecfdf5; color: #047857; }
.gap-highlight.old-flag { border-color: #fecaca; background: #fee2e2; color: #b91c1c; }

.composition-list { display: grid; gap: 8px; }
.composition-row { display: grid; grid-template-columns: 120px minmax(0,1fr) 40px; gap: 8px; align-items: center; font-size: 11px; }
.composition-track, .skill-track { height: 7px; border-radius: 4px; background: #ececf3; overflow: hidden; }
.composition-track i { display: block; height: 100%; background: linear-gradient(90deg, #6f45ff, #8d63ff); }
.skill-graph { display: grid; gap: 8px; }
.skill-graph-row { display: grid; grid-template-columns: 110px minmax(0,1fr) 40px; gap: 8px; align-items: center; font-size: 11px; }
.skill-track i { display: block; height: 100%; background: linear-gradient(90deg, #6f45ff, #10b981); }

.career-timeline { position: relative; margin-left: 6px; padding-left: 18px; border-left: 2px solid #ede9fe; }
.career-event { position: relative; padding: 0 0 16px; }
.career-event:before { content: ""; position: absolute; left: -24px; top: 3px; width: 10px; height: 10px; border-radius: 50%; background: #6f45ff; box-shadow: 0 0 0 4px #f5f3ff; }
.career-event strong { display: block; margin-bottom: 2px; font-size: 12px; color: #0f172a; }
.career-event p { margin: 0; color: #64748b; font-size: 11px; line-height: 1.5; }

/* Timeline & Notes Styling */
.notes-add-box {
  background: #fbfaff;
  border: 1px solid #ede9fe;
  border-radius: 10px;
  padding: 14px;
  margin-bottom: 18px;
}
.notes-add-box h4 {
  margin: 0 0 10px;
  font-size: 12.5px;
  font-weight: 700;
  color: #6f45ff;
  display: flex;
  align-items: center;
  gap: 6px;
}
.note-item-card {
  background: #ffffff;
  border: 1px solid #eef2f6;
  border-radius: 8px;
  padding: 12px 14px;
  margin-bottom: 12px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.02);
}
.note-item-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 6px;
}
.note-author {
  font-size: 12px;
  font-weight: 700;
  color: #0f172a;
}
.note-time {
  font-size: 10.5px;
  color: #94a3b8;
}
.note-stage-badge {
  font-size: 10px;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 4px;
  background: #f1f5f9;
  color: #475569;
}
.note-feedback {
  font-size: 12px;
  color: #334155;
  line-height: 1.5;
  margin: 4px 0;
  white-space: pre-wrap;
}
.note-strengths {
  font-size: 11px;
  color: #047857;
  background: #ecfdf5;
  border-radius: 4px;
  padding: 4px 8px;
  margin-top: 6px;
  display: inline-block;
}

.drawer-footer-actions {
  display: flex;
  gap: 8px;
  padding: 14px 22px;
  border-top: 1px solid #eef2f6;
  background: #ffffff;
  align-items: center;
}
.drawer-footer-actions .btn-primary {
  margin-left: auto;
}
</style>
CSS;

require __DIR__ . '/views/partials/header.php';
?>

<!-- Header & Filter Toolbar -->
<section class="pipeline-top-header">
  <div class="header-meta-wrap">
    <h1>
      <?=htmlspecialchars($jobName)?> Pipeline
    </h1>
    <p>
      <?=$activeTotal?> in active interview funnel · Filter by job or batch
    </p>
  </div>

  <div class="filters-cluster">
    
    <!-- View Switcher -->
    <div class="view-switch">
      <a href="pipeline.php?job_id=<?=$jobId?>&batch_id=<?=$batchId?>&view=active" class="view-switch-btn <?=$viewMode === 'active' ? 'active' : ''?>">
        <i class="fa-solid fa-users-viewfinder"></i> Active (<?=$activeTotal?>)
      </a>
      <a href="pipeline.php?job_id=<?=$jobId?>&batch_id=<?=$batchId?>&view=pool" class="view-switch-btn <?=$viewMode === 'pool' ? 'active' : ''?>">
        <i class="fa-solid fa-inbox"></i> Raw Pool (<?=$poolTotal?>)
      </a>
    </div>

    <!-- Filter by Job -->
    <div class="filter-box">
      <label for="pipelineJob">Job</label>
      <select id="pipelineJob" class="pipeline-select" onchange="onFilterChanged()">
        <option value="0">All Jobs (<?=count($rawCandidates)?>)</option>
        <?php foreach ($jobs as $j): ?>
          <option value="<?=(int)$j['id']?>" <?=$jobId === (int)$j['id'] ? 'selected' : ''?>>
            <?=htmlspecialchars($j['title'])?> (<?=(int)$j['applicant_count']?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Filter by Interview Batch (Eliminates Clutter) -->
    <div class="filter-box">
      <label for="pipelineBatch">Batch</label>
      <select id="pipelineBatch" class="pipeline-select" onchange="onFilterChanged()">
        <option value="0">All Batches (<?=count($batches)?>)</option>
        <?php foreach ($batches as $b): ?>
          <option value="<?=(int)$b['id']?>" <?=$batchId === (int)$b['id'] ? 'selected' : ''?>>
            <?=htmlspecialchars($b['name'])?> (<?=(int)$b['invite_count']?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

  </div>
</section>

<?php if ($viewMode === 'active'): ?>

  <!-- 5 Active Interview Stages Kanban Board -->
  <div class="kanban-board-wrapper">
    <section class="kanban-board">
      <?php foreach ($activeColumns as $stageKey => $meta): 
        $group = $meta['items'];
      ?>
        <article class="kanban-col" data-stage="<?=$stageKey?>">
          <div class="kanban-col-head">
            <div class="col-title-wrap">
              <span class="col-dot" style="background:<?=$meta['color']?>;"></span>
              <strong><?=$meta['label']?></strong>
            </div>
            <span class="kanban-badge count" id="count-<?=str_replace(' ', '_', $stageKey)?>"><?=count($group)?></span>
          </div>

          <div class="kanban-dropzone" data-stage="<?=$stageKey?>">
            <?php foreach ($group as $c): 
              $d = candidatePresentation($c);
              $candScore = (int)($c['score'] ?? 0);
              $hasSlot = !empty($c['slot_starts_at']);
            ?>
              <div class="cand-card" 
                   draggable="true" 
                   data-id="<?=htmlspecialchars($c['id'])?>" 
                   data-name="<?=htmlspecialchars($d['name'])?>"
                   data-email="<?=htmlspecialchars($c['email'] ?? '')?>"
                   data-job="<?=htmlspecialchars($c['job_title'] ?? $c['role_title'] ?? 'Candidate')?>"
                   data-stage="<?=$stageKey?>"
                   onclick="openCandidateDrawer('<?=htmlspecialchars($c['id'], ENT_QUOTES)?>')">
                
                <div class="cand-card-head">
                  <span class="cand-name">
                    <?=htmlspecialchars($d['name'])?>
                  </span>
                  <?php if ($candScore > 0): ?>
                    <span class="cand-score"><?=$candScore?>%</span>
                  <?php endif; ?>
                </div>

                <div class="cand-role">
                  <?=htmlspecialchars($c['job_title'] ?? $d['role'])?>
                </div>

                <!-- Interview Slot Badge if available -->
                <?php if ($hasSlot): ?>
                  <div class="slot-badge-confirmed">
                    <i class="fa-regular fa-calendar-check"></i>
                    <span><?=date('d M, h:i A', strtotime($c['slot_starts_at']))?></span>
                  </div>
                <?php elseif ($stageKey === 'Invited'): ?>
                  <div class="slot-badge-awaiting">
                    <i class="fa-regular fa-clock"></i>
                    <span>Awaiting slot pick</span>
                  </div>
                <?php endif; ?>

                <div class="cand-actions-row">
                  <span class="cand-email-text" title="<?=htmlspecialchars($c['email'] ?: 'No email')?>">
                    <i class="fa-regular fa-envelope"></i> <?=htmlspecialchars($c['email'] ?: 'No email')?>
                  </span>
                  
                  <button type="button" class="mail-action-btn" onclick="openStageModal('<?=htmlspecialchars($c['id'], ENT_QUOTES)?>', '<?=$stageKey?>', event)">
                    <i class="fa-solid fa-paper-plane"></i> Stage Mail
                  </button>
                </div>

              </div>
            <?php endforeach; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  </div>

<?php else: ?>

  <!-- Raw Applicant Pool (Inbox of Uncontacted Resumes) -->
  <section style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.03);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <div>
        <h2 style="margin:0; font-size:18px; font-weight:800; color:#0f172a;">Raw Applicant Pool (<?=$poolTotal?> candidates)</h2>
        <p style="margin:2px 0 0; font-size:13px; color:#64748b;">Uncontacted applications awaiting screening or interview invite batch.</p>
      </div>
      <div>
        <a href="interview_invite.php?job_id=<?=$jobId?>" class="btn" style="background:#7c3aed; color:#fff; font-weight:700;">
          <i class="fa-solid fa-paper-plane"></i> Create Interview Invite Batch
        </a>
      </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:12px;">
      <?php foreach ($poolItems as $c): 
        $d = candidatePresentation($c);
        $candScore = (int)($c['score'] ?? 0);
      ?>
        <div class="cand-card" onclick="openCandidateDrawer('<?=htmlspecialchars($c['id'], ENT_QUOTES)?>')" style="cursor:pointer;">
          <div class="cand-card-head">
            <span class="cand-name">
              <?=htmlspecialchars($d['name'])?>
            </span>
            <?php if ($candScore > 0): ?>
              <span class="cand-score"><?=$candScore?>%</span>
            <?php endif; ?>
          </div>

          <div class="cand-role">
            <?=htmlspecialchars($c['job_title'] ?? $d['role'])?>
          </div>

          <div class="cand-meta">
            <span><?=htmlspecialchars($c['source_name'] ?? 'Website')?></span>
            <span><?=htmlspecialchars(substr($c['created_at'], 0, 10))?></span>
          </div>

          <div class="cand-actions-row">
            <span style="font-size:11px; color:#64748b;">
              <i class="fa-regular fa-envelope"></i> <?=htmlspecialchars($c['email'] ?: 'No email')?>
            </span>
            <button type="button" class="mail-action-btn" onclick="openStageModal('<?=htmlspecialchars($c['id'], ENT_QUOTES)?>', 'Invited', event)">
              <i class="fa-solid fa-paper-plane"></i> Invite to Interview
            </button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

<?php endif; ?>

<!-- Lifecycle Mail & Stage Transition Modal -->
<div class="lifecycle-modal-backdrop" id="stageModal">
  <div class="lifecycle-modal-card">
    
    <!-- Modal Header -->
    <div class="modal-header-bar">
      <div>
        <h3 id="modalCandidateTitle">
          <i class="fa-solid fa-paper-plane" style="color:#7c3aed;"></i>
          <span>Stage Transition & Branded Email</span>
        </h3>
        <p style="margin:2px 0 0; font-size:12px; color:#64748b;" id="modalCandidateSubtitle">
          Candidate: Jane Doe · Role: Fullstack Developer
        </p>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeStageModal()">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <!-- Modal Body (Composer Left, Live Preview Right) -->
    <div class="modal-body-grid">
      
      <!-- Left: Composer -->
      <div class="modal-composer">
        
        <div>
          <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:6px;">
            Target Stage & Template:
          </label>
          <select id="modalTemplateSelect" class="pipeline-select" style="width:100%;" onchange="onTemplateChanged()">
            <option value="welcome">Applied · Application Received (Welcome)</option>
            <option value="screening">Screening · Profile Shortlisted</option>
            <option value="round1" selected>Interview · Round 1 (Screening / Technical)</option>
            <option value="round2">Interview · Round 2 (Advanced / Leadership)</option>
            <option value="offer">Offer · Selection & Employment Offer</option>
            <option value="rejected">Rejected · Respectful Rejection</option>
          </select>
        </div>

        <div>
          <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:6px;">
            Recipient Email:
          </label>
          <input type="email" id="modalRecipientEmail" class="pipeline-select" style="width:100%;" readonly>
        </div>

        <div>
          <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:6px;">
            Subject Line:
          </label>
          <input type="text" id="modalSubject" class="pipeline-select" style="width:100%;" oninput="updateLivePreviewFromInputs()">
        </div>

        <div style="flex:1; display:flex; flex-direction:column;">
          <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:6px;">
            Custom Message / Candidate Email Remarks:
          </label>
          <textarea id="modalBodyText" 
                    style="flex:1; min-height:100px; width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; font-family:inherit; font-size:13px; line-height:1.6; resize:vertical; outline:none;" 
                    placeholder="Enter custom remarks or use template defaults..."
                    oninput="updateLivePreviewFromInputs()"></textarea>
          <small style="margin-top:4px; color:#64748b; font-size:11px;">
            <i class="fa-solid fa-circle-info"></i> Automatically renders with NonceBlox branding, logo, colors, and action button.
          </small>
        </div>

        <!-- Recruiter Round Evaluation & Notes Section -->
        <div style="background:#fbfaff; border:1px solid #ede9fe; border-radius:10px; padding:12px; margin-top:8px;">
          <div style="font-size:12px; font-weight:700; color:#6f45ff; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
            <i class="fa-solid fa-clipboard-check"></i> Interview Evaluation & Notes (Internal Timeline)
          </div>
          
          <label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">
            What happened / Round feedback ("Kya Hua"):
          </label>
          <textarea id="modalEvalFeedback" 
                    style="width:100%; min-height:55px; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; font-family:inherit; outline:none; resize:vertical;" 
                    placeholder="e.g. Candidate answered system design well, hands-on experience in React/PHP, communication clear..."></textarea>
          
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:8px;">
            <div>
              <label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">
                Key Strengths ("Kya Achha Tha"):
              </label>
              <input type="text" id="modalEvalStrengths" 
                     style="width:100%; height:32px; padding:0 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; outline:none;" 
                     placeholder="e.g. Problem solving, clean code, energy">
            </div>
            <div>
              <label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">
                Recommendation:
              </label>
              <select id="modalEvalRecommendation" style="width:100%; height:32px; padding:0 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; outline:none;">
                <option value="Advance">Advance to Next Round</option>
                <option value="Hire">Strong Hire / Select</option>
                <option value="Hold">Hold / Discuss</option>
                <option value="Reject">Reject</option>
              </select>
            </div>
          </div>
        </div>

      </div>

      <!-- Right: Live Preview Iframe -->
      <div class="modal-preview-pane">
        <div class="preview-pane-header">
          <span><i class="fa-solid fa-eye" style="color:#7c3aed;"></i> Live Branded Email Preview</span>
          <span style="font-size:11px; background:#e0f2fe; color:#0369a1; padding:2px 8px; border-radius:10px; font-weight:700;">
            NonceBlox Executive Theme
          </span>
        </div>
        <iframe id="modalPreviewIframe" class="preview-pane-iframe"></iframe>
      </div>

    </div>

    <!-- Modal Footer Actions -->
    <div class="modal-footer-bar">
      <div>
        <button type="button" class="btn-move-only" onclick="closeStageModal()">
          Cancel
        </button>
      </div>

      <div class="modal-footer-right">
        <button type="button" class="btn-move-only" id="btnMoveOnly" onclick="executeStageTransition(false)">
          <i class="fa-solid fa-arrows-turn-to-dots"></i> Move Stage Only (No Mail)
        </button>
        <button type="button" class="btn-primary-send" id="btnSendAndMove" onclick="executeStageTransition(true)">
          <i class="fa-solid fa-paper-plane"></i> Send Branded Email & Move
        </button>
      </div>
    </div>

  </div>
</div>

<!-- Candidate Detail Overlay Drawer -->
<div class="drawer-shade" id="drawerShade" onclick="closeCandidateDrawer()"></div>
<aside class="candidate-drawer" id="candidateDrawer" aria-label="Candidate Details Drawer">
  <!-- Drawer Head -->
  <div class="drawer-head">
    <div>
      <div class="drawer-rank-tag" id="drawerRank">Candidate Overview</div>
      <h2 id="drawerName" style="margin:4px 0 2px; font-size:20px; font-weight:800; color:#0f172a;">Candidate Name</h2>
      <div style="font-size:12px; color:#64748b;" id="drawerRole">Role · Stage</div>
    </div>
    <button type="button" class="drawer-close-btn" onclick="closeCandidateDrawer()" aria-label="Close Drawer">
      <i class="fa-solid fa-xmark"></i>
    </button>
  </div>

  <!-- Speedometer Gauge Match Score -->
  <div class="drawer-score">
    <div class="gauge">
      <div class="gauge-ring"></div>
      <div class="gauge-cut"></div>
      <div class="gauge-needle" id="gaugeNeedle" style="--needle:-180deg;"></div>
      <div class="gauge-value" id="gaugeValue">--/100</div>
    </div>
    <div class="score-copy">
      <strong>Job-specific match</strong>
      <p>Evidence v1: required skills, requirement-level experience, preferred skills, role similarity and evidence strength. Advisory only.</p>
    </div>
  </div>

  <!-- Drawer 5 Tabs -->
  <div class="drawer-tabs">
    <button type="button" class="active" id="tabBtnOverview" onclick="switchDrawerTab('overview', this)">
      <i class="fa-solid fa-gauge-high"></i> Overview
    </button>
    <button type="button" id="tabBtnEvidence" onclick="switchDrawerTab('evidence', this)">
      <i class="fa-solid fa-list-check"></i> Evidence
    </button>
    <button type="button" id="tabBtnCareer" onclick="switchDrawerTab('career', this)">
      <i class="fa-solid fa-briefcase"></i> Career
    </button>
    <button type="button" id="tabBtnNotes" onclick="switchDrawerTab('notes', this)">
      <i class="fa-solid fa-clipboard-user"></i> Timeline & Notes
    </button>
    <button type="button" id="tabBtnResume" onclick="switchDrawerTab('resume', this)">
      <i class="fa-solid fa-file-lines"></i> Resume Data
    </button>
  </div>

  <!-- Drawer Body Panes -->
  <div class="drawer-body">
    <!-- Pane 1: Overview -->
    <div class="drawer-pane active" id="paneOverview">
      <div style="text-align:center; padding:30px; color:#94a3b8;">
        <i class="fa-solid fa-circle-notch fa-spin"></i> Loading overview...
      </div>
    </div>

    <!-- Pane 2: Evidence -->
    <div class="drawer-pane" id="paneEvidence"></div>

    <!-- Pane 3: Career -->
    <div class="drawer-pane" id="paneCareer"></div>

    <!-- Pane 4: Notes & Timeline -->
    <div class="drawer-pane" id="paneNotes"></div>

    <!-- Pane 5: Resume -->
    <div class="drawer-pane" id="paneResume"></div>
  </div>

  <!-- Drawer Actions Footer -->
  <div class="drawer-actions">
    <button type="button" class="btn" onclick="closeCandidateDrawer()" style="background:#f1f5f9; color:#475569; font-weight:600; font-size:12px; padding:7px 14px; border-radius:6px; border:1px solid #e2e8f0; cursor:pointer;">
      Close
    </button>
    <a href="#" class="btn" id="drawerResumeLink" target="_blank" style="background:#ffffff; color:#334155; font-weight:600; font-size:12px; padding:7px 14px; border-radius:6px; border:1px solid #cbd5e1; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
      <i class="fa-solid fa-file-lines" style="color:#6f45ff;"></i> Resume
    </a>
    <button type="button" class="btn" id="drawerMoveStageBtn" style="background:#f5f3ff; color:#7c3aed; font-weight:700; font-size:12px; padding:7px 14px; border-radius:6px; border:1px solid #ddd6fe; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
      <i class="fa-solid fa-paper-plane"></i> Stage Mail / Move
    </button>
    <a href="#" class="btn btn-primary" id="drawerDetailLink" target="_blank" style="background:#6f45ff; color:#ffffff; font-weight:700; font-size:12px; padding:7px 16px; border-radius:6px; text-decoration:none; margin-left:auto; display:inline-flex; align-items:center; gap:6px;">
      Full profile & activity <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:10px;"></i>
    </a>
  </div>
</aside>

<script>
const CSRF_TOKEN = '<?=csrfToken()?>';
let activeCandidate = null;
let currentDraggedCard = null;
let originalColumn = null;
let targetDroppedStage = null;
let currentDrawerCandidate = null;

function onFilterChanged() {
  const jobId = document.getElementById('pipelineJob').value;
  const batchId = document.getElementById('pipelineBatch').value;
  const viewMode = '<?=$viewMode?>';
  location.href = `pipeline.php?job_id=${encodeURIComponent(jobId)}&batch_id=${encodeURIComponent(batchId)}&view=${encodeURIComponent(viewMode)}`;
}

// Setup Drag & Drop
document.querySelectorAll('.cand-card[draggable="true"]').forEach(card => {
  card.addEventListener('dragstart', (e) => {
    currentDraggedCard = card;
    originalColumn = card.closest('.kanban-col');
    card.classList.add('is-dragging');
    e.dataTransfer.setData('text/plain', card.dataset.id);
  });

  card.addEventListener('dragend', () => {
    card.classList.remove('is-dragging');
  });
});

document.querySelectorAll('.kanban-col').forEach(col => {
  let dragDepth = 0;

  col.addEventListener('dragenter', (e) => {
    e.preventDefault();
    dragDepth++;
    col.classList.add('drag-over');
  });

  col.addEventListener('dragover', (e) => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
  });

  col.addEventListener('dragleave', () => {
    dragDepth--;
    if (dragDepth <= 0) {
      dragDepth = 0;
      col.classList.remove('drag-over');
    }
  });

  col.addEventListener('drop', (e) => {
    e.preventDefault();
    dragDepth = 0;
    col.classList.remove('drag-over');
    if (!currentDraggedCard) return;

    targetDroppedStage = col.dataset.stage;
    const currentCardStage = currentDraggedCard.dataset.stage;

    if (targetDroppedStage === currentCardStage) return;

    // Move in DOM immediately for smooth visual feedback
    const dropzone = col.querySelector('.kanban-dropzone');
    dropzone.prepend(currentDraggedCard);
    updateColumnCounts();

    // Open Modal for Email preview and confirmation
    openStageModal(currentDraggedCard.dataset.id, targetDroppedStage);
  });
});

function updateColumnCounts() {
  document.querySelectorAll('.kanban-col').forEach(col => {
    const count = col.querySelectorAll('.cand-card').length;
    const badge = col.querySelector('.kanban-badge');
    if (badge) badge.textContent = count;
  });
}

// Stage Modal Logic
async function openStageModal(candidateId, targetStage, evt) {
  if (evt) evt.stopPropagation();

  targetDroppedStage = targetStage;
  const modal = document.getElementById('stageModal');
  modal.classList.add('active');

  // Reset evaluation note inputs for the new transition
  const evalFeedback = document.getElementById('modalEvalFeedback');
  const evalStrengths = document.getElementById('modalEvalStrengths');
  const evalRec = document.getElementById('modalEvalRecommendation');
  if (evalFeedback) evalFeedback.value = '';
  if (evalStrengths) evalStrengths.value = '';
  if (evalRec) {
    if (targetStage === 'Rejected') evalRec.value = 'Reject';
    else if (targetStage === 'Offer') evalRec.value = 'Hire';
    else evalRec.value = 'Advance';
  }

  // Map target stage to default lifecycle template
  const stageMap = {
    'Applied': 'welcome',
    'Screening': 'screening',
    'Invited': 'round1',
    'Round 1': 'round1',
    'Round 2': 'round2',
    'Offer': 'offer',
    'Rejected': 'rejected'
  };
  const defaultTpl = stageMap[targetStage] || 'round1';
  document.getElementById('modalTemplateSelect').value = defaultTpl;

  // Fetch payload from API
  try {
    const res = await fetch(`pipeline_lifecycle_api.php?action=get_preview&candidate_id=${encodeURIComponent(candidateId)}&stage=${encodeURIComponent(targetStage)}&template_key=${encodeURIComponent(defaultTpl)}`);
    const data = await res.json();

    if (!data.ok) throw new Error(data.error || 'Failed to load preview');

    activeCandidate = data.candidate;
    document.getElementById('modalCandidateTitle').innerHTML = `
      <i class="fa-solid fa-paper-plane" style="color:#7c3aed;"></i>
      <span>Move to <strong>${escapeHtml(targetStage)}</strong> & Send Branded Mail</span>
    `;
    document.getElementById('modalCandidateSubtitle').textContent = 
      `Candidate: ${activeCandidate.name} · Role: ${activeCandidate.job_title}`;

    document.getElementById('modalRecipientEmail').value = activeCandidate.email || 'No email on file';
    document.getElementById('modalSubject').value = data.subject;
    document.getElementById('modalBodyText').value = data.body_text;

    renderIframeHtml(data.html);

    // Disable send button if candidate has no email
    const sendBtn = document.getElementById('btnSendAndMove');
    if (!activeCandidate.email) {
      sendBtn.disabled = true;
      sendBtn.title = 'Candidate has no email address on file';
    } else {
      sendBtn.disabled = false;
      sendBtn.title = '';
    }

  } catch (err) {
    alert(err.message);
    closeStageModal();
  }
}

async function onTemplateChanged() {
  if (!activeCandidate) return;
  const tplKey = document.getElementById('modalTemplateSelect').value;

  try {
    const res = await fetch(`pipeline_lifecycle_api.php?action=get_preview&candidate_id=${encodeURIComponent(activeCandidate.id)}&stage=${encodeURIComponent(targetDroppedStage)}&template_key=${encodeURIComponent(tplKey)}`);
    const data = await res.json();

    if (data.ok) {
      document.getElementById('modalSubject').value = data.subject;
      document.getElementById('modalBodyText').value = data.body_text;
      renderIframeHtml(data.html);
    }
  } catch (e) {}
}

function updateLivePreviewFromInputs() {
  const iframe = document.getElementById('modalPreviewIframe');
  if (!iframe.contentDocument) return;

  const doc = iframe.contentDocument;
  const customMessage = document.getElementById('modalBodyText').value;

  const bodyHolder = doc.querySelector('div[style*="line-height:1.8"]') || doc.querySelector('div[style*="line-height: 1.8"]');
  if (bodyHolder && customMessage.trim() !== '') {
    bodyHolder.innerHTML = customMessage.replace(/\n/g, '<br>');
  }
}

function renderIframeHtml(html) {
  const iframe = document.getElementById('modalPreviewIframe');
  iframe.srcdoc = html;
}

function closeStageModal() {
  const modal = document.getElementById('stageModal');
  modal.classList.remove('active');

  // If user dropped a card but cancelled without moving, revert DOM position
  if (currentDraggedCard && originalColumn && currentDraggedCard.dataset.stage !== targetDroppedStage) {
    originalColumn.querySelector('.kanban-dropzone').prepend(currentDraggedCard);
    updateColumnCounts();
  }

  currentDraggedCard = null;
  originalColumn = null;
  targetDroppedStage = null;
}

// Execute Move & Email
async function executeStageTransition(sendEmail) {
  if (!activeCandidate || !targetDroppedStage) return;

  const sendBtn = document.getElementById('btnSendAndMove');
  const moveBtn = document.getElementById('btnMoveOnly');
  sendBtn.disabled = true;
  moveBtn.disabled = true;

  const templateKey = document.getElementById('modalTemplateSelect').value;
  const subject = document.getElementById('modalSubject').value;
  const messageText = document.getElementById('modalBodyText').value;

  const evalFeedback = document.getElementById('modalEvalFeedback') ? document.getElementById('modalEvalFeedback').value : '';
  const evalStrengths = document.getElementById('modalEvalStrengths') ? document.getElementById('modalEvalStrengths').value : '';
  const evalRecommendation = document.getElementById('modalEvalRecommendation') ? document.getElementById('modalEvalRecommendation').value : 'Advance';

  try {
    const response = await fetch('pipeline_lifecycle_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf: CSRF_TOKEN,
        candidate_id: activeCandidate.id,
        stage: targetDroppedStage,
        send_email: sendEmail ? 1 : 0,
        template_key: templateKey,
        subject: subject,
        message_text: messageText,
        eval_feedback: evalFeedback,
        eval_strengths: evalStrengths,
        eval_recommendation: evalRecommendation
      })
    });

    const res = await response.json();
    if (!res.ok) throw new Error(res.error || 'Operation failed');

    // Update dataset on card
    if (currentDraggedCard) {
      currentDraggedCard.dataset.stage = targetDroppedStage;
    }

    const modal = document.getElementById('stageModal');
    modal.classList.remove('active');

    // Show clean toast
    const msg = sendEmail 
      ? `✓ Candidate moved to ${targetDroppedStage} & branded email dispatched to ${activeCandidate.email}!`
      : `✓ Candidate stage updated to ${targetDroppedStage} (no email sent).`;
    showToast(msg);

    // If candidate drawer is currently open, refresh it so the timeline and stage reflect the change
    if (currentDrawerCandidate && currentDrawerCandidate.id === activeCandidate.id) {
      openCandidateDrawer(activeCandidate.id);
    }

  } catch (err) {
    alert('Error: ' + err.message);
    if (currentDraggedCard && originalColumn) {
      originalColumn.querySelector('.kanban-dropzone').prepend(currentDraggedCard);
      updateColumnCounts();
    }
  } finally {
    sendBtn.disabled = false;
    moveBtn.disabled = false;
    currentDraggedCard = null;
    originalColumn = null;
    targetDroppedStage = null;
  }
}

// ----------------------------------------------------
// CANDIDATE PROFILE OVERLAY DRAWER LOGIC
// ----------------------------------------------------

const esc = v => { let d = document.createElement('div'); d.textContent = v ?? ''; return d.innerHTML; };
const chips = (arr, type = '') => (arr && arr.length)
  ? '<div class="chips">' + arr.map(x => `<span class="chip ${type}">${esc(x)}</span>`).join('') + '</div>'
  : '<p style="color:#94a3b8; font-size:11px; margin:4px 0;">None recorded.</p>';

const label = key => String(key).replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());

const composition = c => {
  const rows = Object.entries(c.breakdown || {}).map(([k, val]) => {
    const max = Number(c.maximum?.[k] || 1);
    const pct = Math.round(Number(val) / max * 100);
    return `
      <div class="composition-row">
        <span>${esc(label(k))}</span>
        <div class="composition-track"><i style="width:${pct}%"></i></div>
        <b>${pct}%</b>
      </div>
    `;
  }).join('');
  return rows || '<p style="color:#94a3b8; font-size:11px;">Score composition is unavailable.</p>';
};

const gaps = c => {
  if (!(c.gaps || []).length) {
    return `
      <div class="gap-highlight clear">
        <i class="fa-solid fa-circle-check"></i>
        <div>
          <strong>No employment gap of 2+ months detected</strong><br>
          <small>Based only on reliably parsed dated roles.</small>
        </div>
      </div>
    `;
  }
  return '<div class="gap-list" style="display:grid; gap:8px;">' + c.gaps.map(g => `
    <div class="gap-highlight">
      <i class="fa-solid fa-triangle-exclamation"></i>
      <div>
        <strong>${Number(g.months) || 0} month gap</strong><br>
        <small>${esc(g.after || 'Unknown')} to ${esc(g.before || 'Unknown')}</small>
      </div>
    </div>
  `).join('') + '</div>';
};

const timeline = c => {
  if (!(c.jobs || []).length) {
    return '<p style="color:#94a3b8; font-size:11px;">No reliable dated employment timeline extracted.</p>';
  }
  return '<div class="career-timeline">' + c.jobs.map(j => `
    <div class="career-event">
      <strong>${esc(j.start || 'Unknown')} — ${esc(j.end || 'Unknown')}</strong>
      <p style="font-weight:600; color:#334155;">${esc(j.label || 'Employment record')}</p>
      <p>${Number(j.months || 0)} months${(j.skills || []).length ? ' · ' + esc(j.skills.join(', ')) : ''}</p>
    </div>
  `).join('') + '</div>';
};

const skillGraph = c => {
  const entries = Object.entries(c.skill_months || {});
  const max = Math.max(1, ...entries.map(([, m]) => Number(m)));
  if (!entries.length) {
    return '<p style="color:#94a3b8; font-size:11px;">No reliable skill tenure extracted from resume.</p>';
  }
  return '<div class="skill-graph">' + entries.map(([skill, m]) => `
    <div class="skill-graph-row">
      <span>${esc(skill)}</span>
      <div class="skill-track"><i style="width:${Math.round(Number(m) / max * 100)}%"></i></div>
      <b>${Math.round(Number(m) / 12 * 10) / 10}y</b>
    </div>
  `).join('') + '</div>';
};

async function openCandidateDrawer(candidateId) {
  const shade = document.getElementById('drawerShade');
  const drawer = document.getElementById('candidateDrawer');
  shade.classList.add('open');
  drawer.classList.add('open');

  // Set initial loading state
  document.getElementById('drawerRank').textContent = 'Candidate Details';
  document.getElementById('drawerName').textContent = 'Loading details...';
  document.getElementById('drawerRole').textContent = '';
  document.getElementById('gaugeValue').textContent = '--/100';
  document.getElementById('gaugeNeedle').style.setProperty('--needle', '-180deg');

  document.getElementById('paneOverview').innerHTML = `
    <div style="text-align:center; padding:50px 20px; color:#94a3b8;">
      <i class="fa-solid fa-circle-notch fa-spin fa-2x" style="color:#6f45ff; margin-bottom:12px;"></i>
      <p style="font-size:13px; font-weight:600;">Fetching candidate intelligence & evaluation timeline...</p>
    </div>
  `;

  try {
    const res = await fetch(`pipeline_lifecycle_api.php?action=get_drawer&candidate_id=${encodeURIComponent(candidateId)}`);
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Failed to load candidate details.');

    const c = data.candidate;
    currentDrawerCandidate = c;

    // Header info
    document.getElementById('drawerName').textContent = c.name;
    document.getElementById('drawerRole').textContent = `${c.job} · ${c.stage}`;
    document.getElementById('drawerRank').innerHTML = c.old
      ? `<span style="color:#dc2626; font-weight:700;"><i class="fa-solid fa-triangle-exclamation"></i> 🚩 Old Application · ${esc(c.age)}</span>`
      : `Applied ${esc(c.age)}`;

    // Speedometer Needle & Score
    const scoreVal = Number(c.score) || 0;
    const needleDeg = -180 + (scoreVal * 1.8);
    document.getElementById('gaugeNeedle').style.setProperty('--needle', `${needleDeg}deg`);
    document.getElementById('gaugeValue').textContent = scoreVal > 0 ? `${scoreVal}/100` : 'Indexed';

    // Action buttons in footer
    const resumeLink = document.getElementById('drawerResumeLink');
    if (resumeLink) resumeLink.href = c.resume_url;

    const detailLink = document.getElementById('drawerDetailLink');
    if (detailLink) detailLink.href = c.detail_url;

    const moveBtn = document.getElementById('drawerMoveStageBtn');
    if (moveBtn) {
      moveBtn.onclick = () => {
        openStageModal(c.id, c.stage);
      };
    }

    // Render individual tab panes
    renderDrawerOverview(c);
    renderDrawerEvidence(c);
    renderDrawerCareer(c);
    renderDrawerNotes(c);
    renderDrawerResume(c);

  } catch (err) {
    document.getElementById('paneOverview').innerHTML = `
      <div style="padding:20px; color:#dc2626; background:#fee2e2; border-radius:8px;">
        <i class="fa-solid fa-triangle-exclamation"></i> ${esc(err.message)}
      </div>
    `;
  }
}

function closeCandidateDrawer() {
  const shade = document.getElementById('drawerShade');
  const drawer = document.getElementById('candidateDrawer');
  if (shade) shade.classList.remove('open');
  if (drawer) drawer.classList.remove('open');
}

function switchDrawerTab(tabName, btnEl) {
  document.querySelectorAll('.drawer-tabs button').forEach(b => b.classList.remove('active'));
  if (btnEl) btnEl.classList.add('active');

  document.querySelectorAll('.drawer-pane').forEach(p => p.classList.remove('active'));
  const targetPane = document.getElementById('pane' + tabName.charAt(0).toUpperCase() + tabName.slice(1));
  if (targetPane) targetPane.classList.add('active');
}

function renderDrawerOverview(c) {
  const oldFlag = c.old ? `
    <div class="gap-highlight old-flag" style="margin-bottom:14px;">
      <i class="fa-solid fa-triangle-exclamation"></i>
      <div>
        <strong>OLD APPLICATION FLAG (6+ months old)</strong><br>
        <small>Candidate registered ${esc(c.age)} (${esc(c.created_at)}). Details may be stale.</small>
      </div>
    </div>
  ` : '';

  const expYears = c.months ? (Math.round(c.months / 12 * 10) / 10 + ' years') : 'Uncertain';

  document.getElementById('paneOverview').innerHTML = `
    ${oldFlag}
    <div class="detail-grid">
      <div class="detail-card">
        <small>Current Stage</small>
        <strong>${esc(c.stage)}</strong>
      </div>
      <div class="detail-card">
        <small>Application Date</small>
        <strong>${esc(c.created_at || 'Unknown')}</strong>
      </div>
      <div class="detail-card">
        <small>Parsed Experience</small>
        <strong>${expYears}</strong>
      </div>
      <div class="detail-card">
        <small>Application Source</small>
        <strong>${esc(c.source)}</strong>
      </div>
      <div class="detail-card" style="grid-column: span 2;">
        <small>Compensation Expectations</small>
        <strong style="color:#059669;">${esc(c.ctc)}</strong>
      </div>
    </div>

    <section class="overview-section">
      <h3>Employment gaps</h3>
      ${gaps(c)}
    </section>

    <section class="overview-section">
      <h3>Score composition</h3>
      <div class="composition-list">
        ${composition(c)}
      </div>
    </section>
  `;
}

function renderDrawerEvidence(c) {
  document.getElementById('paneEvidence').innerHTML = `
    <div style="display:grid; gap:16px;">
      <section>
        <h3 style="font-size:12px; margin:0 0 6px; color:#1e293b; font-weight:700;">
          <i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Experience-backed requirements
        </h3>
        ${chips(c.matched)}
      </section>

      <section>
        <h3 style="font-size:12px; margin:0 0 6px; color:#1e293b; font-weight:700;">
          <i class="fa-solid fa-clipboard-question" style="color:#3b82f6;"></i> Needs recruiter review
        </h3>
        ${chips(c.review, 'extra')}
      </section>

      <section>
        <h3 style="font-size:12px; margin:0 0 6px; color:#1e293b; font-weight:700;">
          <i class="fa-solid fa-circle-xmark" style="color:#ef4444;"></i> Missing requirements
        </h3>
        ${chips(c.missing, 'missing')}
      </section>

      <section>
        <h3 style="font-size:12px; margin:0 0 6px; color:#1e293b; font-weight:700;">
          <i class="fa-solid fa-wand-magic-sparkles" style="color:#8b5cf6;"></i> Additional skills (not scored)
        </h3>
        ${chips(c.benefits, 'extra')}
      </section>
    </div>
  `;
}

function renderDrawerCareer(c) {
  document.getElementById('paneCareer').innerHTML = `
    <section class="overview-section" style="margin-top:0; padding-top:0; border-top:none;">
      <h3>Skill-wise parsed experience tenure</h3>
      ${skillGraph(c)}
    </section>

    <section class="overview-section">
      <h3>Employment timeline</h3>
      ${timeline(c)}
    </section>
  `;
}

function renderDrawerNotes(c) {
  const notes = c.notes || [];
  const activity = c.activity || [];

  const recColor = {
    'Advance': { bg: '#ecfdf5', text: '#065f46', border: '#a7f3d0', icon: 'fa-arrow-right' },
    'Hire': { bg: '#dcfce7', text: '#15803d', border: '#86efac', icon: 'fa-check' },
    'Hold': { bg: '#fef3c7', text: '#92400e', border: '#fde68a', icon: 'fa-pause' },
    'Reject': { bg: '#fee2e2', text: '#991b1b', border: '#fca5a5', icon: 'fa-xmark' }
  };

  const notesHtml = notes.length ? notes.map(n => {
    const rTheme = recColor[n.recommendation] || recColor['Advance'];
    return `
      <div class="note-item-card">
        <div class="note-item-head">
          <div style="display:flex; align-items:center; gap:8px;">
            <strong style="color:#0f172a; font-size:12.5px;">${esc(n.author_name || 'Recruiter')}</strong>
            <span class="note-stage-badge">${esc(n.stage)}</span>
            <span style="font-size:10px; font-weight:700; padding:2px 7px; border-radius:12px; background:${rTheme.bg}; color:${rTheme.text}; border:1px solid ${rTheme.border}; display:inline-flex; align-items:center; gap:4px;">
              <i class="fa-solid ${rTheme.icon}"></i> ${esc(n.recommendation)}
            </span>
          </div>
          <span class="note-date-text">
            <i class="fa-regular fa-clock"></i> ${esc(n.created_at)}
          </span>
        </div>

        <div style="font-size:12.5px; color:#334155; line-height:1.55; white-space:pre-wrap; margin-bottom:${n.strengths ? '8px' : '0'};">
          ${esc(n.feedback)}
        </div>

        ${n.strengths ? `
          <div style="display:inline-flex; align-items:center; gap:6px; font-size:11px; background:#f0fdf4; color:#166534; padding:3px 9px; border-radius:6px; border:1px solid #bbf7d0; font-weight:600;">
            <i class="fa-solid fa-star" style="color:#eab308;"></i> Strengths ("Kya achha tha"): ${esc(n.strengths)}
          </div>
        ` : ''}
      </div>
    `;
  }).join('') : `
    <div style="text-align:center; padding:20px; color:#94a3b8; background:#f8fafc; border-radius:8px; border:1px dashed #e2e8f0; font-size:12px;">
      <i class="fa-regular fa-note-sticky" style="font-size:20px; margin-bottom:6px; display:block;"></i>
      No round evaluation notes recorded yet for this candidate.<br>Use the form above or enter feedback during stage transitions.
    </div>
  `;

  const activityHtml = activity.length ? `
    <div style="margin-top:20px;">
      <h4 style="font-size:12px; font-weight:700; color:#475569; margin:0 0 10px; display:flex; align-items:center; gap:6px;">
        <i class="fa-solid fa-clock-rotate-left"></i> System Activity Timeline
      </h4>
      <div class="career-timeline">
        ${activity.map(a => `
          <div class="career-event">
            <strong>${esc(a.date || a.created_at || 'Event')}</strong>
            <p style="font-weight:600; color:#334155;">${esc(a.action || a.title || 'Candidate event')}</p>
            <p>${esc(a.details || a.description || '')}</p>
          </div>
        `).join('')}
      </div>
    </div>
  ` : '';

  document.getElementById('paneNotes').innerHTML = `
    <!-- Note Add Box -->
    <div class="notes-add-box">
      <h4>
        <i class="fa-solid fa-pen-to-square"></i> Add Round Evaluation Note ("Kya hua / Kya achha tha")
      </h4>

      <div style="margin-bottom:8px;">
        <label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">
          What happened / Round feedback ("Kya Hua"):
        </label>
        <textarea id="drawerNoteFeedback" rows="2" 
                  style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; font-family:inherit; outline:none; resize:vertical;" 
                  placeholder="e.g. Candidate explained system architecture well, answered Solidity reentrancy questions clearly, communication fluent..."></textarea>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:10px;">
        <div>
          <label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">
            Key Strengths ("Kya Achha Tha"):
          </label>
          <input type="text" id="drawerNoteStrengths" 
                 style="width:100%; height:32px; padding:0 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; outline:none;" 
                 placeholder="e.g. Clean coding, system design, high energy">
        </div>
        <div>
          <label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">
            Recommendation:
          </label>
          <select id="drawerNoteRecommendation" style="width:100%; height:32px; padding:0 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; outline:none;">
            <option value="Advance">Advance to Next Round</option>
            <option value="Hire">Strong Hire / Select</option>
            <option value="Hold">Hold / Discuss</option>
            <option value="Reject">Reject</option>
          </select>
        </div>
      </div>

      <div style="display:flex; justify-content:space-between; align-items:center;">
        <div style="font-size:11px; color:#64748b;">
          Recorded under stage: <strong>${esc(c.stage)}</strong>
        </div>
        <button type="button" id="btnSaveDrawerNote" onclick="submitDrawerNote()" 
                style="padding:6px 14px; font-size:12px; border-radius:6px; background:#6f45ff; color:#fff; font-weight:700; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-floppy-disk"></i> Save Note to Timeline
        </button>
      </div>
    </div>

    <!-- Chronological Notes Section -->
    <div>
      <h4 style="font-size:12px; font-weight:700; color:#475569; margin:0 0 10px; display:flex; align-items:center; gap:6px;">
        <i class="fa-solid fa-clipboard-check" style="color:#6f45ff;"></i> Evaluation History & Notes (${notes.length})
      </h4>
      <div style="display:grid; gap:8px;">
        ${notesHtml}
      </div>
    </div>

    ${activityHtml}
  `;
}

async function submitDrawerNote() {
  if (!currentDrawerCandidate) return;

  const feedback = (document.getElementById('drawerNoteFeedback').value || '').trim();
  const strengths = (document.getElementById('drawerNoteStrengths').value || '').trim();
  const recommendation = document.getElementById('drawerNoteRecommendation').value || 'Advance';
  const saveBtn = document.getElementById('btnSaveDrawerNote');

  if (!feedback) {
    alert('Please enter evaluation feedback ("Kya Hua") before saving.');
    document.getElementById('drawerNoteFeedback').focus();
    return;
  }

  saveBtn.disabled = true;
  saveBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Saving...';

  try {
    const res = await fetch('pipeline_lifecycle_api.php?action=add_note', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf: CSRF_TOKEN,
        candidate_id: currentDrawerCandidate.id,
        stage: currentDrawerCandidate.stage,
        feedback: feedback,
        strengths: strengths,
        recommendation: recommendation
      })
    });

    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Failed to save note');

    // Update local candidate notes and re-render notes pane
    currentDrawerCandidate.notes = data.notes;
    renderDrawerNotes(currentDrawerCandidate);
    showToast('✓ Evaluation note recorded to timeline with timestamp!');

  } catch (err) {
    alert('Error saving note: ' + err.message);
    saveBtn.disabled = false;
    saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Note to Timeline';
  }
}

function renderDrawerResume(c) {
  document.getElementById('paneResume').innerHTML = `
    <div class="detail-grid" style="margin-bottom:14px;">
      <div class="detail-card"><small>Email Address</small><strong>${esc(c.email)}</strong></div>
      <div class="detail-card"><small>Phone Number</small><strong>${esc(c.phone)}</strong></div>
      <div class="detail-card"><small>Country / Location</small><strong>${esc(c.country)}</strong></div>
      <div class="detail-card"><small>Source Attribution</small><strong>${esc(c.source)}</strong></div>
    </div>

    <section class="overview-section">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
        <h3 style="margin:0;">Extracted Resume Text</h3>
        <button type="button" class="btn" onclick="loadDrawerResumeText('${esc(c.id)}')" 
                style="font-size:11px; padding:4px 10px; border:1px solid #cbd5e1; border-radius:5px; background:#fff; cursor:pointer; color:#334155;">
          <i class="fa-solid fa-file-lines"></i> View Raw Extracted Text
        </button>
      </div>
      <div id="drawerResumeTextHolder" class="resume-text-state">
        Click "View Raw Extracted Text" to inspect the complete extracted resume text or click "Resume" in the footer to download the original document.
      </div>
    </section>
  `;
}

async function loadDrawerResumeText(candidateId) {
  const holder = document.getElementById('drawerResumeTextHolder');
  if (!holder) return;

  holder.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Extracting resume text...';

  try {
    const res = await fetch(`candidate_drawer_api.php?id=${encodeURIComponent(candidateId)}`);
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Failed to extract resume text.');

    holder.outerHTML = `<div class="resume-raw" id="drawerResumeTextHolder">${esc(data.text)}</div>`;
  } catch (err) {
    holder.innerHTML = `<span style="color:#dc2626;"><i class="fa-solid fa-triangle-exclamation"></i> ${esc(err.message)}</span>`;
  }
}

// Global Keyboard shortcuts (Escape closes drawer and modal)
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    closeCandidateDrawer();
    closeStageModal();
  }
});

function showToast(text) {
  const toast = document.createElement('div');
  toast.className = 'toast-msg';
  toast.innerHTML = `<i class="fa-solid fa-circle-check" style="color:#4ade80;"></i> ${escapeHtml(text)}`;
  document.body.appendChild(toast);
  setTimeout(() => {
    toast.style.transition = 'opacity 0.3s ease';
    toast.style.opacity = '0';
    setTimeout(() => toast.remove(), 300);
  }, 4000);
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
