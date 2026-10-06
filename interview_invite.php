<?php
require_once __DIR__.'/lib/auth.php';requireAuth();require_once __DIR__.'/lib/workspace.php';require_once __DIR__.'/lib/branding.php';require_once __DIR__.'/lib/integrations.php';require_once __DIR__.'/lib/smtp_mailer.php';require_once __DIR__.'/lib/email_api_mailer.php';require_once __DIR__.'/lib/interview_email_template.php';require_once __DIR__.'/lib/interview_schedule.php';require_once __DIR__.'/lib/interview_schedule_persistence.php';require_once __DIR__.'/lib/interview_send_bridge.php';
$pdo=db();$user=currentUser();$brand=organizationBrand();$jobId=(int)($_GET['job_id']??$_POST['job_id']??0);$message='';$error='';
$jobStmt=$pdo->prepare('SELECT * FROM jobs WHERE id=?');$jobStmt->execute([$jobId]);$job=$jobStmt->fetch();if(!$job){http_response_code(404);exit('Job not found.');}
$profileStmt=$pdo->prepare('SELECT * FROM wishlist_profiles WHERE job_id=? AND user_id=? ORDER BY id LIMIT 1');$profileStmt->execute([$jobId,$user['id']]);$profile=$profileStmt->fetch();if(!$profile){http_response_code(409);exit('Save favourites for this job before creating interview invitations.');}
$profileOptionsStmt=$pdo->prepare('SELECT wp.job_id,j.title,COUNT(CASE WHEN wi.disposition=\'wishlist\' THEN 1 END) favourite_count FROM wishlist_profiles wp JOIN jobs j ON j.id=wp.job_id LEFT JOIN wishlist_items wi ON wi.profile_id=wp.id WHERE wp.user_id=? GROUP BY wp.id,wp.job_id,j.title ORDER BY j.title');$profileOptionsStmt->execute([$user['id']]);$profileOptions=$profileOptionsStmt->fetchAll();
$all=array_values(array_filter(loadCandidateRecords(),fn($candidate)=>(int)($candidate['job_id']??0)===$jobId));
$wishStmt=$pdo->prepare("SELECT candidate_id FROM wishlist_items WHERE profile_id=? AND disposition='wishlist'");$wishStmt->execute([$profile['id']]);$preselected=array_flip(array_column($wishStmt->fetchAll(),'candidate_id'));
$lockStmt=$pdo->prepare('SELECT candidate_id FROM interview_invite_locks WHERE profile_id=? AND active=1');$lockStmt->execute([$profile['id']]);$locked=array_fill_keys($lockStmt->fetchAll(PDO::FETCH_COLUMN),true);
usort($all,function($a,$b)use($preselected,$locked){
    $aPre=isset($preselected[$a['id']])?1:0;
    $bPre=isset($preselected[$b['id']])?1:0;
    if($aPre!==$bPre)return $bPre<=>$aPre;
    $aLock=isset($locked[$a['id']])?1:0;
    $bLock=isset($locked[$b['id']])?1:0;
    if($aLock!==$bLock)return $aLock<=>$bLock;
    return ((int)($b['job_match']['analyzed']??0)<=> (int)($a['job_match']['analyzed']??0))?:(($b['score']??-1)<=>($a['score']??-1));
});
$emailApi=integrationConfig('email_api');if(($emailApi['status']??'')!=='configured')$emailApi=['status'=>'configured','url'=>'https://hrms-api.nonceblox.com/api/emails/send-multi-recipient'];$emailApiReady=!empty($emailApi['url']);$smtp=integrationConfig('smtp');$smtpReady=($smtp['status']??'')==='configured'&&!empty($smtp['host'])&&!empty($smtp['from_email']);$deliveryReady=$emailApiReady||$smtpReady;$deliveryName=$emailApiReady?'NonceBlox Email API':'SMTP';$emailTesting=integrationConfig('email_testing');$testCopyEnabled=!array_key_exists('enabled',$emailTesting)||!empty($emailTesting['enabled']);$testCopyEmail=trim((string)($emailTesting['email']??'kishan.sharma@nonceblox.com'));$deliverOne=function(string $to,string $mailSubject,string $html)use($emailApiReady,$emailApi,$smtp){if($emailApiReady)emailApiSendHtml($emailApi,[$to],$mailSubject,$html);else smtpSendHtml($smtp,$to,$mailSubject,$html);};$deliverInterview=function(string $to,string $mailSubject,string $html)use($deliverOne,$testCopyEnabled,$testCopyEmail){if($testCopyEnabled&&filter_var($testCopyEmail,FILTER_VALIDATE_EMAIL)&&strcasecmp($to,$testCopyEmail)!==0)$deliverOne($testCopyEmail,'[TEST COPY] '.$mailSubject,$html);$deliverOne($to,$mailSubject,$html);};
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='reset'&&verifyCsrf($_POST['csrf']??'')){$pdo->prepare('UPDATE interview_invite_locks SET active=0,reset_at=NOW(),reset_by=? WHERE profile_id=? AND active=1')->execute([$user['id'],$profile['id']]);$pdo->prepare("INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,'interview.invitation_locks_reset','wishlist_profile',?,?)")->execute([$user['id'],(string)$profile['id'],json_encode(['job_id'=>$jobId,'outcome'=>'success'])]);header('Location: interview_invite.php?job_id='.$jobId);exit;}
require __DIR__.'/lib/interview_invite_scheduler.php';
if(isset($_GET['scheduled'])){$s=(int)$_GET['scheduled'];$f=(int)($_GET['failed']??0);$message=$s.' invitation'.($s===1?'':'s').' sent cleanly. '.$f.' failed. Candidates moved to Interview stage.';}

