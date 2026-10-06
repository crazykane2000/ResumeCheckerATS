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
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}

// FETCH ALL PROFILES & CANDIDATES
$profilesStmt=$pdo->prepare('SELECT wp.id, wp.user_id, wp.job_id, wp.name, wp.created_at, MAX(j.id) job_table_id, COUNT(wi.id) total FROM wishlist_profiles wp LEFT JOIN wishlist_items wi ON wi.profile_id=wp.id LEFT JOIN jobs j ON j.title=wp.name WHERE wp.user_id=? GROUP BY wp.id, wp.user_id, wp.job_id, wp.name, wp.created_at ORDER BY wp.name');
$profilesStmt->execute([$user['id']]);
$profiles=$profilesStmt->fetchAll();

$allCandidates=loadCandidateRecords();

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
    $selectedCount=0;
    $wishlistCount=0;
    $blacklistedCount=0;

    foreach($itemStmt->fetchAll() as $row){
        $statuses[$row['candidate_id']]=$row['disposition'];
        if($row['disposition']==='selected')$selectedCount++;
        elseif($row['disposition']==='wishlist')$wishlistCount++;
        elseif($row['disposition']==='blacklisted')$blacklistedCount++;
    }

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
        'items'=>$items,
        'selected_count'=>$selectedCount,
        'wishlist_count'=>$wishlistCount,
        'blacklisted_count'=>$blacklistedCount,
        'invited_count'=>count($locks)
    ];
}

$defaultProfileId = !empty($profiles) ? (int)($_GET['profile_id'] ?? $profiles[0]['id']) : 0;

