<?php
require_once __DIR__.'/lib/auth.php';
requireAuth();
require_once __DIR__.'/lib/workspace.php';
require_once __DIR__.'/secrets.php';
require_once __DIR__.'/lib/interview_calendar_links.php';

$pdo=db();$user=currentUser();
$jobId=(int)($_GET['job_id']??0);
$status=trim((string)($_GET['status']??'all'));
$allowed=['all','confirmed','awaiting_confirmation','completed'];
if(!in_array($status,$allowed,true))$status='all';

// Fetch user's job profiles for filter dropdown
$jobs=$pdo->prepare('SELECT DISTINCT j.id,j.title FROM interview_batches ib JOIN jobs j ON j.id=ib.job_id WHERE ib.created_by=? ORDER BY j.title');
$jobs->execute([$user['id']]);
$jobs=$jobs->fetchAll();

$params=[$user['id']];
$where='';
if($jobId){$where.=' AND ib.job_id=?';$params[]=$jobId;}
if($status==='confirmed')$where.=" AND ii.status='confirmed' AND ii.outcome IS NULL";
elseif($status==='awaiting_confirmation')$where.=" AND ii.status='awaiting_confirmation'";
elseif($status==='completed')$where.=' AND ii.outcome IS NOT NULL';

$stmt=$pdo->prepare("SELECT ii.id,ii.status,ii.recipient_email,ii.notification_status,ii.outcome,ii.outcome_notes,
  c.id candidate_id, c.name candidate_name, j.title job_title,
  ib.availability_start, ib.availability_end, ib.timezone,
  s.id slot_id, s.starts_at, s.ends_at
  FROM interview_invitations ii
  JOIN interview_batches ib ON ib.id=ii.batch_id
  JOIN candidates c ON c.id=ii.candidate_id
  JOIN jobs j ON j.id=ib.job_id
  LEFT JOIN interview_slots s ON s.id=ii.confirmed_slot_id
  WHERE ib.created_by=?$where
  ORDER BY COALESCE(s.starts_at,CONCAT(ib.availability_start,' 23:59:59')),c.name");
$stmt->execute($params);
$items=$stmt->fetchAll();

$confirmed=array_values(array_filter($items,fn($x)=>$x['status']==='confirmed'&&!$x['outcome']));
$waiting=array_values(array_filter($items,fn($x)=>$x['status']==='awaiting_confirmation'));
$decided=array_values(array_filter($items,fn($x)=>!empty($x['outcome'])));

$byDate=[];
$eventsMap=[];
foreach($confirmed as $item){
    if(!empty($item['starts_at'])){
        $dateKey=substr($item['starts_at'],0,10);
        $byDate[$dateKey][]=$item;
        
        $gCalUrl = interviewGoogleCalendarUrl($item, $item);
        $icsUrl = interviewCalendarDownloadUrl((int)$item['id']);
        
        $eventsMap[$dateKey][] = [
            'id' => (int)$item['id'],
            'title' => $item['candidate_name'],
            'job' => $item['job_title'],
            'email' => $item['recipient_email'],
            'candidate_id' => $item['candidate_id'],
            'time' => date('g:i A', strtotime($item['starts_at'])),
            'starts_at' => $item['starts_at'],
            'gcal_url' => $gCalUrl,
            'ics_url' => $icsUrl,
            'notification_status' => $item['notification_status']
        ];
    }
}

$feedToken = hash('sha256', $user['id'].$user['email'].appSecretKey());
$scheme = (!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
$host = $_SERVER['HTTP_HOST']??'localhost';
$feedUrl = $scheme.'://'.$host.'/interview_calendar_feed.php?token='.$feedToken;

$labels=[
  'completed'=>'Completed',
  'no_show'=>'No show',
  'selected'=>'Selected',
  'rejected'=>'Rejected',
  'offer_sent'=>'Offer sent',
  'offer_accepted'=>'Offer accepted',
  'offer_declined'=>'Offer declined',
  'hired'=>'Hired'
];

$activePage='calendar';
$pageTitle='Interview Calendar · ResumeIQ';

$pageStyles='<style>
.calendar-layout { display: grid; grid-template-columns: 340px minmax(0, 1fr); gap: 20px; align-items: start; margin-top: 14px; }

/* LEFT MINI CALENDAR & SYNC CARD */
.left-panel { display: flex; flex-direction: column; gap: 16px; position: sticky; top: 80px; }
.mini-cal-card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 18px; box-shadow: 0 4px 16px rgba(0,0,0,0.02); }
.mini-cal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
.mini-cal-header h3 { margin: 0; font-size: 15px; font-weight: 800; color: #111827; }
.mini-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center; }
.mini-day-name { font-size: 11px; font-weight: 800; color: #9ca3af; padding-bottom: 6px; }
.mini-day-cell { height: 36px; border-radius: 10px; display: flex; flex-direction: column; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: #374151; cursor: pointer; transition: all 0.15s ease; position: relative; background: #fafafa; border: 1px solid #f3f4f6; }
.mini-day-cell:hover { background: #eeefee; color: #4338ca; border-color: #c7d2fe; }
.mini-day-cell.active-day { background: #4338ca; color: #ffffff; border-color: #4338ca; font-weight: 800; box-shadow: 0 2px 6px rgba(67,56,202,0.25); }
.mini-day-cell.other-month { opacity: 0.3; background: transparent; border-color: transparent; }
.mini-day-dot { width: 5px; height: 5px; background: #6366f1; border-radius: 50%; position: absolute; bottom: 3px; }
.mini-day-cell.active-day .mini-day-dot { background: #ffffff; }

.sync-card { background: linear-gradient(135deg, #f8f7ff 0%, #f1ecff 100%); border: 1px solid #e0e7ff; border-radius: 16px; padding: 18px; text-align: center; }
.sync-card h4 { margin: 0 0 6px; font-size: 14px; font-weight: 800; color: #1e1b4b; }
.sync-card p { margin: 0 0 12px; font-size: 12px; color: #6b7280; line-height: 1.4; }

/* RIGHT WORKSPACE PANEL */
.right-panel { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 22px; box-shadow: 0 4px 16px rgba(0,0,0,0.02); min-height: 540px; }
.workspace-head { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e5e7eb; padding-bottom: 16px; margin-bottom: 18px; flex-wrap: wrap; gap: 12px; }
.workspace-head h2 { margin: 0; font-size: 20px; font-weight: 800; color: #111827; letter-spacing: -0.02em; }

.tab-bar { display: flex; gap: 8px; margin-bottom: 18px; border-bottom: 1px solid #f3f4f6; padding-bottom: 10px; overflow-x: auto; }
.tab-btn { padding: 7px 16px; border-radius: 20px; font-size: 12px; font-weight: 700; color: #6b7280; background: #f9fafb; border: 1px solid #e5e7eb; cursor: pointer; transition: all 0.15s ease; white-space: nowrap; }
.tab-btn:hover { background: #f3f4f6; color: #374151; }
.tab-btn.active { background: #4338ca; color: #ffffff; border-color: #4338ca; box-shadow: 0 2px 8px rgba(67,56,202,0.2); }

.date-section { margin-bottom: 20px; }
.date-section-title { font-size: 12px; font-weight: 800; text-transform: uppercase; color: #6b7280; letter-spacing: 0.05em; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }

.meeting-card { background: #ffffff; border: 1px solid #e5e7eb; border-left: 4px solid #6366f1; border-radius: 12px; padding: 16px; margin-bottom: 10px; transition: all 0.2s ease; }
.meeting-card:hover { border-color: #cbd5e1; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
.meeting-header { display: flex; justify-content: space-between; align-items: start; gap: 12px; flex-wrap: wrap; }
.meeting-info strong { font-size: 15px; color: #111827; }
.meeting-info small { display: block; color: #6b7280; font-size: 12px; margin-top: 3px; }

.time-pill { font-size: 11px; font-weight: 800; background: #eeefee; color: #4338ca; padding: 3px 8px; border-radius: 10px; display: inline-block; }
.action-links { display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap; }
.gcal-btn { display: inline-flex; align-items: center; gap: 6px; padding: 5px 11px; border-radius: 8px; background: #ffffff; border: 1px solid #d1d5db; color: #374151; font-size: 11px; font-weight: 700; text-decoration: none; transition: all 0.15s ease; }
.gcal-btn:hover { background: #f9fafb; border-color: #9ca3af; color: #111827; }

.outcome-form { display: grid; grid-template-columns: 160px 1fr auto; gap: 8px; margin-top: 12px; padding-top: 12px; border-top: 1px dashed #f3f4f6; }
.badge { padding: 4px 10px; border-radius: 12px; background: #eeefee; color: #4338ca; font-size: 11px; font-weight: 800; display: inline-block; }
.badge.failed { background: #fee2e2; color: #dc2626; }
.waiting-card, .decision-card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; margin-bottom: 10px; background: #fff; }
.waiting-card { border-left: 4px solid #f59e0b; }
.decision-card { border-left: 4px solid #10b981; }

.notice { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; background: #dcfce7; color: #15803d; border: 1px solid #86efac; font-weight: 600; font-size: 13px; }

/* GOOGLE SYNC MODAL */
.modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.5); backdrop-filter: blur(4px); display: grid; place-items: center; z-index: 1000; padding: 20px; opacity: 0; pointer-events: none; transition: all 0.2s ease; }
.modal-overlay.active { opacity: 1; pointer-events: auto; }
.modal-card { background: #fff; border-radius: 16px; max-width: 500px; width: 100%; padding: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.15); transform: translateY(10px); transition: all 0.2s ease; }
.modal-overlay.active .modal-card { transform: translateY(0); }

@media(max-width: 900px) {
  .calendar-layout { grid-template-columns: 1fr; }
  .left-panel { position: static; }
  .outcome-form { grid-template-columns: 1fr; }
}
</style>';

require __DIR__.'/views/partials/header.php';
?>

<section class="page-head" style="padding:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px">
  <div>
    <div class="kicker" style="color:#6366f1;font-weight:800;font-size:12px;letter-spacing:0.05em;text-transform:uppercase;margin-bottom:4px">
      <i class="fa-regular fa-calendar-check"></i> Interview Schedule & Outreach
    </div>
    <h1 style="margin:0;font-size:26px;font-weight:900;color:#111827">Interview Calendar</h1>
    <p class="muted" style="margin:4px 0 0;font-size:13px;color:#6b7280">Manage upcoming interviews, track candidate confirmations, and sync seamlessly with Google Calendar.</p>
  </div>
  <form method="get" style="display:flex;gap:10px">
    <select class="control" name="job_id" onchange="this.form.submit()" style="font-size:13px;border-radius:10px;padding:8px 14px">
      <option value="0">All job profiles</option>
      <?php foreach($jobs as $job):?>
        <option value="<?=(int)$job['id']?>" <?=(int)$job['id']===$jobId?'selected':''?>><?=htmlspecialchars($job['title'])?></option>
      <?php endforeach?>
    </select>
  </form>
</section>

<?php if(isset($_GET['updated'])):?>
  <div class="notice"><i class="fa-solid fa-circle-check"></i> Interview outcome saved and pipeline updated.</div>
<?php endif?>

<div class="calendar-layout">
  <!-- LEFT COLUMN: MINI CALENDAR & GOOGLE SYNC CARD -->
  <aside class="left-panel">
    <!-- MINI MONTH PICKER -->
    <div class="mini-cal-card">
      <div class="mini-cal-header">
        <button type="button" class="btn" onclick="changeMiniMonth(-1)" style="border:0;background:transparent;font-size:12px;cursor:pointer"><i class="fa-solid fa-chevron-left"></i></button>
        <h3 id="miniMonthTitle">October 2026</h3>
        <button type="button" class="btn" onclick="changeMiniMonth(1)" style="border:0;background:transparent;font-size:12px;cursor:pointer"><i class="fa-solid fa-chevron-right"></i></button>
      </div>

      <div class="mini-cal-grid">
        <div class="mini-day-name">S</div>
        <div class="mini-day-name">M</div>
        <div class="mini-day-name">T</div>
        <div class="mini-day-name">W</div>
        <div class="mini-day-name">T</div>
        <div class="mini-day-name">F</div>
        <div class="mini-day-name">S</div>
      </div>
      <div class="mini-cal-grid" id="miniCalGridDays" style="margin-top:6px">
        <!-- Rendered via JS -->
      </div>

      <div style="margin-top:14px;text-align:center">
        <button type="button" class="btn" onclick="clearDateFilter()" style="font-size:11px;font-weight:700;color:#6366f1;border:0;background:transparent;cursor:pointer">
          <i class="fa-solid fa-rotate-left"></i> Show All Upcoming
        </button>
      </div>
    </div>

    <!-- GOOGLE SYNC CARD -->
    <div class="sync-card">
      <h4><i class="fa-brands fa-google" style="color:#4285f4"></i> Google Calendar Sync</h4>
      <p>Subscribe to automatically sync scheduled candidate interviews to your phone or Google Calendar.</p>
      <button type="button" class="btn btn-primary" onclick="openSyncModal()" style="font-size:12px;border-radius:10px;padding:8px 16px;width:100%;font-weight:700">
        <i class="fa-solid fa-rotate"></i> Sync Google Calendar
      </button>
    </div>

    <!-- QUICK STATS -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:16px">
      <div style="display:flex;justify-content:space-between;font-size:12px;padding:6px 0;border-bottom:1px solid #f3f4f6">
        <span style="color:#6b7280;font-weight:700"><i class="fa-solid fa-calendar-check" style="color:#10b981"></i> Confirmed</span>
        <strong style="color:#111827"><?=count($confirmed)?></strong>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:12px;padding:6px 0;border-bottom:1px solid #f3f4f6">
        <span style="color:#6b7280;font-weight:700"><i class="fa-regular fa-clock" style="color:#f59e0b"></i> Awaiting</span>
        <strong style="color:#111827"><?=count($waiting)?></strong>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:12px;padding:6px 0">
        <span style="color:#6b7280;font-weight:700"><i class="fa-solid fa-circle-info" style="color:#6366f1"></i> Decided</span>
        <strong style="color:#111827"><?=count($decided)?></strong>
      </div>
    </div>
  </aside>

  <!-- RIGHT COLUMN: INTERVIEW WORKSPACE -->
  <main class="right-panel">
    <div class="workspace-head">
      <div>
        <h2 id="workspaceTitle">Upcoming Confirmed Meetings</h2>
      </div>
      <span class="badge" style="font-size:11px;background:#e0e7ff;color:#4338ca" id="activeCountBadge"><?=count($confirmed)?> Scheduled</span>
    </div>

    <!-- DISPOSITION TAB SWITCHER -->
    <div class="tab-bar">
      <button type="button" class="tab-btn active" id="tabConfirmedBtn" onclick="showCategory('confirmed', this)">Upcoming Confirmed (<?=count($confirmed)?>)</button>
      <button type="button" class="tab-btn" id="tabWaitingBtn" onclick="showCategory('waiting', this)">Awaiting Reply (<?=count($waiting)?>)</button>
      <button type="button" class="tab-btn" id="tabDecisionBtn" onclick="showCategory('decision', this)">Decision History (<?=count($decided)?>)</button>
    </div>

    <!-- CATEGORY 1: UPCOMING CONFIRMED INTERVIEWS -->
    <section class="cat-section" id="catConfirmed">
      <?php if(empty($confirmed)): ?>
        <div style="padding:40px;text-align:center;color:#9ca3af;font-size:13px">No upcoming confirmed interviews for this selection.</div>
      <?php else: ?>
        <?php foreach($byDate as $date=>$meetings): ?>
        <div class="date-section" data-date="<?=$date?>">
          <div class="date-section-title">
            <i class="fa-regular fa-calendar-day" style="color:#6366f1"></i> <?=htmlspecialchars(date('l, d F Y',strtotime($date)))?>
          </div>
          <?php foreach($meetings as $meeting): 
            $gCalUrl = interviewGoogleCalendarUrl($meeting, $meeting);
            $icsUrl = interviewCalendarDownloadUrl((int)$meeting['id']);
          ?>
          <div class="meeting-card">
            <div class="meeting-header">
              <div class="meeting-info">
                <strong>
                  <a href="candidate_detail.php?id=<?=urlencode($meeting['candidate_id'])?>" target="_blank" style="color:#111827;text-decoration:none">
                    <?=htmlspecialchars($meeting['candidate_name'])?>
                  </a>
                  <a href="candidate_detail.php?id=<?=urlencode($meeting['candidate_id'])?>" target="_blank" title="Open candidate profile" style="color:#6366f1;margin-left:4px">
                    <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:10px"></i>
                  </a>
                </strong>
                <small>
                  <span class="time-pill"><?=htmlspecialchars(date('g:i A',strtotime($meeting['starts_at'])))?></span>
                  · <?=htmlspecialchars($meeting['job_title'])?> · <?=htmlspecialchars($meeting['recipient_email'])?>
                </small>
                
                <div class="action-links">
                  <a href="<?=htmlspecialchars($gCalUrl)?>" target="_blank" class="gcal-btn">
                    <i class="fa-brands fa-google" style="color:#4285f4"></i> Add Google Cal
                  </a>
                  <a href="<?=htmlspecialchars($icsUrl)?>" class="gcal-btn">
                    <i class="fa-solid fa-file-arrow-down" style="color:#6366f1"></i> .ics File
                  </a>
                </div>
              </div>

              <div>
                <span class="badge <?=$meeting['notification_status']==='failed'?'failed':''?>">
                  Mail <?=htmlspecialchars($meeting['notification_status']?:'sent')?>
                </span>
              </div>
            </div>

            <?php if($meeting['notification_status']==='failed'):?>
            <form method="post" action="interview_confirmation_retry.php" style="margin-top:10px">
              <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrfToken())?>">
              <input type="hidden" name="invitation_id" value="<?=(int)$meeting['id']?>">
              <button class="btn" type="submit" style="font-size:11px"><i class="fa-solid fa-rotate-right"></i> Retry email</button>
            </form>
            <?php endif?>

            <!-- OUTCOME FORM -->
            <form class="outcome-form" method="post" action="interview_outcome.php">
              <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrfToken())?>">
              <input type="hidden" name="invitation_id" value="<?=(int)$meeting['id']?>">
              <select class="control" name="outcome" required style="font-size:12px;border-radius:8px">
                <option value="">Set interview outcome…</option>
                <?php foreach($labels as $value=>$label):?>
                  <option value="<?=$value?>"><?=$label?></option>
                <?php endforeach?>
              </select>
              <input class="control" name="notes" maxlength="1000" placeholder="Optional recruiter note…" style="font-size:12px;border-radius:8px">
              <button class="btn btn-primary" type="submit" style="font-size:12px;border-radius:8px"><i class="fa-solid fa-floppy-disk"></i> Save</button>
            </form>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <!-- CATEGORY 2: AWAITING REPLIES -->
    <section class="cat-section" id="catWaiting" style="display:none">
      <?php if(empty($waiting)): ?>
        <div style="padding:40px;text-align:center;color:#9ca3af;font-size:13px">No candidates currently awaiting reply.</div>
      <?php else: ?>
        <?php foreach($waiting as $item): ?>
        <div class="waiting-card">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <strong>
              <a href="candidate_detail.php?id=<?=urlencode($item['candidate_id'])?>" target="_blank" style="color:#111827;text-decoration:none">
                <?=htmlspecialchars($item['candidate_name'])?>
              </a>
            </strong>
            <span class="badge" style="background:#fef3c7;color:#b45309">Awaiting Reply</span>
          </div>
          <small style="color:#6b7280;display:block;margin-top:4px"><?=htmlspecialchars($item['job_title'])?> · <?=htmlspecialchars($item['recipient_email'])?></small>
          <small style="color:#b45309;font-weight:700;display:block;margin-top:4px">
            Window <?=htmlspecialchars(date('d M',strtotime($item['availability_start'])))?> – <?=htmlspecialchars(date('d M Y',strtotime($item['availability_end'])))?>
          </small>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <!-- CATEGORY 3: DECISION HISTORY -->
    <section class="cat-section" id="catDecision" style="display:none">
      <?php if(empty($decided)): ?>
        <div style="padding:40px;text-align:center;color:#9ca3af;font-size:13px">No outcomes recorded yet.</div>
      <?php else: ?>
        <?php foreach($decided as $item): ?>
        <div class="decision-card">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <strong><?=htmlspecialchars($item['candidate_name'])?></strong>
            <span class="badge" style="background:#dcfce7;color:#15803d"><?=htmlspecialchars($labels[$item['outcome']]??$item['outcome'])?></span>
          </div>
          <small style="color:#6b7280;display:block;margin-top:4px"><?=htmlspecialchars($item['job_title'])?> · <?=htmlspecialchars($item['recipient_email']??'')?></small>
          <?php if($item['outcome_notes']): ?>
            <small style="font-style:italic;color:#374151;display:block;margin-top:4px">"<?=htmlspecialchars($item['outcome_notes'])?>"</small>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  </main>
</div>

<!-- GOOGLE CALENDAR SYNC MODAL -->
<div class="modal-overlay" id="syncModal">
  <div class="modal-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h3 style="margin:0;font-size:18px;font-weight:800;color:#111827"><i class="fa-brands fa-google" style="color:#4285f4"></i> Google Calendar Feed</h3>
      <button type="button" class="btn" onclick="closeSyncModal()" style="border:0;background:transparent;font-size:16px;cursor:pointer">&times;</button>
    </div>
    <p style="font-size:13px;color:#4b5563;line-height:1.5;margin-bottom:16px">
      Subscribe to your live NonceBlox ATS Interview Calendar. Any newly confirmed interview will automatically sync to your Google Calendar!
    </p>

    <div style="margin-bottom:14px">
      <label style="font-size:11px;font-weight:800;text-transform:uppercase;color:#6b7280;display:block;margin-bottom:6px">Direct Google Calendar Feed Link:</label>
      <div style="display:flex;gap:8px">
        <input type="text" id="icsUrlInput" value="<?=htmlspecialchars($feedUrl)?>" readonly class="control" style="font-size:12px;border-radius:8px">
        <button type="button" class="btn btn-primary" onclick="copyFeedUrl()" style="white-space:nowrap;font-size:12px;border-radius:8px"><i class="fa-regular fa-copy"></i> Copy Link</button>
      </div>
    </div>

    <div style="padding:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;font-size:12px;color:#334155">
      <strong>How to subscribe in Google Calendar:</strong>
      <ol style="margin:8px 0 0;padding-left:20px;line-height:1.6">
        <li>Open <a href="https://calendar.google.com" target="_blank" style="color:#4338ca;font-weight:700">Google Calendar</a>.</li>
        <li>On the left sidebar, click <strong>+ Other calendars</strong> &rarr; <strong>From URL</strong>.</li>
        <li>Paste the copied URL above and click <strong>Add calendar</strong>.</li>
      </ol>
    </div>

    <div style="margin-top:16px;display:flex;justify-content:flex-end">
      <button type="button" class="btn" onclick="closeSyncModal()" style="border-radius:8px">Close</button>
    </div>
  </div>
</div>

<script>
const eventsMap = <?=json_encode($eventsMap, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
let currentYear = 2026;
let currentMonth = 9; // October (0-indexed)

document.addEventListener('DOMContentLoaded', function() {
  const d = new Date();
  currentYear = d.getFullYear();
  currentMonth = d.getMonth();
  renderMiniCalendar(currentYear, currentMonth);
});

function renderMiniCalendar(year, month) {
  const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
  document.getElementById('miniMonthTitle').innerText = `${monthNames[month]} ${year}`;

  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const daysInPrevMonth = new Date(year, month, 0).getDate();

  const container = document.getElementById('miniCalGridDays');
  container.innerHTML = '';

  const todayStr = (new Date()).toISOString().slice(0, 10);

  // Previous month days
  for (let i = firstDay - 1; i >= 0; i--) {
    const dayNum = daysInPrevMonth - i;
    const cell = document.createElement('div');
    cell.className = 'mini-day-cell other-month';
    cell.innerText = dayNum;
    container.appendChild(cell);
  }

  // Current month days
  for (let day = 1; day <= daysInMonth; day++) {
    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    const cell = document.createElement('div');
    cell.className = 'mini-day-cell';
    cell.setAttribute('data-date', dateStr);

    let html = `<span>${day}</span>`;
    if (eventsMap[dateStr] && eventsMap[dateStr].length > 0) {
      html += `<span class="mini-day-dot"></span>`;
    }
    cell.innerHTML = html;

    cell.onclick = function() {
      document.querySelectorAll('.mini-day-cell').forEach(c => c.classList.remove('active-day'));
      cell.classList.add('active-day');
      filterByDate(dateStr);
    };

    container.appendChild(cell);
  }
}

function changeMiniMonth(delta) {
  currentMonth += delta;
  if (currentMonth < 0) { currentMonth = 11; currentYear--; }
  if (currentMonth > 11) { currentMonth = 0; currentYear++; }
  renderMiniCalendar(currentYear, currentMonth);
}

function filterByDate(dateStr) {
  showCategory('confirmed', document.getElementById('tabConfirmedBtn'));
  
  const sections = document.querySelectorAll('#catConfirmed .date-section');
  let found = false;
  sections.forEach(sec => {
    if (sec.getAttribute('data-date') === dateStr) {
      sec.style.display = 'block';
      found = true;
    } else {
      sec.style.display = 'none';
    }
  });

  document.getElementById('workspaceTitle').innerText = `Meetings for ${dateStr}`;
}

function clearDateFilter() {
  document.querySelectorAll('.mini-day-cell').forEach(c => c.classList.remove('active-day'));
  document.querySelectorAll('#catConfirmed .date-section').forEach(sec => sec.style.display = 'block');
  document.getElementById('workspaceTitle').innerText = 'Upcoming Confirmed Meetings';
  showCategory('confirmed', document.getElementById('tabConfirmedBtn'));
}

function showCategory(cat, btnEl) {
  document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
  document.querySelectorAll('.cat-section').forEach(sec => sec.style.display = 'none');
  
  btnEl.classList.add('active');
  
  if (cat === 'confirmed') {
    document.getElementById('catConfirmed').style.display = 'block';
    document.getElementById('workspaceTitle').innerText = 'Upcoming Confirmed Meetings';
  } else if (cat === 'waiting') {
    document.getElementById('catWaiting').style.display = 'block';
    document.getElementById('workspaceTitle').innerText = 'Awaiting Candidate Reply';
  } else if (cat === 'decision') {
    document.getElementById('catDecision').style.display = 'block';
    document.getElementById('workspaceTitle').innerText = 'Interview Decision History';
  }
}

function openSyncModal() {
  document.getElementById('syncModal').classList.add('active');
}

function closeSyncModal() {
  document.getElementById('syncModal').classList.remove('active');
}

function copyFeedUrl() {
  const input = document.getElementById('icsUrlInput');
  input.select();
  document.execCommand('copy');
  alert('Google Calendar feed URL copied to clipboard!');
}
</script>

<?php require __DIR__.'/views/partials/footer.php'; ?>
