<?php
require_once __DIR__.'/lib/auth.php';
requireAuth();
require_once __DIR__.'/lib/workspace.php';
require_once __DIR__.'/lib/branding.php';
$pdo=db();$user=currentUser();$brand=organizationBrand();$message='';$error='';
$ownerId=(int)$pdo->query('SELECT MIN(id) FROM users')->fetchColumn();$isOwner=(int)($user['id']??0)===$ownerId;

function roleKey(string $value):string{$value=mb_strtolower(trim($value));$value=preg_replace('/^sr\.?\s+/','senior ',$value);return preg_replace('/\s+/',' ',$value);}

function brandedEmailHtml(array $brand,string $domain,string $logoUrl,string $message):string{
    $company=htmlspecialchars($brand['name']?:'NonceBlox ATS',ENT_QUOTES|ENT_HTML5,'UTF-8');$safeDomain=htmlspecialchars($domain,ENT_QUOTES|ENT_HTML5,'UTF-8');$safeLogo=htmlspecialchars($logoUrl,ENT_QUOTES|ENT_HTML5,'UTF-8');$copy=nl2br(htmlspecialchars($message,ENT_QUOTES|ENT_HTML5,'UTF-8'));
    $mark=$safeLogo!==''?'<img src="'.$safeLogo.'" alt="'.$company.'" style="display:block;max-width:130px;max-height:52px">':'<div style="font-size:24px;font-weight:800;color:#6842ff">'.$company.'</div>';
    return '<!doctype html><html><body style="margin:0;background:#f4f5f9;font-family:Arial,sans-serif;color:#202330"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f5f9;padding:30px 15px"><tr><td align="center"><table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:100%;max-width:640px;background:#fff;border:1px solid #e7e5f0;border-radius:12px;overflow:hidden"><tr><td style="padding:24px 30px;background:linear-gradient(135deg,#faf9ff,#f0edff)">'.$mark.'<div style="margin-top:8px;color:#727786;font-size:13px">'.$safeDomain.'</div></td></tr><tr><td style="padding:34px 30px;font-size:15px;line-height:1.75">'.$copy.'</td></tr><tr><td style="padding:18px 30px;border-top:1px solid #eceaf3;color:#858997;font-size:12px">'.$company.' · '.$safeDomain.'</td></tr></table></td></tr></table></body></html>';
}

if(isset($_GET['deleted']))$message=$_GET['deleted']==='1'?'Candidate and related operational records deleted.':'Candidate was already unavailable.';