$activePage='wishlist';$pageTitle='Interview invitations · ResumeIQ';
$pageStyles='<style>
.invite-head{padding:20px;display:flex;justify-content:space-between;align-items:center}
.invite-grid{display:grid;grid-template-columns:minmax(0,22fr) minmax(0,30fr) minmax(0,48fr);gap:16px;margin-top:14px;width:100%}
.invite-box{min-width:0;padding:20px;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,0.03);background:#fff;display:flex;flex-direction:column}
.candidate-search{margin:10px 0}
.recipient-list{max-height:580px;overflow-y:auto;border-top:1px solid var(--line);padding-right:4px}
.recipient{display:grid;grid-template-columns:24px 32px 1fr auto;gap:10px;align-items:center;padding:11px 6px;border-bottom:1px solid var(--line);transition:background 0.15s ease}
.recipient:hover{background:rgba(0,0,0,0.015)}
.recipient.hide{display:none}
.recipient strong,.recipient small{display:block}
.recipient small{color:var(--muted);font-size:11px}
.rank-badge{color:var(--primary);font-weight:800;font-size:10px;background:var(--primary-soft);padding:2px 5px;border-radius:4px;text-align:center}
.score-badge{font-size:12px;color:var(--primary);font-weight:800}
.compose label{display:block;font-size:11px;font-weight:700;margin:12px 0 5px;color:var(--text)}
.pair{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.compose textarea{min-height:110px;line-height:1.6;font-family:inherit}
.send-row{display:flex;justify-content:space-between;align-items:center;margin-top:16px;padding-top:12px;border-top:1px solid var(--line)}
.send-row .btn{height:42px}
.smtp-warning{padding:11px;background:#fff5e7;color:#8c610d;border-radius:8px;margin-top:10px}
.ok,.err{padding:12px 16px;margin-top:10px;border-radius:8px;font-weight:600}
.ok{background:var(--green-soft);color:var(--green)}
.err{background:#fff1f3;color:var(--danger)}
.schedule-card{background:#fafafa;border:1px solid var(--line);border-radius:10px;padding:16px;margin:14px 0}
.sched-mode-tab{padding:5px 12px;font-size:11px;font-weight:700;border:0;border-radius:6px;cursor:pointer;transition:all 0.2s ease}
.sched-mode-tab.active{background:#fff;color:var(--primary);box-shadow:0 1px 4px rgba(0,0,0,0.1)}
.buffer-info-box{margin-top:10px;padding:10px 12px;background:#eef6ff;border-left:3px solid #2b7fff;border-radius:6px;font-size:11px;color:#1e40af;line-height:1.5}
.live-preview-box{min-width:0;background:#f8f9fc;border:1px solid var(--line);overflow:hidden;padding:0;display:flex;flex-direction:column}
.preview-header-bar{padding:14px 18px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between}
.template-frame-wrap-inline{width:100%;flex:1;background:#f6f5f9;padding:16px;overflow-x:auto;display:flex;justify-content:center;align-items:flex-start}
.template-preview-frame{width:100%;max-width:680px;min-height:640px;height:100%;border:0;background:#fff;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,0.06)}
.mail-preview{position:fixed;inset:0;z-index:120;margin:0;background:#17122566;backdrop-filter:blur(4px);display:none;overflow:auto;padding:30px 16px}
.mail-preview.open{display:block}
.mail-preview>.preview-toolbar,.mail-preview>.template-frame-wrap{width:min(900px,100%);margin-left:auto;margin-right:auto}
.mail-preview>.preview-toolbar{border-radius:12px 12px 0 0;padding:14px 18px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between}
.mail-preview>.template-frame-wrap{border-radius:0 0 12px 12px;overflow:hidden;background:#fff}
.modal-preview-frame{display:block;width:100%;height:min(80vh,900px);border:0;background:#f6f5f9}

@media(min-width:900px){
  .invite-grid{grid-template-columns:minmax(0,22fr) minmax(0,30fr) minmax(0,48fr)}
  .live-preview-box{grid-column:span 1;margin-top:0}
}
@media(max-width:899px){
  .invite-grid{grid-template-columns:1fr}
  .live-preview-box{grid-column:span 1;margin-top:14px;width:100%}
  .pair{grid-template-columns:1fr}
}
</style>';
require __DIR__.'/views/partials/header.php';
?>
<section class="panel invite-head">
  <div>
    <div class="kicker"><i class="fa-solid fa-calendar-check"></i> Interview outreach</div>
    <h1><?=htmlspecialchars(trim($job['title']))?></h1>
    <p class="muted">Select candidate recipients, set your availability & working hours schedule, and preview invitations live.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a class="btn" href="outreach.php"><i class="fa-solid fa-paper-plane"></i> Outreach hub</a>
    <a class="btn" href="pipeline.php?job_id=<?=$jobId?>">Open job pipeline</a>
  </div>
</section>

<?php if($message):?><div class="ok"><?=htmlspecialchars($message)?></div><?php endif?>
<?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif?>
<?php if(!$smtpReady && !$emailApiReady):?><div class="smtp-warning">Email delivery is not ready. <a href="integrations.php">Configure NonceBlox Email API or SMTP</a> before sending.</div><?php endif?>

<form method="post" class="invite-grid" id="inviteForm">
  <input type="hidden" name="csrf" value="<?=csrfToken()?>">
  <input type="hidden" name="action" value="send">
  <input type="hidden" name="job_id" value="<?=$jobId?>">
  
  <!-- COLUMN 1 (22%): RECIPIENTS -->
  <section class="panel invite-box">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <h2>Recipients</h2>
      <small class="muted"><?=count($all)?> candidates</small>
    </div>
    <input class="control candidate-search" id="candidateSearch" placeholder="Search name, email, role or skill">
    <div class="recipient-list">
      <?php foreach($all as $index=>$candidate): $person=candidatePresentation($candidate); ?>
      <label class="recipient" data-find="<?=htmlspecialchars(mb_strtolower($person['name'].' '.$candidate['email'].' '.$person['role'].' '.implode(' ',$candidate['skills']??[])))?>">
        <input type="checkbox" name="candidate_ids[]" value="<?=htmlspecialchars($candidate['id'])?>" <?=isset($preselected[$candidate['id']])?'checked':''?> <?=empty($candidate['email'])?'disabled':''?>>
        <span class="rank-badge">#<?=$index+1?></span>
        <span>
          <strong>
            <?=htmlspecialchars($person['name'])?> 
            <a href="candidate_detail.php?id=<?=urlencode($candidate['id'])?>" target="_blank" onclick="event.stopPropagation()" title="Open candidate profile in new tab" style="color:var(--primary);margin-left:4px;text-decoration:none">
              <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:10px"></i>
            </a>
          </strong>
          <small><?=htmlspecialchars($candidate['email']?:'Email unavailable')?> · <?=htmlspecialchars($person['role'])?></small>
        </span>
        <span class="score-badge"><?=$candidate['job_match']['analyzed']?$candidate['score'].'%':'—'?></span>
      </label>
      <?php endforeach?>
    </div>
  </section>

  <!-- COLUMN 2 (28%): INVITATION COMPOSER & SCHEDULE -->
  <section class="panel invite-box compose">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <h2>Invitation & Schedule</h2>
      <div id="recipientSummary" class="tag">0 selected</div>
    </div>

    <!-- SUBJECT -->
    <label>Subject</label>
    <input class="control" name="subject" value="Interview invitation — <?=htmlspecialchars(trim($job['title']))?>" required>

    <!-- AVAILABILITY & WORKING HOURS CARD -->
    <div class="schedule-card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <strong style="font-size:12px;color:var(--text)">
          <i class="fa-solid fa-clock" style="color:var(--primary);margin-right:6px"></i> Recruiter Availability Window
        </strong>
        <div style="display:flex;gap:4px;background:#eef0f4;padding:3px;border-radius:8px">
          <button type="button" class="sched-mode-tab active" id="tabSimpleBtn" onclick="switchScheduleTab('simple')">Standard Window</button>
          <button type="button" class="sched-mode-tab" id="tabDaywiseBtn" onclick="switchScheduleTab('daywise')">Day-wise Working Hours</button>
        </div>
      </div>

      <!-- STANDARD RANGE CONTROLS -->
      <div id="simpleScheduleMode">
        <div class="pair" style="margin-bottom:10px">
          <div>
            <label style="margin-top:0">Availability starts (Date)</label>
            <input class="control" type="date" name="availability_start" value="<?=date('Y-m-d', strtotime('+1 day'))?>" required>
          </div>
          <div>
            <label style="margin-top:0">Daily start time</label>
            <input class="control" type="time" name="daily_start" value="10:00" required>
          </div>
        </div>

        <div class="pair">
          <div>
            <label style="margin-top:0">Availability ends (Date)</label>
            <input class="control" type="date" name="availability_end" value="<?=date('Y-m-d', strtotime('+7 days'))?>" required>
          </div>
          <div>
            <label style="margin-top:0">Daily end time</label>
            <input class="control" type="time" name="daily_end" value="17:00" required>
          </div>
        </div>
      </div>

      <!-- DAY-WISE WORKING HOURS CONTROLS -->
      <div id="daywiseScheduleMode" style="display:none;margin-top:12px">
        <p style="font-size:11px;color:var(--muted);margin-bottom:10px">Select working days and specific daily working hours for candidate slots:</p>
        <div style="display:flex;flex-direction:column;gap:6px">
          <?php
          $daysOfWeek = [
            1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
            4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'
          ];
          foreach($daysOfWeek as $num => $dayName):
            $isWorkday = ($num <= 5);
          ?>
          <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 10px;background:#fff;border:1px solid var(--line);border-radius:6px;font-size:11px">
            <label style="display:flex;align-items:center;gap:8px;font-weight:600;margin:0;cursor:pointer;width:110px">
              <input type="checkbox" name="working_days[]" value="<?=$num?>" <?=$isWorkday?'checked':''?> onchange="toggleDayHoursRow(this, <?=$num?>)">
              <?=$dayName?>
            </label>
            <div id="dayHoursRow_<?=$num?>" style="display:flex;align-items:center;gap:6px;<?=$isWorkday?'':'opacity:0.4;pointer-events:none'?>">
              <input class="control" type="time" name="day_hours[<?=$num?>][start]" value="10:00" style="padding:2px 6px;font-size:11px;height:28px">
              <span style="font-size:10px;color:var(--muted)">to</span>
              <input class="control" type="time" name="day_hours[<?=$num?>][end]" value="17:00" style="padding:2px 6px;font-size:11px;height:28px">
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- DURATION & BUFFER -->
      <div class="pair" style="margin-top:12px">
        <div>
          <label style="margin-top:0">Interview duration</label>
          <select class="control" name="slot_minutes">
            <option value="30" selected>30 minutes</option>
            <option value="45">45 minutes</option>
            <option value="60">60 minutes</option>
            <option value="15">15 minutes</option>
          </select>
        </div>
        <div>
          <label style="margin-top:0">Buffer between interviews</label>
          <select class="control" name="buffer_minutes">
            <option value="0">No buffer</option>
            <option value="10">10 minutes</option>
            <option value="15" selected>15 minutes</option>
            <option value="30">30 minutes</option>
          </select>
        </div>
      </div>

      <!-- BUFFER EXPLANATION BOX -->
      <div class="buffer-info-box">
        <i class="fa-solid fa-lightbulb" style="margin-right:4px"></i>
        <strong>Buffer explanation:</strong> Buffer is rest & note-taking time between consecutive candidate slots (e.g. 30m interview + 15m buffer means candidate #2 starts 45 minutes after candidate #1).
      </div>

      <!-- TIMEZONE -->
      <div style="margin-top:12px">
        <label style="margin-top:0">Timezone</label>
        <select class="control" name="timezone">
          <option value="Asia/Kolkata" selected>Asia/Kolkata (IST +5:30)</option>
          <option value="UTC">UTC (+0:00)</option>
          <option value="America/New_York">America/New_York (EST -5:00)</option>
          <option value="Europe/London">Europe/London (GMT +0:00)</option>
          <option value="Asia/Dubai">Asia/Dubai (GST +4:00)</option>
        </select>
      </div>
    </div>

    <!-- MESSAGE TEMPLATE -->
    <label>Message Template</label>
    <textarea class="control" name="body" required>Hello {{candidate_name}},

Thank you for your interest in the {{job_title}} role at NonceBlox. We would like to invite you for an interview.

Please use the link below to select your preferred interview time slot:
{{booking_url}}

Regards,
Hiring Team</textarea>

    <div class="send-row">
      <small class="muted">Only successful deliveries move candidates to Interview.</small>
      <div style="display:flex;gap:8px">
        <button type="button" class="btn" id="previewOpenBtn"><i class="fa-regular fa-eye"></i> Preview email</button>
        <button type="submit" class="btn btn-primary" id="sendBtn" <?=$deliveryReady?'':'disabled'?> onclick="return confirm('Send interview invitations to the selected candidates?')">
          <i class="fa-solid fa-paper-plane"></i> Send invitations
        </button>
      </div>
    </div>
  </section>

  <!-- COLUMN 3 (50% WIDEST COLUMN): LIVE EMAIL PREVIEW COLUMN -->
  <section class="panel invite-box live-preview-box">
    <div class="preview-header-bar">
      <div>
        <strong style="font-size:13px;color:var(--text)"><i class="fa-solid fa-eye" style="color:var(--primary);margin-right:6px"></i> Live Email Preview</strong>
        <div style="font-size:11px;color:var(--muted);margin-top:2px" id="previewRecipients">No recipients selected</div>
      </div>
      <button type="button" class="btn" id="colModalBtn" style="padding:6px 14px;font-size:11px;background:#fff;border:1px solid var(--line);box-shadow:0 1px 3px rgba(0,0,0,0.05)"><i class="fa-solid fa-expand" style="color:var(--primary);margin-right:4px"></i> Full Modal View</button>
    </div>
    <div class="template-frame-wrap-inline">
      <iframe class="template-preview-frame" id="templatePreviewFrame" title="Interview email preview" sandbox="allow-popups"></iframe>
    </div>
  </section>
</form>

<!-- FULL-SCREEN PREVIEW MODAL -->
<section class="mail-preview" id="emailPreviewModal">
  <div class="preview-toolbar">
    <div>
      <strong>NonceBlox interview email — Full Modal View</strong>
      <div class="preview-recipients" id="modalPreviewRecipients" style="font-size:11px;color:var(--muted);margin-top:4px"></div>
    </div>
    <button type="button" class="btn" id="previewCloseBtn"><i class="fa-solid fa-xmark"></i> Close</button>
  </div>
  <div class="template-frame-wrap">
    <iframe class="modal-preview-frame" id="modalPreviewFrame" title="Interview email modal preview" sandbox="allow-popups"></iframe>
  </div>
</section>

<script>
const rows=[...document.querySelectorAll('.recipient')],search=document.getElementById('candidateSearch'),boxes=[...document.querySelectorAll('.recipient input[type="checkbox"]')],summary=document.getElementById('recipientSummary');
function updateSummary(){summary.textContent=boxes.filter(box=>box.checked).length+' selected'}
search.oninput=()=>{const query=search.value.trim().toLowerCase();rows.forEach(row=>row.classList.toggle('hide',query&&!row.dataset.find.includes(query)))};
boxes.forEach(box=>box.addEventListener('change',updateSummary));
updateSummary();

function switchScheduleTab(mode){
  const simpleDiv=document.getElementById('simpleScheduleMode'),daywiseDiv=document.getElementById('daywiseScheduleMode'),btnSimple=document.getElementById('tabSimpleBtn'),btnDaywise=document.getElementById('tabDaywiseBtn');
  if(mode==='daywise'){
    simpleDiv.style.display='none';daywiseDiv.style.display='block';btnSimple.classList.remove('active');btnDaywise.classList.add('active');
  }else{
    simpleDiv.style.display='block';daywiseDiv.style.display='none';btnSimple.classList.add('active');btnDaywise.classList.remove('active');
  }
}
function toggleDayHoursRow(chk,num){
  const row=document.getElementById('dayHoursRow_'+num);
  if(row){row.style.opacity=chk.checked?'1':'0.4';row.style.pointerEvents=chk.checked?'auto':'none';}
}
</script>

<script>
const lockedIds=<?=json_encode(array_keys($locked))?>;
lockedIds.forEach(id=>{
  const box=document.querySelector('.recipient input[value="'+CSS.escape(id)+'"]');
  if(!box)return;
  box.checked=false;box.disabled=true;
  const row=box.closest('.recipient');
  row.style.opacity='.5';row.style.cursor='not-allowed';
  const badge=row.querySelector('.score-badge');if(badge)badge.textContent='Invited';
});
updateSummary();

const headActions=document.querySelector('.invite-head>a')?.parentElement;
if(headActions){
  headActions.style.display='flex';headActions.style.gap='8px';
  const reset=document.createElement('form');
  reset.method='post';
  reset.onsubmit=()=>confirm('Reset invited candidates for this job and allow selection again?');
  reset.innerHTML='<input type="hidden" name="csrf" value="<?=csrfToken()?>"><input type="hidden" name="action" value="reset"><input type="hidden" name="job_id" value="<?=$jobId?>"><button class="btn" <?=empty($locked)?'disabled':''?>><i class="fa-solid fa-rotate-left"></i> Reset invited</button>';
  headActions.prepend(reset);
}
</script>

<style>
.invite-actions{display:flex;align-items:center;gap:8px;position:relative;z-index:2}
.profile-switch{display:grid;gap:4px;font-size:10px;color:var(--muted)}
.profile-switch select{min-width:200px}
</style>

<script>
const actionLink=document.querySelector('.invite-head>a'),directReset=document.querySelector('.invite-head>form'),actionBar=document.createElement('div');actionBar.className='invite-actions';
if(actionLink){
  actionLink.before(actionBar);
  if(directReset)actionBar.appendChild(directReset);
  const switcher=document.createElement('label'),profileSelect=document.createElement('select'),profileOptions=<?=json_encode($profileOptions,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  switcher.className='profile-switch';switcher.append('Job profile');
  profileSelect.className='control';
  profileOptions.forEach(item=>profileSelect.add(new Option(item.title+' · '+item.favourite_count+' selected',item.job_id,false,Number(item.job_id)===<?=$jobId?>)));
  profileSelect.onchange=event=>location.href='interview_invite.php?job_id='+event.target.value;
  switcher.append(profileSelect);
  actionBar.append(switcher,actionLink);
}

const canonicalEmailTemplate=<?=json_encode(file_get_contents(__DIR__.'/assets/interview_email_template.html'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const colFrame=document.getElementById('templatePreviewFrame'),colRecipients=document.getElementById('previewRecipients');
const modalFrame=document.getElementById('modalPreviewFrame'),modalRecipients=document.getElementById('modalPreviewRecipients'),emailModal=document.getElementById('emailPreviewModal');
const previewOpenBtn=document.getElementById('previewOpenBtn'),colModalBtn=document.getElementById('colModalBtn'),previewCloseBtn=document.getElementById('previewCloseBtn');

const subjectInput=document.querySelector('[name="subject"]'),bodyInput=document.querySelector('[name="body"]'),startDateInput=document.querySelector('[name="availability_start"]'),startTimeInput=document.querySelector('[name="daily_start"]'),timezoneInput=document.querySelector('[name="timezone"]');

function selectedRows(){return boxes.filter(box=>box.checked&&!box.disabled).map(box=>({box,name:box.closest('.recipient').querySelector('strong').textContent.trim()}))}
function previewEscape(val){const node=document.createElement('span');node.textContent=val;return node.innerHTML}

let canonicalPreviewUrl='';
function renderCanonicalPreview(){
  const selected=selectedRows(),first=selected[0]?.name||'Candidate',date=startDateInput.value||'Interview date',time=startTimeInput.value||'Interview time',timezone=timezoneInput.value||'Asia/Kolkata';
  const html=canonicalEmailTemplate.replaceAll('{{candidate_name}}',previewEscape(first)).replaceAll('{{job_title}}',previewEscape(<?=json_encode(trim($job['title']))?>)).replaceAll('{{interview_date}}',previewEscape(date)).replaceAll('{{interview_time}}',previewEscape(time)).replaceAll('{{timezone}}',previewEscape(timezone));
  
  if(canonicalPreviewUrl)URL.revokeObjectURL(canonicalPreviewUrl);
  canonicalPreviewUrl=URL.createObjectURL(new Blob([html],{type:'text/html;charset=utf-8'}));
  
  if(colFrame)colFrame.src=canonicalPreviewUrl;
  if(modalFrame)modalFrame.src=canonicalPreviewUrl;

  const recipText = selected.length ? 'Selected (' + selected.length + '): ' + selected.map(item=>item.name).join(' · ') : 'No recipients selected';
  if(colRecipients)colRecipients.textContent=recipText;
  if(modalRecipients)modalRecipients.textContent=recipText;
}

function openModalView(){
  renderCanonicalPreview();
  if(emailModal)emailModal.classList.add('open');
}

if(previewOpenBtn)previewOpenBtn.onclick=openModalView;
if(colModalBtn)colModalBtn.onclick=openModalView;
if(previewCloseBtn)previewCloseBtn.onclick=()=>emailModal.classList.remove('open');
if(emailModal)emailModal.addEventListener('click',e=>{if(e.target===emailModal)emailModal.classList.remove('open');});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&emailModal)emailModal.classList.remove('open');});

[subjectInput,bodyInput,startDateInput,startTimeInput,timezoneInput].forEach(inp=>{if(inp)inp.addEventListener('input',renderCanonicalPreview);});
boxes.forEach(box=>box.addEventListener('change',async()=>{
  renderCanonicalPreview();
  const previous=!box.checked;box.disabled=true;
  try{
    const response=await fetch('invite_selection_api.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body:new URLSearchParams({csrf:<?=json_encode(csrfToken())?>,job_id:<?=$jobId?>,profile_id:<?=(int)$profile['id']?>,candidate_id:box.value,selected:box.checked?'1':'0'})}),payload=await response.json();
    if(!response.ok||!payload.ok)throw new Error(payload.error||'Selection could not be saved.');
  }catch(error){
    box.checked=previous;alert(error.message);
  }finally{
    if(!lockedIds.includes(box.value))box.disabled=false;
    updateSummary();renderCanonicalPreview();
  }
}));

renderCanonicalPreview();
</script>

<link rel="stylesheet" href="assets/interview-invite-progress.css?v=20261001d">
<div class="send-lock" id="sendLock" role="status" aria-live="assertive" aria-hidden="true">
  <div class="send-lock-card">
    <div class="send-spinner"></div>
    <strong>Sending interview invitations...</strong>
    <small>Please keep this page open. Delivery status is being saved.</small>
  </div>
</div>
<script src="assets/interview-submit-lock.js?v=20261001d"></script>
<script src="assets/interview-invite-progress.js?v=20261001d"></script>

<script>
document.addEventListener('DOMContentLoaded',()=>{
  const dateInput=document.querySelector('[name="availability_start"]'),timezoneInput=document.querySelector('[name="timezone"]');
  if(!dateInput||!timezoneInput)return;

  const zonedParts=tz=>{const parts=new Intl.DateTimeFormat('en-CA',{timeZone:tz,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hour12:false}).formatToParts(new Date());const o={};parts.forEach(p=>{if(p.type!=='literal')o[p.type]=p.value});return o};
  const addOneDay=ymd=>{const [y,m,d]=ymd.split('-').map(Number),dt=new Date(Date.UTC(y,m-1,d));dt.setUTCDate(dt.getUTCDate()+1);return dt.getUTCFullYear()+'-'+String(dt.getUTCMonth()+1).padStart(2,'0')+'-'+String(dt.getUTCDate()).padStart(2,'0')};
  const minDateForZone=tz=>{const p=zonedParts(tz);return addOneDay(p.year+'-'+p.month+'-'+p.day)};

  function applyMinDate(){
    const min=minDateForZone(timezoneInput.value||'Asia/Kolkata');
    dateInput.min=min;
    if(dateInput._flatpickr)dateInput._flatpickr.set('minDate',min);
    if(dateInput.value&&dateInput.value<min){
      if(dateInput._flatpickr)dateInput._flatpickr.clear(); else dateInput.value='';
      dateInput.dispatchEvent(new Event('input',{bubbles:true}));
    }
  }

  applyMinDate();
  timezoneInput.addEventListener('change',applyMinDate);

  const form=document.getElementById('inviteForm');
  if(form)form.addEventListener('submit',e=>{
    const min=minDateForZone(timezoneInput.value||'Asia/Kolkata');
    if(!dateInput.value||dateInput.value<min){
      e.preventDefault();e.stopImmediatePropagation();
      dateInput.setCustomValidity('Please select an availability start date from tomorrow onward in the selected timezone.');
      dateInput.reportValidity();
      return false;
    }
    dateInput.setCustomValidity('');
  },true);
});
</script>

<?php require __DIR__.'/views/partials/footer.php';