$activePage='wishlist';$pageTitle='Shortlists (2-Column) · ResumeIQ';
$pageStyles='<style>
.wishlist-layout { display: grid; grid-template-columns: 320px minmax(0, 1fr); gap: 20px; align-items: start; margin-top: 14px; }
.master-panel { background: #fff; border: 1px solid var(--line); border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.03); overflow: hidden; position: sticky; top: 80px; }
.master-head { padding: 16px; background: linear-gradient(135deg,#faf9ff,#f4f0ff); border-bottom: 1px solid var(--line); }
.master-search { padding: 12px; border-bottom: 1px solid var(--line); background: var(--bg); }
.profile-list { max-height: calc(100vh - 260px); overflow-y: auto; display: flex; flex-direction: column; }
.profile-item { padding: 14px 16px; border-bottom: 1px solid var(--line); cursor: pointer; transition: all 0.15s ease; position: relative; }
.profile-item:last-child { border-bottom: 0; }
.profile-item:hover { background: #faf9ff; }
.profile-item.active { background: #f0ebff; border-left: 4px solid var(--primary); }
.profile-item-title { font-size: 14px; font-weight: 800; color: var(--text); margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center; }
.profile-item-stats { display: flex; gap: 6px; flex-wrap: wrap; }
.stat-pill { font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 10px; background: var(--bg); color: var(--muted); border: 1px solid var(--line); }
.stat-pill.fav { background: #fff0f3; color: var(--danger); border-color: #ffd8df; }
.stat-pill.total { background: var(--primary-soft); color: var(--primary); border-color: #d6cbff; }
.detail-panel { background: #fff; border: 1px solid var(--line); border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.03); padding: 20px; min-height: 500px; }
.detail-head { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--line); padding-bottom: 16px; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
.detail-title h2 { margin: 0; font-size: 20px; font-weight: 800; color: var(--text); }
.detail-title p { margin: 4px 0 0; font-size: 12px; color: var(--muted); }
.filter-tabs { display: flex; gap: 8px; margin-bottom: 16px; border-bottom: 1px solid var(--line); padding-bottom: 10px; overflow-x: auto; }
.tab-btn { padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; color: var(--muted); background: var(--bg); border: 1px solid var(--line); cursor: pointer; transition: all 0.15s ease; white-space: nowrap; }
.tab-btn:hover, .tab-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.candidate-table { width: 100%; border-collapse: collapse; }
.candidate-row { display: grid; grid-template-columns: 28px minmax(0, 1.3fr) 90px 110px auto; gap: 12px; align-items: center; padding: 14px 10px; border-bottom: 1px solid var(--line); border-radius: 8px; transition: background 0.15s ease; }
.candidate-row:hover { background: #faf9ff; }
.candidate-row:last-child { border: 0; }
.candidate-row strong { font-size: 13px; }
.candidate-row small { color: var(--muted); display: block; font-size: 11px; }
.rank-badge { color: var(--primary); font-size: 11px; font-weight: 800; background: var(--primary-soft); padding: 2px 6px; border-radius: 4px; text-align: center; }
.status-pill { font-size: 10px; font-weight: 800; padding: 3px 8px; border-radius: 12px; text-transform: capitalize; display: inline-block; text-align: center; }
.status-pill.selected { background: var(--green-soft); color: var(--green); }
.status-pill.wishlist { background: var(--primary-soft); color: var(--primary); }
.status-pill.blacklisted { background: #fff1f3; color: var(--danger); }
.status-pill.invited { background: #fff5e7; color: #8c610d; }
.actions-cell { display: flex; gap: 6px; justify-content: flex-end; }
.mini-btn { width: 32px; height: 32px; border: 1px solid var(--line); border-radius: 8px; background: #fff; cursor: pointer; display: grid; place-items: center; font-size: 12px; color: var(--text); transition: all 0.15s ease; }
.mini-btn:hover { background: var(--bg); border-color: var(--muted); }
.mini-btn.danger:hover { color: var(--danger); background: #fff1f3; border-color: #ffd8df; }
.add-toolbar { margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--line); display: flex; gap: 10px; align-items: center; }
.add-toolbar form { display: flex; gap: 10px; flex: 1; }
.empty-state { text-align: center; padding: 40px 20px; color: var(--muted); }
.notice { padding: 12px 16px; border-radius: 8px; margin: 10px 0; font-weight: 600; }
.ok { background: var(--green-soft); color: var(--green); }
.err { background: #fff1f3; color: var(--danger); }

@media (max-width: 900px) {
  .wishlist-layout { grid-template-columns: 1fr; }
  .master-panel { position: static; max-height: none; }
  .candidate-row { grid-template-columns: 1fr; gap: 8px; }
  .actions-cell { justify-content: flex-start; }
}
</style>';

require __DIR__.'/views/partials/header.php';
?>

<section class="page-head" style="padding:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px">
  <div>
    <div class="kicker"><i class="fa-solid fa-list-check"></i> Master-Detail Shortlist Workspace</div>
    <h1 style="margin:4px 0 0;font-size:24px">Wishlist & Profiles <span class="badge" style="font-size:11px;vertical-align:middle;background:var(--primary-soft);color:var(--primary)">2-Column Demo</span></h1>
    <p class="muted" style="margin:4px 0 0;font-size:13px">Select any job profile on the left panel to inspect candidates, review scores, and dispatch invitations.</p>
  </div>
  <form method="post" style="display:flex;gap:8px">
    <input type="hidden" name="csrf" value="<?=csrfToken()?>">
    <input type="hidden" name="action" value="create_profile">
    <input class="control" name="profile_name" placeholder="New job profile title..." required style="width:220px">
    <button class="btn btn-primary"><i class="fa-solid fa-plus"></i> Create Profile</button>
  </form>
</section>

<?php if($message):?><div class="notice ok"><?=htmlspecialchars($message)?></div><?php endif?>
<?php if($error):?><div class="notice err"><?=htmlspecialchars($error)?></div><?php endif?>

<div class="wishlist-layout">
  <!-- LEFT COLUMN: MASTER JOB PROFILES PANEL -->
  <aside class="master-panel">
    <div class="master-head" style="display:flex;justify-content:space-between;align-items:center">
      <strong style="font-size:14px;color:var(--text)"><i class="fa-solid fa-briefcase" style="color:var(--primary)"></i> Job Profiles</strong>
      <span class="badge" style="font-size:11px"><?=count($profiles)?> Roles</span>
    </div>
    
    <div class="master-search">
      <input type="text" id="profileSearchInput" class="control" placeholder="Search profiles…" style="font-size:12px">
    </div>

    <div class="profile-list" id="profileListContainer">
      <?php if(empty($profiles)): ?>
        <div style="padding:20px;text-align:center;color:var(--muted);font-size:12px">No profiles found.</div>
      <?php endif; ?>

      <?php foreach($profiles as $p): 
        $pId = (int)$p['id'];
        $pData = $profileData[$pId];
        $totalItems = count($pData['items']);
        $favCount = $pData['selected_count'] + $pData['wishlist_count'];
        $eligibleCount = count($pData['eligible']);
        $isActive = ($pId === $defaultProfileId);
      ?>
      <div class="profile-item <?=$isActive?'active':''?>" data-profile-id="<?=$pId?>" data-name="<?=htmlspecialchars(mb_strtolower($p['name']))?>" onclick="selectProfile(<?=$pId?>)">
        <div class="profile-item-title">
          <span><?=htmlspecialchars(trim($p['name']))?></span>
          <i class="fa-solid fa-chevron-right" style="font-size:11px;color:var(--muted)"></i>
        </div>
        <div class="profile-item-stats">
          <span class="stat-pill total" title="Shortlisted Candidates"><?=$totalItems?> Shortlisted</span>
          <span class="stat-pill fav" title="Liked & Selected Candidates"><i class="fa-solid fa-heart"></i> <?=$favCount?> Fav</span>
          <span class="stat-pill" title="Eligible Applicants"><?=$eligibleCount?> Applicants</span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </aside>

  <!-- RIGHT COLUMN: SELECTED PROFILE DETAIL PANEL -->
  <main class="detail-panel">
    <?php foreach($profiles as $p): 
      $pId = (int)$p['id'];
      $pData = $profileData[$pId];
      $items = $pData['items'];
      $eligible = $pData['eligible'];
      $statuses = $pData['statuses'];
      $locks = $pData['locks'];
      $jobTableId = $pData['job_id'];
      $isActive = ($pId === $defaultProfileId);
    ?>
    <section class="profile-workspace" id="workspace-<?=$pId?>" style="<?=$isActive?'':'display:none;'?>">
      <div class="detail-head">
        <div class="detail-title">
          <h2><?=htmlspecialchars(trim($p['name']))?></h2>
          <p><?=count($items)?> shortlisted candidates · <?=count($eligible)?> total eligible applicants</p>
        </div>
        <div>
          <?php if($jobTableId > 0): ?>
          <a href="interview_invite.php?job_id=<?=$jobTableId?>" class="btn btn-primary" style="height:38px">
            <i class="fa-solid fa-paper-plane"></i> Send Invitations
          </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- DISPOSITION FILTER TABS -->
      <div class="filter-tabs">
        <button type="button" class="tab-btn active" onclick="filterCandidates(<?=$pId?>, 'all', this)">All Shortlist (<?=count($items)?>)</button>
        <button type="button" class="tab-btn" onclick="filterCandidates(<?=$pId?>, 'selected', this)">Selected (<?=$pData['selected_count']?>)</button>
        <button type="button" class="tab-btn" onclick="filterCandidates(<?=$pId?>, 'wishlist', this)">Wishlist (<?=$pData['wishlist_count']?>)</button>
        <button type="button" class="tab-btn" onclick="filterCandidates(<?=$pId?>, 'invited', this)">Invited (<?=$pData['invited_count']?>)</button>
        <button type="button" class="tab-btn" onclick="filterCandidates(<?=$pId?>, 'blacklisted', this)">Blacklisted (<?=$pData['blacklisted_count']?>)</button>
      </div>

      <!-- CANDIDATE LIST TABLE -->
      <?php if(empty($items)): ?>
      <div class="empty-state">
        <i class="fa-solid fa-user-plus" style="font-size:32px;color:var(--muted);margin-bottom:12px;display:block"></i>
        <h3>No candidates in shortlist</h3>
        <p style="font-size:12px">Use the quick dropdown below to add eligible applicants to this job profile shortlist.</p>
      </div>
      <?php else: ?>
      <div class="candidate-table" id="table-<?=$pId?>">
        <?php foreach($items as $index=>$candidate): 
          $person = candidatePresentation($candidate);
          $status = $statuses[$candidate['id']] ?? 'wishlist';
          $isInvited = isset($locks[$candidate['id']]);
          $filterCategory = $isInvited ? 'invited' : $status;
        ?>
        <div class="candidate-row cand-item" data-disposition="<?=$filterCategory?>" style="<?=$isInvited?'opacity:0.65;':''?>">
          <span class="rank-badge">#<?=$index+1?></span>
          <span>
            <strong>
              <a href="candidate_detail.php?id=<?=urlencode($candidate['id'])?>" target="_blank" style="color:var(--text);text-decoration:none">
                <?=htmlspecialchars($person['name'])?>
              </a>
              <a href="candidate_detail.php?id=<?=urlencode($candidate['id'])?>" target="_blank" title="Open candidate profile in new tab" style="color:var(--primary);margin-left:4px">
                <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:10px"></i>
              </a>
            </strong>
            <small><?=htmlspecialchars($candidate['email']??'No email')?> · <?=htmlspecialchars($person['role'])?></small>
          </span>
          <span class="badge" style="font-weight:800;font-size:11px"><?=$candidate['job_match']['analyzed']?$candidate['score'].'%':'—'?></span>
          <div>
            <?php if($isInvited): ?>
              <span class="status-pill invited"><i class="fa-solid fa-envelope"></i> Invited</span>
            <?php else: ?>
              <span class="status-pill <?=htmlspecialchars($status)?>"><?=htmlspecialchars($status)?></span>
            <?php endif; ?>
          </div>
          <div class="actions-cell">
            <button class="mini-btn" type="button" onclick="statusUpdate(<?=$pId?>,'<?=htmlspecialchars($candidate['id'])?>','selected')" title="Mark Selected">
              <i class="fa-solid fa-check" style="color:var(--green)"></i>
            </button>
            <button class="mini-btn" type="button" onclick="statusUpdate(<?=$pId?>,'<?=htmlspecialchars($candidate['id'])?>','wishlist')" title="Keep Wishlist">
              <i class="fa-solid fa-heart" style="color:var(--primary)"></i>
            </button>
            <button class="mini-btn" type="button" onclick="statusUpdate(<?=$pId?>,'<?=htmlspecialchars($candidate['id'])?>','blacklisted')" title="Blacklist">
              <i class="fa-solid fa-ban" style="color:var(--danger)"></i>
            </button>
            <?php if($isOwner): ?>
            <button class="mini-btn danger" type="button" onclick="deleteCandidate(<?=$pId?>,'<?=htmlspecialchars($candidate['id'])?>','<?=htmlspecialchars(addslashes($person['name']))?>')" title="Delete Candidate">
              <i class="fa-solid fa-trash"></i>
            </button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- QUICK ADD APPLICANT TOOLBAR -->
      <div class="add-toolbar">
        <form method="post">
          <input type="hidden" name="csrf" value="<?=csrfToken()?>">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="profile_id" value="<?=$pId?>">
          <select class="control" name="candidate_id" required style="font-size:12px">
            <option value="">Add applicant to <?=htmlspecialchars(trim($p['name']))?> shortlist…</option>
            <?php foreach($eligible as $cand): 
              if(isset($statuses[$cand['id']])) continue;
              $pInfo = candidatePresentation($cand);
            ?>
            <option value="<?=htmlspecialchars($cand['id'])?>"><?=htmlspecialchars($pInfo['name'])?> · <?=$cand['job_match']['analyzed']?$cand['score'].'%':'Needs analysis'?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-primary" style="font-size:12px;white-space:nowrap"><i class="fa-solid fa-plus"></i> Add to shortlist</button>
        </form>
      </div>
    </section>
    <?php endforeach; ?>
  </main>
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
// Select Job Profile in Master-Detail panel
function selectProfile(profileId) {
  document.querySelectorAll('.profile-item').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.profile-workspace').forEach(el => el.style.display = 'none');
  
  const targetItem = document.querySelector(`.profile-item[data-profile-id="${profileId}"]`);
  const targetWorkspace = document.getElementById(`workspace-${profileId}`);
  
  if (targetItem) targetItem.classList.add('active');
  if (targetWorkspace) targetWorkspace.style.display = 'block';

  // Update browser URL query string without reloading page
  if (window.history.replaceState) {
    const url = new URL(window.location);
    url.searchParams.set('profile_id', profileId);
    window.history.replaceState({}, '', url);
  }
}

// Live profile search filtering in Left Panel
document.getElementById('profileSearchInput').addEventListener('input', function() {
  const query = this.value.toLowerCase().trim();
  document.querySelectorAll('.profile-item').forEach(item => {
    const name = item.getAttribute('data-name') || '';
    if (name.includes(query)) {
      item.style.display = 'block';
    } else {
      item.style.display = 'none';
    }
  });
});

// Candidate disposition filter tabs in Right Panel
function filterCandidates(profileId, filter, btnEl) {
  const workspace = document.getElementById(`workspace-${profileId}`);
  if (!workspace) return;
  
  workspace.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
  btnEl.classList.add('active');
  
  workspace.querySelectorAll('.cand-item').forEach(row => {
    const disp = row.getAttribute('data-disposition');
    if (filter === 'all' || disp === filter) {
      row.style.display = 'grid';
    } else {
      row.style.display = 'none';
    }
  });
}

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