if($_SERVER['REQUEST_METHOD']==='POST'&&verifyCsrf($_POST['csrf']??'')){
    try{
        $action=$_POST['action']??'';$profile=(int)($_POST['profile_id']??0);
        $profileName='';
        if($profile){
            $owned=$pdo->prepare('SELECT name FROM wishlist_profiles WHERE id=? AND user_id=?');
            $owned->execute([$profile,$user['id']]);
            $profileName=(string)$owned->fetchColumn();
            if(!$profileName)throw new RuntimeException('Invalid profile.');
        }

        if($action==='create_profile'){
            $name=trim($_POST['profile_name']??'');
            if(!$name)throw new RuntimeException('Profile name is required.');
            $pdo->prepare('INSERT IGNORE INTO wishlist_profiles(user_id,name) VALUES(?,?)')->execute([$user['id'],$name]);
            $message='Profile created.';
        }
        elseif($action==='add'){
            $candidate=$_POST['candidate_id']??'';
            $s=$pdo->prepare('SELECT role_title FROM candidates WHERE id=?');
            $s->execute([$candidate]);
            $role=(string)$s->fetchColumn();
            if(!$role||roleKey($role)!==roleKey($profileName))throw new RuntimeException('Candidate can only be added to the job they applied for.');
            $pdo->prepare("INSERT INTO wishlist_items(profile_id,candidate_id,disposition) VALUES(?,?,'wishlist') ON DUPLICATE KEY UPDATE disposition=IF(disposition='blacklisted',disposition,'wishlist')")->execute([$profile,$candidate]);
            $message='Candidate added to '.$profileName.'.';
        }
        elseif($action==='status'){
            $status=$_POST['status']??'';
            if(!in_array($status,['wishlist','selected','blacklisted'],true))throw new RuntimeException('Invalid status.');
            $pdo->prepare('UPDATE wishlist_items SET disposition=? WHERE profile_id=? AND candidate_id=?')->execute([$status,$profile,$_POST['candidate_id']??'']);
            $message='Candidate status updated.';
        }
        elseif($action==='email'){
            $ids=array_values(array_unique(array_filter($_POST['candidate_ids']??[])));
            $subject=trim($_POST['subject']??'');$body=trim($_POST['body']??'');
            $date=trim($_POST['interview_date']??'');$time=trim($_POST['interview_time']??'');
            $timezone=trim($_POST['timezone']??'Asia/Kolkata');
            if(!$ids||!$subject||!$body)throw new RuntimeException('Select candidates and complete the preview.');
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $s=$pdo->prepare("SELECT c.id,c.email FROM candidates c JOIN wishlist_items wi ON wi.candidate_id=c.id WHERE wi.profile_id=? AND wi.disposition='wishlist' AND c.id IN ($marks) AND NOT EXISTS(SELECT 1 FROM interview_invite_locks il WHERE il.profile_id=wi.profile_id AND il.candidate_id=c.id AND il.active=1)");
            $s->execute(array_merge([$profile],$ids));
            $recipients=$s->fetchAll();
            if(count($recipients)!==count($ids))throw new RuntimeException('A selected candidate is unavailable or already invited.');
            $domain=trim((string)($brand['domain']??''));
            if($domain==='')$domain=parse_url((string)$pdo->query('SELECT public_url FROM jobs WHERE public_url IS NOT NULL LIMIT 1')->fetchColumn(),PHP_URL_HOST)?:($_SERVER['HTTP_HOST']??'');
            $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
            $base=$scheme.'://'.($_SERVER['HTTP_HOST']??'localhost');
            $logoUrl=!empty($brand['logo_path'])?$base.'/'.ltrim($brand['logo_path'],'/'):'';
            $html=brandedEmailHtml($brand,$domain,$logoUrl,$body);
            $interviewAt=($date&&$time)?$date.' '.$time.':00':null;
            $dedupe=hash('sha256',$user['id'].'|'.$profile.'|'.$subject.'|'.$html.'|'.$interviewAt.'|'.implode(',',$ids));
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO email_batches(user_id,profile_id,subject,body,interview_at,timezone,recipient_count,status,dedupe_key) VALUES(?,?,?,?,?,?,?,'preview',?)")->execute([$user['id'],$profile,$subject,$html,$interviewAt,$timezone,count($recipients),$dedupe]);
            $batch=$pdo->lastInsertId();
            $ri=$pdo->prepare("INSERT INTO email_recipients(batch_id,candidate_id,email,status) VALUES(?,?,?,'preview')");
            foreach($recipients as $recipient)if($recipient['email'])$ri->execute([$batch,$recipient['id'],$recipient['email']]);
            $pdo->commit();
            $message='Branded HTML email preview saved. Nothing was sent.';
        }
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=str_contains($e->getMessage(),'Duplicate entry')?'This exact preview already exists.':$e->getMessage();}
}

// FETCH ALL PROFILES & CANDIDATES
$profilesStmt=$pdo->prepare('SELECT wp.id, wp.user_id, wp.job_id, wp.name, wp.created_at, MAX(j.id) job_table_id, COUNT(wi.id) total FROM wishlist_profiles wp LEFT JOIN wishlist_items wi ON wi.profile_id=wp.id LEFT JOIN jobs j ON j.title=wp.name WHERE wp.user_id=? GROUP BY wp.id, wp.user_id, wp.job_id, wp.name, wp.created_at ORDER BY wp.name');
$profilesStmt->execute([$user['id']]);
$profiles=$profilesStmt->fetchAll();

$allCandidates=loadCandidateRecords();
$candidatesByJob=[];
foreach($allCandidates as $c){
    $jobId=(int)($c['job_id']??0);
    $candidatesByJob[$jobId][]=$c;
}

// Build detailed profile data map
$profileData=[];
foreach($profiles as $prof){
    $profId=(int)$prof['id'];
    $jobTableId=(int)($prof['job_table_id']??0);
    
    // Eligible applicants for this role
    $eligible=array_values(array_filter($allCandidates, function($cand) use ($prof, $jobTableId) {
        if($jobTableId > 0 && (int)($cand['job_id']??0) === $jobTableId) return true;
        return roleKey($cand['role_title']??'') === roleKey($prof['name']);
    }));

    // Wishlist items for this profile
    $itemStmt=$pdo->prepare('SELECT candidate_id, disposition FROM wishlist_items WHERE profile_id=?');
    $itemStmt->execute([$profId]);
    $statuses=[];
    foreach($itemStmt->fetchAll() as $row)$statuses[$row['candidate_id']]=$row['disposition'];

    // Locked/invited candidates
    $lockStmt=$pdo->prepare('SELECT candidate_id FROM interview_invite_locks WHERE profile_id=? AND active=1');
    $lockStmt->execute([$profId]);
    $locks=array_fill_keys($lockStmt->fetchAll(PDO::FETCH_COLUMN),true);

    $items=array_values(array_filter($eligible,fn($cand)=>isset($statuses[$cand['id']])));
    usort($items,fn($a,$b)=>(int)($b['job_match']['analyzed']??0)<=> (int)($a['job_match']['analyzed']??0)?:(($b['score']??-1)<=>($a['score']??-1)));

    $profileData[$profId]=[
        'profile'=>$prof,
        'job_id'=>$jobTableId,
        'eligible'=>$eligible,
        'statuses'=>$statuses,
        'locks'=>$locks,
        'items'=>$items
    ];
}

$activeFilter=(string)($_GET['filter']??'all');
$domain=trim((string)($brand['domain']??''));if($domain==='')$domain=parse_url((string)$pdo->query('SELECT public_url FROM jobs WHERE public_url IS NOT NULL LIMIT 1')->fetchColumn(),PHP_URL_HOST)?:($_SERVER['HTTP_HOST']??'');

$activePage='wishlist';$pageTitle='Wishlist · ResumeIQ';
$pageStyles='<style>
.page-head{padding:20px;display:flex;justify-content:space-between;align-items:center}
.filter-bar{display:flex;gap:8px;padding:10px 14px;margin:14px 0;overflow-x:auto;align-items:center;background:#fff;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,0.03)}
.filter-pill{padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;color:var(--muted);background:var(--bg);border:1px solid var(--line);text-decoration:none;transition:all 0.15s ease}
.filter-pill:hover,.filter-pill.active{background:var(--primary);color:#fff;border-color:var(--primary)}
.jobs-grid{display:flex;flex-direction:column;gap:18px;margin-top:14px}
.job-card{background:#fff;border-radius:12px;border:1px solid var(--line);box-shadow:0 2px 12px rgba(0,0,0,0.03);overflow:hidden}
.job-card-head{padding:16px 20px;background:linear-gradient(135deg,#faf9ff,#f4f0ff);border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}
.job-card-head h2{margin:0;font-size:16px;font-weight:800;color:var(--text)}
.job-card-body{padding:18px}
.candidate-table{width:100%;border-collapse:collapse}
.candidate-row{display:grid;grid-template-columns:26px minmax(0,1.2fr) 90px 100px auto;gap:12px;align-items:center;padding:12px 6px;border-bottom:1px solid var(--line)}
.candidate-row:last-child{border:0}
.candidate-row strong{font-size:13px}
.candidate-row small{color:var(--muted);display:block;font-size:11px}
.rank-badge{color:var(--primary);font-size:10px;font-weight:800;background:var(--primary-soft);padding:2px 6px;border-radius:4px;display:inline-block}
.status-pill{font-size:10px;font-weight:800;padding:3px 8px;border-radius:12px;text-transform:capitalize;display:inline-block}
.status-pill.selected{background:var(--green-soft);color:var(--green)}
.status-pill.wishlist{background:var(--primary-soft);color:var(--primary)}
.status-pill.blacklisted{background:#fff1f3;color:var(--danger)}
.status-pill.invited{background:#fff5e7;color:#8c610d}
.actions-cell{display:flex;gap:6px;justify-content:flex-end}
.mini-btn{width:32px;height:32px;border:1px solid var(--line);border-radius:8px;background:#fff;cursor:pointer;display:grid;place-items:center;font-size:12px;color:var(--text);transition:all 0.15s ease}
.mini-btn:hover{background:var(--bg);border-color:var(--muted)}
.mini-btn.danger:hover{color:var(--danger);background:#fff1f3;border-color:#ffd8df}
.add-toolbar{margin-top:14px;padding-top:14px;border-top:1px solid var(--line);display:flex;gap:10px;align-items:center}
.add-toolbar form{display:flex;gap:8px;flex:1}
.notice{padding:12px 16px;border-radius:8px;margin:10px 0;font-weight:600}
.ok{background:var(--green-soft);color:var(--green)}
.err{background:#fff1f3;color:var(--danger)}
@media(max-width:850px){.candidate-row{grid-template-columns:1fr;gap:6px;padding:14px 0}.actions-cell{justify-content:flex-start}.job-card-head{flex-direction:column;align-items:flex-start;gap:10px}}
</style>';
require __DIR__.'/views/partials/header.php';
?>

<section class="page-head">
  <div>
    <div class="kicker"><i class="fa-solid fa-heart"></i> Job-specific shortlist</div>
    <h1>Wishlist & Shortlists</h1>
    <p class="muted">Review shortlisted candidates across all job profiles at a glance and jump straight to interview outreach.</p>
  </div>
  <form method="post" style="display:flex;gap:8px">
    <input type="hidden" name="csrf" value="<?=csrfToken()?>">
    <input type="hidden" name="action" value="create_profile">
    <input class="control" name="profile_name" placeholder="New job profile title" required style="width:200px">
    <button class="btn btn-primary"><i class="fa-solid fa-plus"></i> Create Profile</button>
  </form>
</section>

<?php if($message):?><div class="notice ok"><?=htmlspecialchars($message)?></div><?php endif?>
<?php if($error):?><div class="notice err"><?=htmlspecialchars($error)?></div><?php endif?>

<!-- TOP FILTER PILLS -->
<div class="filter-bar">
  <span style="font-size:11px;font-weight:700;color:var(--muted);margin-right:4px"><i class="fa-solid fa-filter"></i> Filter:</span>
  <a href="wishlist.php?filter=all" class="filter-pill <?=$activeFilter==='all'?'active':''?>">
    All Job Profiles (<?=count($profiles)?>)
  </a>
  <?php foreach($profiles as $p): ?>
  <a href="wishlist.php?filter=<?=$p['id']?>" class="filter-pill <?=$activeFilter===(string)$p['id']?'active':''?>">
    <?=htmlspecialchars(trim($p['name']))?> (<?=(int)$p['total']?>)
  </a>
  <?php endforeach; ?>
</div>

<!-- MULTI-JOB SHORTLIST CARDS GRID -->
<div class="jobs-grid">
  <?php 
  $displayProfiles = $profiles;
  if($activeFilter !== 'all'){
    $displayProfiles = array_filter($profiles, fn($p)=>(string)$p['id']===$activeFilter);
  }

  if(empty($displayProfiles)):
  ?>
  <div class="panel" style="padding:30px;text-align:center;color:var(--muted)">
    No job profiles created yet. Use the <strong>Create Profile</strong> form above to get started.
  </div>
  <?php endif; ?>

  <?php foreach($displayProfiles as $pRow): 
    $profId = (int)$pRow['id'];
    $data = $profileData[$profId];
    $items = $data['items'];
    $eligible = $data['eligible'];
    $statuses = $data['statuses'];
    $locks = $data['locks'];
    $jobTableId = $data['job_id'];
  ?>
  <article class="job-card">
    <div class="job-card-head">
      <div>
        <h2><?=htmlspecialchars(trim($pRow['name']))?></h2>
        <small class="muted"><?=count($items)?> shortlisted · <?=count($eligible)?> eligible applicants</small>
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <?php if($jobTableId > 0): ?>
        <a href="interview_invite.php?job_id=<?=$jobTableId?>" class="btn btn-primary" style="height:36px;font-size:11px">
          <i class="fa-solid fa-paper-plane"></i> Send Invitations
        </a>
        <?php endif; ?>
      </div>
    </div>

    <div class="job-card-body">
      <?php if(empty($items)): ?>
      <div style="padding:20px;text-align:center;color:var(--muted);font-size:12px">
        No candidates added to this shortlist yet. Select an applicant below to add.
      </div>
      <?php else: ?>
      <div class="candidate-table">
        <?php foreach($items as $index=>$candidate): 
          $person = candidatePresentation($candidate);
          $status = $statuses[$candidate['id']] ?? 'wishlist';
          $isInvited = isset($locks[$candidate['id']]);
        ?>
        <div class="candidate-row" style="<?=$isInvited?'opacity:0.6;':''?>">
          <span class="rank-badge">#<?=$index+1?></span>
          <span>
            <strong>
              <a href="candidate_detail.php?id=<?=urlencode($candidate['id'])?>" target="_blank" style="color:var(--text);text-decoration:none">
                <?=htmlspecialchars($person['name'])?>
              </a>
              <a href="candidate_detail.php?id=<?=urlencode($candidate['id'])?>" target="_blank" title="Open candidate profile" style="color:var(--primary);margin-left:4px">
                <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:10px"></i>
              </a>
            </strong>
            <small><?=htmlspecialchars($candidate['email']??'No email')?> · <?=htmlspecialchars($person['role'])?></small>
          </span>
          <span class="badge" style="font-weight:800"><?=$candidate['job_match']['analyzed']?$candidate['score'].'%':'—'?></span>
          <div>
            <?php if($isInvited): ?>
              <span class="status-pill invited"><i class="fa-solid fa-envelope"></i> Invited</span>
            <?php else: ?>
              <span class="status-pill <?=htmlspecialchars($status)?>"><?=htmlspecialchars($status)?></span>
            <?php endif; ?>
          </div>
          <div class="actions-cell">
            <button class="mini-btn" type="button" onclick="statusUpdate(<?=$profId?>,'<?=htmlspecialchars($candidate['id'])?>','selected')" title="Mark Selected">
              <i class="fa-solid fa-check" style="color:var(--green)"></i>
            </button>
            <button class="mini-btn" type="button" onclick="statusUpdate(<?=$profId?>,'<?=htmlspecialchars($candidate['id'])?>','wishlist')" title="Keep Wishlist">
              <i class="fa-solid fa-heart" style="color:var(--primary)"></i>
            </button>
            <button class="mini-btn" type="button" onclick="statusUpdate(<?=$profId?>,'<?=htmlspecialchars($candidate['id'])?>','blacklisted')" title="Blacklist">
              <i class="fa-solid fa-ban" style="color:var(--danger)"></i>
            </button>
            <?php if($isOwner): ?>
            <button class="mini-btn danger" type="button" onclick="deleteCandidate(<?=$profId?>,'<?=htmlspecialchars($candidate['id'])?>','<?=htmlspecialchars(addslashes($person['name']))?>')" title="Delete Candidate">
              <i class="fa-solid fa-trash"></i>
            </button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- QUICK ADD APPLICANT FORM -->
      <div class="add-toolbar">
        <form method="post">
          <input type="hidden" name="csrf" value="<?=csrfToken()?>">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="profile_id" value="<?=$profId?>">
          <select class="control" name="candidate_id" required style="font-size:12px">
            <option value="">Add applicant to <?=htmlspecialchars(trim($pRow['name']))?> shortlist…</option>
            <?php foreach($eligible as $cand): 
              if(isset($statuses[$cand['id']])) continue;
              $pInfo = candidatePresentation($cand);
            ?>
            <option value="<?=htmlspecialchars($cand['id'])?>"><?=htmlspecialchars($pInfo['name'])?> · <?=$cand['job_match']['analyzed']?$cand['score'].'%':'Needs analysis'?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-primary" style="font-size:11px;white-space:nowrap"><i class="fa-solid fa-plus"></i> Add to shortlist</button>
        </form>
      </div>
    </div>
  </article>
  <?php endforeach; ?>
</div>

<form method="post" id="statusForm" hidden>
  <input type="hidden" name="csrf" value="<?=csrfToken()?>">
  <input type="hidden" name="action" value="status">
  <input type="hidden" name="profile_id" id="statusProfileId">
  <input name="candidate_id" id="statusCandidateId">
  <input name="status" id="statusValue">
</form>

<?php if($isOwner): ?>
<form method="post" action="candidate_delete.php" id="deleteForm" hidden>
  <input type="hidden" name="csrf" value="<?=csrfToken()?>">
  <input type="hidden" name="profile_id" id="deleteProfileId">
  <input name="candidate_id" id="deleteCandidateId">
</form>
<?php endif; ?>

<script>
function statusUpdate(profileId, candidateId, status){
  document.getElementById('statusProfileId').value = profileId;
  document.getElementById('statusCandidateId').value = candidateId;
  document.getElementById('statusValue').value = status;
  document.getElementById('statusForm').submit();
}

function deleteCandidate(profileId, candidateId, name){
  if(!confirm('Permanently delete '+name+' and related wishlist/email records? This cannot be undone.')) return;
  document.getElementById('deleteProfileId').value = profileId;
  document.getElementById('deleteCandidateId').value = candidateId;
  document.getElementById('deleteForm').submit();
}
</script>

<?php require __DIR__.'/views/partials/footer.php'; ?>
