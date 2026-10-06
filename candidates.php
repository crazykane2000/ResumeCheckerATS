<?php
require_once __DIR__.'/lib/auth.php';
requireAuth();
require_once __DIR__.'/lib/workspace.php';
require_once __DIR__.'/lib/application_match.php';
require_once __DIR__.'/lib/candidate_activity.php';

$pdo=db();
$user=currentUser();
$message='';
$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verifyCsrf($_POST['csrf']??'')){
        http_response_code(403);
        exit('Invalid request.');
    }
    try{
        $jobId=(int)($_POST['job_id']??0);
        $profileId=(int)($_POST['profile_id']??0);
        $candidateIds=array_values(array_unique(array_filter(array_map('strval',$_POST['candidate_ids']??[]))));
        if(!$jobId && !empty($candidateIds)){
            $firstCandStmt=$pdo->prepare('SELECT job_id FROM candidates WHERE id=? AND job_id IS NOT NULL AND job_id > 0');
            $firstCandStmt->execute([$candidateIds[0]]);
            $jobId=(int)$firstCandStmt->fetchColumn();
        }
        if($jobId > 0 && !$profileId){
            $profileCheck=$pdo->prepare('SELECT id FROM wishlist_profiles WHERE job_id=? AND user_id=? ORDER BY id LIMIT 1');
            $profileCheck->execute([$jobId,$user['id']]);
            $profileId=(int)$profileCheck->fetchColumn();
            if(!$profileId){
                $jobCheck=$pdo->prepare('SELECT title FROM jobs WHERE id=?');
                $jobCheck->execute([$jobId]);
                $jobTitle=trim((string)$jobCheck->fetchColumn());
                if($jobTitle==='')$jobTitle='Candidates Wishlist';
                $pdo->prepare('INSERT INTO wishlist_profiles(job_id,user_id,name) VALUES(?,?,?) ON DUPLICATE KEY UPDATE job_id=VALUES(job_id)')->execute([$jobId,$user['id'],$jobTitle]);
                $profileCheck=$pdo->prepare('SELECT id FROM wishlist_profiles WHERE job_id=? AND user_id=? ORDER BY id LIMIT 1');
                $profileCheck->execute([$jobId,$user['id']]);
                $profileId=(int)$profileCheck->fetchColumn();
            }
        }

        $valid=[];
        if($candidateIds && $profileId){
            $marks=implode(',',array_fill(0,count($candidateIds),'?'));
            $candidateCheck=$pdo->prepare("SELECT c.id FROM candidates c WHERE c.id IN ($marks) AND NOT EXISTS(SELECT 1 FROM interview_invite_locks il JOIN wishlist_profiles wp ON wp.id=il.profile_id WHERE il.candidate_id=c.id AND il.active=1 AND wp.user_id=?)");
            $candidateCheck->execute(array_merge($candidateIds,[$user['id']]));
            $valid=array_column($candidateCheck->fetchAll(),'id');
        }

        if($profileId){
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM wishlist_items WHERE profile_id=? AND disposition='wishlist'")->execute([$profileId]);
            $save=$pdo->prepare("INSERT INTO wishlist_items(profile_id,candidate_id,disposition) VALUES(?,?,'wishlist') ON DUPLICATE KEY UPDATE disposition=IF(disposition IN ('selected','blacklisted'),disposition,'wishlist')");
            foreach($valid as $candidateId)$save->execute([$profileId,$candidateId]);
            $pdo->prepare("INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,'wishlist.bulk_update','wishlist_profile',?,?)")->execute([$user['id'],(string)$profileId,json_encode(['job_id'=>$jobId,'wishlist_count'=>count($valid)])]);
            $pdo->commit();
            $message=count($valid).' candidates saved to favourites.';
        }
        if(($_POST['action']??'save')==='invite' && $jobId > 0){
            header('Location: interview_invite.php?job_id='.$jobId);
            exit;
        }
        if($jobId > 0)$_GET['job']=$jobId;
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $error=$e->getMessage();
    }
}

$jobsStmt=$pdo->prepare("SELECT j.id,j.title,j.status,j.required_skills_json,COUNT(DISTINCT c.id) applicants,wp.id profile_id,wp.required_skills_json profile_skills,COUNT(DISTINCT CASE WHEN wi.disposition='wishlist' THEN wi.candidate_id END) wishlist_count FROM jobs j LEFT JOIN candidates c ON c.job_id=j.id LEFT JOIN wishlist_profiles wp ON wp.job_id=j.id AND wp.user_id=? LEFT JOIN wishlist_items wi ON wi.profile_id=wp.id GROUP BY j.id,j.title,j.status,j.required_skills_json,wp.id,wp.required_skills_json ORDER BY applicants DESC,j.title");
$jobsStmt->execute([$user['id']]);
$jobs=$jobsStmt->fetchAll();

$jobMap=[];
foreach($jobs as $job){$jobMap[(int)$job['id']]=$job;}

$activeJobId=isset($_GET['job']) ? (int)$_GET['job'] : 0;
$activeJob=$jobMap[$activeJobId]??null;

$items=loadCandidateRecords();

$candidates=[];
$favourites=[];
$inviteLocks=[];
$skills=[];
$sources=[];
$oldCount=0;
$analyzedCount=0;
$drawerData=[];

foreach($items as $candidate){
    $cJobId=(int)($candidate['job_id']??0);
    if($activeJobId > 0 && $cJobId !== $activeJobId){
        continue;
    }

    $cJob=$jobMap[$cJobId]??null;
    $cRequired=$cJob?(json_decode($cJob['profile_skills']?:$cJob['required_skills_json']?:'[]',true)?:[]):[];
    $scoringJob=$cJob??[];
    if(!empty($cRequired))$scoringJob['required_skills_json']=json_encode($cRequired);
    
    $match=applicationEvidenceMatch($candidate,$scoringJob);
    if(!empty($match['analyzed']))$analyzedCount++;
    
    $old=candidateIsOldApplication($candidate);
    if($old)$oldCount++;
    
    $exp=$candidate['experience']??[];
    $candName=candidatePresentation($candidate);

    foreach($candidate['skills']??[] as $skill)if(trim((string)$skill)!=='')$skills[mb_strtolower(trim((string)$skill))]=trim((string)$skill);
    if(trim((string)($candidate['source']??''))!=='')$sources[(string)$candidate['source']]=(string)$candidate['source'];

    $cand=['id'=>$candidate['id'],'job_id'=>$cJobId,'job_title'=>trim((string)($candidate['job_title']??$candName['role']??'General')),'name'=>$candName['name'],'created_at'=>(string)($candidate['created_at']??''),'role'=>$candName['role'],'email'=>$candidate['email'],'phone'=>$candidate['phone'],'country_name'=>$candidate['country_name'],'source'=>$candidate['source']??'Unknown','stage'=>$candidate['stage']??'Applied','skills'=>$candidate['skills']??[],'experience'=>$exp,'age'=>candidateApplicationAge($candidate),'old'=>$old,'job_score'=>$match['score'],'analyzed'=>(bool)($match['analyzed']??false),'matched'=>array_column(array_filter($match['mapping']??[],fn($item)=>($item['status']??'')==='experience_backed'),'requirement'),'review'=>array_column(array_filter($match['mapping']??[],fn($item)=>in_array($item['status']??'',['skills_only','related_review'],true)),'requirement'),'missing'=>array_column(array_filter($match['mapping']??[],fn($item)=>($item['status']??'')==='not_found'),'requirement'),'benefits'=>array_slice($match['preferred_matched']??[],0,16),'breakdown'=>$match['breakdown']??[],'maximum'=>$match['maximum']??[]];
    $candidates[]=$cand;

    $drawerData[$candidate['id']]=['name'=>$candName['name'],'role'=>$candName['role'],'job'=>$cand['job_title'],'email'=>$candidate['email']?:'Not available','phone'=>$candidate['phone']?:'Not available','country'=>$candidate['country_name']?:'Not available','source'=>$candidate['source']??'Unknown','stage'=>$candidate['stage']??'Applied','created_at'=>$candidate['created_at']??'','age'=>candidateApplicationAge($candidate),'old'=>$old,'score'=>$match['score'],'analyzed'=>(bool)($match['analyzed']??false),'breakdown'=>$match['breakdown']??[],'maximum'=>$match['maximum']??[],'matched'=>$cand['matched'],'review'=>$cand['review'],'missing'=>$cand['missing'],'benefits'=>$cand['benefits'],'months'=>(int)($exp['total_months']??0),'confidence'=>$exp['confidence']??'Not available','jobs'=>$exp['jobs']??[],'gaps'=>$exp['gaps']??[],'skill_months'=>$exp['skill_months']??[],'detail_url'=>'candidate_detail.php?id='.urlencode($candidate['id']),'resume_url'=>'resume_file.php?id='.urlencode($candidate['id']),'text_url'=>'candidate_drawer_api.php?id='.urlencode($candidate['id'])];
}

usort($candidates,fn($a,$b)=>(int)$b['analyzed']<=>(int)$a['analyzed']?:(($b['job_score']??-1)<=> ($a['job_score']??-1)?:strcmp($a['name'],$b['name'])));

if($activeJob && !empty($activeJob['profile_id'])){
    $favStmt=$pdo->prepare('SELECT candidate_id,disposition FROM wishlist_items WHERE profile_id=?');
    $favStmt->execute([$activeJob['profile_id']]);
    foreach($favStmt->fetchAll() as $row)$favourites[$row['candidate_id']]=$row['disposition'];

    $lockStmt=$pdo->prepare('SELECT candidate_id FROM interview_invite_locks WHERE profile_id=? AND active=1');
    $lockStmt->execute([$activeJob['profile_id']]);
    $inviteLocks=array_fill_keys($lockStmt->fetchAll(PDO::FETCH_COLUMN),true);
}

natcasesort($skills);
natcasesort($sources);

$totalApplicants=count($items);
$totalWishlist=array_sum(array_map(fn($job)=>(int)$job['wishlist_count'],$jobs));

$activePage='candidates';
$pageTitle='Candidates intelligence · ResumeIQ';
$pageStyles=<<<'CSS'
<style>
.d2-head{position:relative;overflow:hidden;display:flex;justify-content:space-between;align-items:end;padding:26px;background:radial-gradient(circle at 82% 15%,#e9e1ff 0,transparent 28%),linear-gradient(120deg,#fff 48%,#f4f1ff 78%,#edfff8)}.d2-head:after{content:"";position:absolute;right:22%;bottom:-46px;width:130px;height:130px;border:24px solid #ffffff80;border-radius:50%}.d2-head h1{margin:5px 0;font-size:26px}.muted,.d2-head p{color:var(--muted)}.d2-summary{display:flex;gap:24px}.d2-summary strong{display:block;font-size:23px}.d2-summary span{font-size:10px;color:var(--muted)}.d2-layout{display:grid;grid-template-columns:290px minmax(0,1fr);gap:12px;margin-top:12px;align-items:start}.job-list,.candidate-panel{padding:14px;box-shadow:0 12px 34px rgba(35,25,80,.055)}.job-list{position:sticky;top:10px;max-height:calc(100vh - 24px);overflow:auto}.section-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}.section-title h2{font-size:14px;margin:0}.job-row{display:grid;grid-template-columns:1fr auto;gap:10px;width:100%;padding:12px;margin-bottom:7px;text-align:left;border:1px solid var(--line);border-radius:8px;background:#fff;cursor:pointer}.job-row.active,.job-row:hover{border-color:#bfb0ff;background:#f8f6ff}.job-row strong{display:block;font-size:12px}.job-row small{color:var(--muted)}.job-counts{text-align:right;white-space:nowrap}.candidate-table{width:100%;border-collapse:collapse}.candidate-table th,.candidate-table td{padding:11px 10px;text-align:left;border-bottom:1px solid var(--line)}.candidate-table th{position:sticky;top:0;z-index:2;font-size:9px;color:var(--muted);text-transform:uppercase;background:#fff}.candidate-table td{font-size:11px}.candidate-table tbody tr{cursor:pointer}.candidate-table tbody tr:hover{background:#faf9ff}.candidate-table tr.top-five{background:linear-gradient(90deg,#fff,#f6f2ff)}.candidate-table tr.reserve{opacity:.65}.candidate-table tr.reserve:hover,.candidate-table tr.reserve:focus-within{opacity:1}.candidate-name strong,.candidate-name small{display:block}.candidate-name small{color:var(--muted)}.rank{color:var(--primary);font-weight:700;font-size:9px}.score{color:var(--primary);font-weight:700;font-size:14px}.select-box{width:17px;height:17px;accent-color:var(--primary)}.save-bar{position:fixed;right:28px;bottom:26px;z-index:55;display:flex;align-items:center;gap:12px;padding:10px 12px 10px 16px;border:1px solid #d9d0ff;border-radius:10px;background:#fff;box-shadow:0 16px 45px rgba(48,31,110,.22)}.save-bar .btn{box-shadow:0 8px 22px rgba(111,69,255,.25)}.notice{padding:10px;margin-top:10px;background:#eafaf4;color:#087a55}.notice.error{background:#fff0f2;color:#b4233c}.empty{padding:34px;text-align:center;color:var(--muted)}
.candidate-filters{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:9px;padding:12px;margin-bottom:12px;border:1px solid var(--line);border-radius:8px;background:#fafafe}.filter-field label{display:block;margin-bottom:5px;font-size:9px;color:var(--muted)}.filter-field .control{height:36px;font-size:10px}.filter-count{display:flex;align-items:end;padding-bottom:9px;color:var(--muted);font-size:10px}
.old-label{display:inline-flex;align-items:center;gap:4px;padding:4px 6px;border-radius:5px;background:#fff0f2;color:#b4233c;font-size:9px;font-weight:800}.fresh-label{color:var(--muted);font-size:9px}
.drawer-shade{position:fixed;inset:0;background:#17122540;backdrop-filter:blur(2px);z-index:70;opacity:0;pointer-events:none;transition:.2s}.drawer-shade.open{opacity:1;pointer-events:auto}.candidate-drawer{position:fixed;right:0;top:0;bottom:0;width:min(590px,92vw);background:#fff;z-index:71;box-shadow:-20px 0 60px #23195029;transform:translateX(102%);transition:.24s;display:flex;flex-direction:column}.candidate-drawer.open{transform:none}.drawer-head{display:flex;justify-content:space-between;padding:22px;border-bottom:1px solid var(--line);background:linear-gradient(125deg,#fff,#f1edff)}.drawer-head h2{font-size:21px;margin:5px 0}.icon-btn{width:36px;height:36px;border:1px solid var(--line);background:#fff;border-radius:8px}.drawer-score{display:grid;grid-template-columns:190px 1fr;gap:20px;align-items:center;padding:18px 22px}.gauge{position:relative;width:180px;height:98px;overflow:hidden}.gauge-ring{position:absolute;width:180px;height:180px;border-radius:50%;background:conic-gradient(from 270deg,#ff4b2b 0 18%,#ffac12 18% 35%,#ffe600 35% 50%,#9bd000 50% 72%,#17ac16 72% 100%);clip-path:inset(0 0 50%)}.gauge-cut{position:absolute;left:28px;top:28px;width:124px;height:124px;background:#fff;border-radius:50%}.gauge-needle{position:absolute;left:90px;bottom:7px;width:67px;height:3px;background:#6f45ff;transform-origin:left;transform:rotate(var(--needle))}.gauge-needle:before{content:'';position:absolute;left:-6px;top:-5px;width:13px;height:13px;border-radius:50%;background:#6f45ff}.gauge-value{position:absolute;left:0;right:0;bottom:0;text-align:center;font-size:24px;font-weight:700}.score-copy p{font-size:11px;color:var(--muted);line-height:1.6}.drawer-tabs{display:grid;grid-template-columns:repeat(4,1fr);padding:8px 18px;gap:6px;background:#fbfaff;border-bottom:1px solid var(--line)}.drawer-tabs button{display:flex;align-items:center;justify-content:center;gap:6px;padding:10px 5px;border:1px solid transparent;border-radius:7px;font-size:10px;color:var(--muted);font-weight:600}.drawer-tabs button.active{background:#f0ebff;border-color:#d9ceff;color:var(--primary);font-weight:700}.drawer-body{padding:18px 22px 90px;overflow:auto;flex:1}.tab-pane{display:none}.tab-pane.active{display:block}.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px}.detail-card{padding:12px;border:1px solid var(--line);border-radius:8px}.detail-card small{display:block;color:var(--muted);margin-bottom:5px}.overview-section{margin-top:16px;padding-top:14px;border-top:1px solid var(--line)}.overview-section h3{margin:0 0 10px;font-size:12px}.chips{display:flex;flex-wrap:wrap;gap:6px}.chip{padding:5px 7px;border-radius:5px;background:#eafaf4;color:#087a55;font-size:9px}.chip.missing{background:#fff0f2;color:#c43b51}.chip.extra{background:#eef4ff;color:#3565bc}.gap-highlight{display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid #f2cc82;border-radius:8px;background:#fff8e8;color:#81590d}.gap-highlight.clear{border-color:#bfe9d8;background:#edfaf5;color:#087a55}.gap-highlight.old-flag{border-color:#ffccd5;background:#fff0f3;color:#b4233c}.gap-highlight i{margin-top:2px}.gap-list{display:grid;gap:7px}.composition-list{display:grid;gap:9px}.composition-row{display:grid;grid-template-columns:125px minmax(0,1fr) 45px;gap:8px;align-items:center;font-size:10px}.composition-track,.skill-track{height:8px;border-radius:5px;background:#eceaf2;overflow:hidden}.composition-track i{display:block;height:100%;background:linear-gradient(90deg,#6f45ff,#a58fff)}.skill-graph{display:grid;gap:9px}.skill-graph-row{display:grid;grid-template-columns:105px minmax(0,1fr) 42px;gap:8px;align-items:center;font-size:10px}.skill-track i{display:block;height:100%;background:linear-gradient(90deg,#6f45ff,#24bd87)}.career-timeline{position:relative;margin-left:6px;padding-left:20px;border-left:2px solid #d9d0ff}.career-event{position:relative;padding:0 0 18px}.career-event:before{content:"";position:absolute;left:-26px;top:2px;width:10px;height:10px;border-radius:50%;background:#6f45ff;box-shadow:0 0 0 4px #eee9ff}.career-event strong{display:block;margin-bottom:4px}.career-event p{margin:0;color:var(--muted);font-size:10px;line-height:1.5}.resume-text-state{padding:12px;border:1px dashed var(--line);border-radius:8px;color:var(--muted);font-size:10px}.resume-raw{max-height:360px;overflow:auto;margin:10px 0 0;padding:12px;border:1px solid var(--line);border-radius:8px;background:#f8f8fc;white-space:pre-wrap;word-break:break-word;font:10px/1.6 Consolas,monospace}.drawer-actions{display:flex;gap:8px;padding:14px 22px;border-top:1px solid var(--line)}.drawer-actions .btn-primary{margin-left:auto}.editable-skill{display:block}.filter-field.skill-edit select{display:none}@media(max-width:1100px){.candidate-filters{grid-template-columns:repeat(2,minmax(140px,1fr))}}@media(max-width:900px){.d2-layout{grid-template-columns:1fr}.job-list-items{display:flex;overflow:auto;gap:7px}.job-row{min-width:240px}}@media(max-width:650px){.d2-head{align-items:flex-start;flex-direction:column}.candidate-filters{grid-template-columns:1fr}.candidate-table th:nth-child(3),.candidate-table td:nth-child(3){display:none}.drawer-score,.detail-grid{grid-template-columns:1fr}.drawer-tabs{grid-template-columns:1fr 1fr}}
</style>
CSS;

require __DIR__.'/views/partials/header.php';
?>
<section class="panel d2-head">
    <div>
        <div class="kicker"><i class="fa-solid fa-users"></i> Candidate Intelligence View</div>
        <h1>Candidate Directory</h1>
        <p>Job-specific evidence, applicant scores, and 6-month stale application indicators. Advisory screening only.</p>
    </div>
    <div class="d2-summary">
        <div><strong><?=$totalApplicants?></strong><span>Total candidates</span></div>
        <div><strong><?=$analyzedCount?></strong><span>Analyzed</span></div>
        <div><strong><?=$totalWishlist?></strong><span>In wishlist</span></div>
        <div><strong style="color:#b4233c"><?=$oldCount?></strong><span>Old applications (6+m)</span></div>
    </div>
</section>

<?php if($message):?><div class="notice"><?=htmlspecialchars($message)?></div><?php endif?>
<?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif?>

<section class="d2-layout">
    <aside class="panel job-list">
        <div class="section-title">
            <h2>Jobs by volume</h2>
            <small>Highest first</small>
        </div>
        <div class="job-list-items">
            <button class="job-row <?=$activeJobId===0?'active':''?>" onclick="location.href='candidates.php'">
                <span>
                    <strong>All Candidates</strong>
                    <small>Entire workspace pool</small>
                </span>
                <span class="job-counts">
                    <b><?=$totalApplicants?></b> candidates<br>
                    <small><?=$totalWishlist?> wishlist</small>
                </span>
            </button>
            <?php foreach($jobs as $job):?>
                <button class="job-row <?=$activeJobId===(int)$job['id']?'active':''?>" onclick="location.href='candidates.php?job=<?=(int)$job['id']?>'">
                    <span>
                        <strong><?=htmlspecialchars($job['title'])?></strong>
                        <small><?=htmlspecialchars(ucfirst($job['status']))?></small>
                    </span>
                    <span class="job-counts">
                        <b><?=(int)$job['applicants']?></b> applied<br>
                        <small><?=(int)$job['wishlist_count']?> wishlist</small>
                    </span>
                </button>
            <?php endforeach?>
        </div>
    </aside>

    <main class="panel candidate-panel">
        <div class="candidate-filters">
            <div class="filter-field">
                <label for="filterSearch">Search</label>
                <input class="control" id="filterSearch" placeholder="Name, role or skill">
            </div>
            <div class="filter-field">
                <label for="filterJob">Applied job</label>
                <select class="control" id="filterJob" onchange="if(this.value){location.href='candidates.php?job='+this.value}else{location.href='candidates.php'}">
                    <option value="">All jobs</option>
                    <?php foreach($jobs as $job):?>
                        <option value="<?=$job['id']?>" <?=$activeJobId===(int)$job['id']?'selected':''?>><?=htmlspecialchars($job['title'])?></option>
                    <?php endforeach?>
                </select>
            </div>
            <div class="filter-field">
                <label for="filterSkill">Skill</label>
                <select class="control" id="filterSkill">
                    <option value="">All skills</option>
                    <?php foreach($skills as $key=>$label):?>
                        <option value="<?=htmlspecialchars($key)?>"><?=htmlspecialchars($label)?></option>
                    <?php endforeach?>
                </select>
            </div>
            <div class="filter-field">
                <label for="filterExperience">Minimum experience</label>
                <select class="control" id="filterExperience">
                    <option value="0">Any experience</option>
                    <option value="12">1+ year</option>
                    <option value="36">3+ years</option>
                    <option value="60">5+ years</option>
                    <option value="96">8+ years</option>
                </select>
            </div>
            <div class="filter-field">
                <label for="filterSource">Source</label>
                <select class="control" id="filterSource">
                    <option value="">All sources</option>
                    <?php foreach($sources as $source):?>
                        <option><?=htmlspecialchars($source)?></option>
                    <?php endforeach?>
                </select>
            </div>
            <div class="filter-field">
                <label for="filterScore">Minimum score</label>
                <select class="control" id="filterScore">
                    <option value="0">Any score</option>
                    <option value="analyzed">Analyzed only</option>
                    <option value="50">50%+</option>
                    <option value="70">70%+</option>
                </select>
            </div>
            <div class="filter-field">
                <label for="filterAge">Application age</label>
                <select class="control" id="filterAge">
                    <option value="">Any age</option>
                    <option value="old">Old (6+ months)</option>
                    <option value="recent">Recent (within 6m)</option>
                </select>
            </div>
            <div class="filter-field">
                <label for="filterStage">Stage</label>
                <select class="control" id="filterStage">
                    <option value="">All stages</option>
                    <?php foreach(['Applied','Screening','Interview','Offer','Rejected'] as $stage):?>
                        <option><?=htmlspecialchars($stage)?></option>
                    <?php endforeach?>
                </select>
            </div>
            <div class="filter-field">
                <label for="filterSort">Sort candidates</label>
                <select class="control" id="filterSort">
                    <option value="score_desc">Highest score first</option>
                    <option value="experience_desc">Most experience first</option>
                    <option value="created_desc">Created time: newest first</option>
                    <option value="created_asc">Created time: oldest first</option>
                    <option value="name_asc">Name A–Z</option>
                </select>
            </div>
            <div class="filter-count">
                <span id="filterCount"><?=count($candidates)?> candidates</span>
            </div>
        </div>

        <div class="section-title">
            <div>
                <h2><?=htmlspecialchars($activeJob['title']??'All Candidates')?></h2>
                <small><?=count($candidates)?> shown · <?=count($favourites)?> in wishlist</small>
            </div>
            <span class="tag">Top 5 highlighted</span>
        </div>

        <?php if(!$candidates):?>
            <div class="empty">No candidates found matching the active selection.</div>
        <?php else:?>
            <form method="post" id="favouriteForm">
                <input type="hidden" name="csrf" value="<?=csrfToken()?>">
                <input type="hidden" name="job_id" value="<?=$activeJobId?>">
                <input type="hidden" name="profile_id" value="<?=(int)($activeJob['profile_id']??0)?>">
                <div style="overflow:auto">
                    <table class="candidate-table">
                        <thead>
                            <tr>
                                <th></th>
                                <th>Candidate</th>
                                <th>Applied Job</th>
                                <th>Stage</th>
                                <th>Rank</th>
                                <th>Experience</th>
                                <th>Job match</th>
                                <th>Applied</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($candidates as $i=>$candidate):
                                $locked=in_array($favourites[$candidate['id']]??'',['selected','blacklisted'],true);
                                $months=(int)($candidate['experience']['total_months']??0);
                                $createdTime=$candidate['created_at']!==''?strtotime($candidate['created_at']):false;
                            ?>
                                <tr class="<?=$i<5?'top-five':'reserve'?>" tabindex="0" data-candidate="<?=htmlspecialchars($candidate['id'])?>" data-find="<?=htmlspecialchars(mb_strtolower($candidate['name'].' '.$candidate['role'].' '.implode(' ',$candidate['skills'])))?>" data-skills="<?=htmlspecialchars('|'.mb_strtolower(implode('|',$candidate['skills'])).'|')?>" data-created="<?=htmlspecialchars($candidate['created_at'],ENT_QUOTES,'UTF-8')?>" data-months="<?=$months?>" data-source="<?=htmlspecialchars($candidate['source'])?>" data-score="<?=$candidate['analyzed']?(int)$candidate['job_score']:-1?>" data-analyzed="<?=$candidate['analyzed']?'1':'0'?>" data-stage="<?=htmlspecialchars($candidate['stage'])?>" data-old="<?=$candidate['old']?'1':'0'?>" data-name="<?=htmlspecialchars(mb_strtolower($candidate['name']))?>">
                                    <td>
                                        <input class="select-box" type="checkbox" name="candidate_ids[]" value="<?=htmlspecialchars($candidate['id'])?>" <?=($favourites[$candidate['id']]??'')==='wishlist'?'checked':''?> <?=$locked?'disabled':''?> onclick="event.stopPropagation()">
                                    </td>
                                    <td class="candidate-name">
                                        <strong><?=htmlspecialchars($candidate['name'])?></strong>
                                        <small><?=htmlspecialchars($candidate['email']?:'No email')?></small>
                                    </td>
                                    <td><?=htmlspecialchars($candidate['job_title'])?></td>
                                    <td><strong><?=htmlspecialchars($candidate['stage'])?></strong></td>
                                    <td><span class="rank"><?=$i<5?'TOP '.($i+1):'RESERVE '.($i-4)?></span></td>
                                    <td class="experience-cell"><?=$months?round($months/12,1).' yrs':'Uncertain'?></td>
                                    <td><span class="score"><?=$candidate['analyzed']?$candidate['job_score'].'%':'Needs analysis'?></span></td>
                                    <td style="white-space:nowrap">
                                        <?php if($candidate['old']):?>
                                            <span class="old-label"><i class="fa-solid fa-triangle-exclamation"></i> Old application</span>
                                            <small style="display:block;margin-top:3px;color:var(--muted)"><?=htmlspecialchars($candidate['age'])?></small>
                                        <?php else:?>
                                            <span class="fresh-label"><?=$createdTime!==false?htmlspecialchars(date('d M Y',$createdTime)):'Unknown'?></span>
                                        <?php endif?>
                                    </td>
                                    <td><i class="fa-solid fa-chevron-right muted"></i></td>
                                </tr>
                            <?php endforeach?>
                        </tbody>
                    </table>
                </div>
                <div class="save-bar">
                    <span class="muted"><b id="selectedCount">0</b> selected</span>
                    <button class="btn btn-primary"><i class="fa-solid fa-heart"></i> Save favourites</button>
                </div>
            </form>
        <?php endif?>
    </main>
</section>

<div class="drawer-shade" id="drawerShade"></div>
<aside class="candidate-drawer" id="candidateDrawer">
    <div class="drawer-head">
        <div>
            <div class="kicker" id="drawerRank"></div>
            <h2 id="drawerName"></h2>
            <span class="muted" id="drawerRole"></span>
        </div>
        <button class="icon-btn" id="drawerClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="drawer-score">
        <div class="gauge">
            <div class="gauge-ring"></div>
            <div class="gauge-cut"></div>
            <div class="gauge-needle" id="gaugeNeedle"></div>
            <div class="gauge-value" id="gaugeValue"></div>
        </div>
        <div class="score-copy">
            <strong>Job-specific match</strong>
            <p>Evidence v1: required skills, requirement-level experience, preferred skills, role similarity and evidence strength. Advisory only.</p>
        </div>
    </div>
    <div class="drawer-tabs">
        <button class="active" data-tab="overview"><i class="fa-solid fa-gauge-high"></i> Overview</button>
        <button data-tab="evidence"><i class="fa-solid fa-list-check"></i> Evidence</button>
        <button data-tab="career"><i class="fa-solid fa-briefcase"></i> Career</button>
        <button data-tab="resume"><i class="fa-solid fa-file-lines"></i> Resume Data</button>
    </div>
    <div class="drawer-body">
        <section class="tab-pane active" data-pane="overview" id="paneOverview"></section>
        <section class="tab-pane" data-pane="evidence" id="paneEvidence"></section>
        <section class="tab-pane" data-pane="career" id="paneCareer"></section>
        <section class="tab-pane" data-pane="resume" id="paneResume"></section>
    </div>
    <div class="drawer-actions">
        <button class="btn" id="drawerFavourite"><i class="fa-regular fa-heart"></i> Toggle favourite</button>
        <a class="btn" id="drawerResume" target="_blank">Resume</a>
        <a class="btn btn-primary" id="drawerDetail">Full profile & activity</a>
    </div>
</aside>

<script>
const candidateData=<?=json_encode($drawerData,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const drawer=document.getElementById('candidateDrawer');
const shade=document.getElementById('drawerShade');
const drawerRank=document.getElementById('drawerRank');
const drawerName=document.getElementById('drawerName');
const drawerRole=document.getElementById('drawerRole');
const drawerClose=document.getElementById('drawerClose');
const gaugeNeedle=document.getElementById('gaugeNeedle');
const gaugeValue=document.getElementById('gaugeValue');
const paneOverview=document.getElementById('paneOverview');
const paneEvidence=document.getElementById('paneEvidence');
const paneCareer=document.getElementById('paneCareer');
const paneResume=document.getElementById('paneResume');
const drawerResume=document.getElementById('drawerResume');
const drawerDetail=document.getElementById('drawerDetail');
const drawerFavourite=document.getElementById('drawerFavourite');

const esc=v=>{let d=document.createElement('div');d.textContent=v??'';return d.innerHTML},
chips=(a,t='')=>a.length?'<div class="chips">'+a.map(x=>'<span class="chip '+t+'">'+esc(x)+'</span>').join('')+'</div>':'<p class="muted">None recorded.</p>';

const label=key=>key.replaceAll('_',' ').replace(/\b\w/g,letter=>letter.toUpperCase());
const composition=c=>{const rows=Object.entries(c.breakdown||{}).map(([key,value])=>{const maximum=Number(c.maximum?.[key]||1),percent=Math.round(Number(value)/maximum*100);return `<div class="composition-row"><span>${esc(label(key))}</span><div class="composition-track"><i style="width:${percent}%"></i></div><b>${percent}%</b></div>`}).join('');return rows||'<p class="muted">Score composition is unavailable.</p>'};
const gaps=c=>{if(!(c.gaps||[]).length)return '<div class="gap-highlight clear"><i class="fa-solid fa-circle-check"></i><div><strong>No employment gap of 2+ months detected</strong><br><small>Based only on reliably parsed dated roles.</small></div></div>';return '<div class="gap-list">'+c.gaps.map(gap=>`<div class="gap-highlight"><i class="fa-solid fa-triangle-exclamation"></i><div><strong>${Number(gap.months)||0} month gap</strong><br><small>${esc(gap.after||'Unknown')} to ${esc(gap.before||'Unknown')}</small></div></div>`).join('')+'</div>'};
const timeline=c=>{if(!(c.jobs||[]).length)return '<p class="muted">No reliable dated employment timeline extracted.</p>';return '<div class="career-timeline">'+c.jobs.map(job=>`<div class="career-event"><strong>${esc(job.start||'Unknown')} — ${esc(job.end||'Unknown')}</strong><p>${esc(job.label||'Employment record')}</p><p>${Number(job.months||0)} months${(job.skills||[]).length?' · '+esc(job.skills.join(', ')):''}</p></div>`).join('')+'</div>'};
const skillGraph=c=>{const entries=Object.entries(c.skill_months||{}),maximum=Math.max(1,...entries.map(([,months])=>Number(months)));if(!entries.length)return '<p class="muted">No reliable skill tenure is available.</p>';return '<div class="skill-graph">'+entries.map(([skill,months])=>`<div class="skill-graph-row"><span>${esc(skill)}</span><div class="skill-track"><i style="width:${Math.round(Number(months)/maximum*100)}%"></i></div><b>${Math.round(Number(months)/12*10)/10}y</b></div>`).join('')+'</div>'};

function openCandidate(id){
    let c=candidateData[id];
    if(!c)return;
    drawer.dataset.id=id;
    if(drawerName)drawerName.textContent=c.name;
    if(drawerRole)drawerRole.textContent=c.job+' · '+c.stage;
    if(drawerRank)drawerRank.innerHTML=c.old?'<span class="old-label"><i class="fa-solid fa-triangle-exclamation"></i> 🚩 Old Application · '+esc(c.age)+'</span>':'Applied '+esc(c.age);
    if(gaugeValue)gaugeValue.textContent=c.analyzed?c.score+'/100':'Needs analysis';
    if(gaugeNeedle)gaugeNeedle.style.setProperty('--needle',(-180+(c.analyzed?c.score:0)*1.8)+'deg');

    const oldFlagBox=c.old?`<div class="gap-highlight old-flag" style="margin-bottom:14px"><i class="fa-solid fa-triangle-exclamation"></i><div><strong>OLD APPLICATION FLAG (6+ months old)</strong><br><small>Candidate registered ${esc(c.age)} (${esc(c.created_at)}). Details may be stale.</small></div></div>`:'';

    if(paneOverview)paneOverview.innerHTML=oldFlagBox+'<div class="detail-grid"><div class="detail-card"><small>Stage</small><strong>'+esc(c.stage)+'</strong></div><div class="detail-card"><small>Applied</small><strong>'+esc(c.created_at||'Unknown')+'</strong></div><div class="detail-card"><small>Experience</small><strong>'+(c.months?Math.round(c.months/12*10)/10+' years':'Uncertain')+'</strong></div><div class="detail-card"><small>Source</small><strong>'+esc(c.source)+'</strong></div></div><section class="overview-section"><h3>Employment gaps</h3>'+gaps(c)+'</section><section class="overview-section"><h3>Score composition</h3><div class="composition-list">'+composition(c)+'</div></section>';
    if(paneEvidence)paneEvidence.innerHTML='<h3>Experience-backed requirements</h3>'+chips(c.matched)+'<h3>Needs recruiter review</h3>'+chips(c.review,'extra')+'<h3>Missing requirements</h3>'+chips(c.missing,'missing')+'<h3>Additional skills (not scored)</h3>'+chips(c.benefits,'extra');
    if(paneCareer)paneCareer.innerHTML='<section class="overview-section"><h3>Skill-wise experience</h3>'+skillGraph(c)+'</section><section class="overview-section"><h3>Employment timeline</h3>'+timeline(c)+'</section>';
    if(paneResume){
        paneResume.dataset.loaded='';
        paneResume.innerHTML='<div class="detail-grid"><div class="detail-card"><small>Email</small><strong>'+esc(c.email)+'</strong></div><div class="detail-card"><small>Phone</small><strong>'+esc(c.phone)+'</strong></div><div class="detail-card"><small>Location</small><strong>'+esc(c.country)+'</strong></div><div class="detail-card"><small>Source</small><strong>'+esc(c.source)+'</strong></div></div><section class="overview-section"><h3>Extracted resume text</h3><div class="resume-text-state">Open this tab to load the exact extracted text.</div></section>';
    }
    
    if(drawerResume)drawerResume.href=c.resume_url;
    if(drawerDetail)drawerDetail.href=c.detail_url;
    if(drawer)drawer.classList.add('open');
    if(shade)shade.classList.add('open');
}

function closeDrawer(){
    if(drawer)drawer.classList.remove('open');
    if(shade)shade.classList.remove('open');
}

document.querySelector('.candidate-table')?.addEventListener('click', (e) => {
    if (e.target.closest('input, button, a')) return;
    const row = e.target.closest('[data-candidate]');
    if (row && row.dataset.candidate) {
        openCandidate(row.dataset.candidate);
    }
});

document.querySelectorAll('[data-candidate]').forEach(r=>{
    r.onclick=(e)=>{
        if(e.target.closest('input, button, a'))return;
        openCandidate(r.dataset.candidate);
    };
    r.onkeydown=e=>{if(e.key==='Enter')openCandidate(r.dataset.candidate)};
});
if(drawerClose)drawerClose.onclick=closeDrawer;
if(shade)shade.onclick=closeDrawer;
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeDrawer()});


document.querySelectorAll('.drawer-tabs button').forEach(b=>b.onclick=()=>{
    document.querySelectorAll('.drawer-tabs button').forEach(x=>x.classList.toggle('active',x===b));
    document.querySelectorAll('.tab-pane').forEach(x=>x.classList.toggle('active',x.dataset.pane===b.dataset.tab));
    if(b.dataset.tab==='resume')loadResumeText();
});

async function loadResumeText(){
    const id=drawer.dataset.id,c=candidateData[id],pane=paneResume;
    if(!c||pane.dataset.loaded)return;
    pane.dataset.loaded='loading';
    const state=pane.querySelector('.resume-text-state');
    const emailField=pane.querySelector('.detail-card strong');
    if(emailField)emailField.textContent='Loading from resume…';
    state.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Extracting resume text…';
    try{
        const response=await fetch(c.text_url,{headers:{Accept:'application/json'}}),payload=await response.json();
        if(!response.ok||!payload.ok)throw new Error(payload.error||'Text could not be loaded.');
        const resumeText=String(payload.text||'');
        const emailMatch=resumeText.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i);
        const resumeEmail=emailMatch?emailMatch[0]:'Not available';
        if(emailField)emailField.textContent=resumeEmail;
        state.className='resume-text-state';
        state.innerHTML=(payload.warning?'<div class="gap-highlight old-flag"><i class="fa-solid fa-triangle-exclamation"></i><span>'+esc(payload.warning)+'</span></div>':'')+'<pre class="resume-raw">'+esc(payload.text)+'</pre>';
        pane.dataset.loaded='done';
    }catch(error){
        if(emailField)emailField.textContent='Not available';
        state.innerHTML='<i class="fa-solid fa-circle-exclamation"></i> '+esc(error.message);
        pane.dataset.loaded='';
    }
}

const filterRows=[...document.querySelectorAll('[data-candidate]')],
filterSearch=document.getElementById('filterSearch'),
filterSkill=document.getElementById('filterSkill'),
filterExperience=document.getElementById('filterExperience'),
filterSource=document.getElementById('filterSource'),
filterScore=document.getElementById('filterScore'),
filterAge=document.getElementById('filterAge'),
filterStage=document.getElementById('filterStage'),
filterSort=document.getElementById('filterSort'),
filterCount=document.getElementById('filterCount'),
tableBody=document.querySelector('.candidate-table tbody');

filterSearch.value=new URLSearchParams(location.search).get('q')||'';

function filterCandidates(){
    let shown=0;
    filterRows.forEach(row=>{
        const scoreOk=filterScore.value==='0'||(filterScore.value==='analyzed'?row.dataset.analyzed==='1':row.dataset.analyzed==='1'&&+row.dataset.score>=+filterScore.value);
        const ageOk=!filterAge.value||(filterAge.value==='old'?row.dataset.old==='1':row.dataset.old==='0');
        const visible=(!filterSearch.value||row.dataset.find.includes(filterSearch.value.toLowerCase()))&&
                      (!filterSkill.value||row.dataset.skills.includes('|'+filterSkill.value.toLowerCase()+'|'))&&
                      +row.dataset.months>=+filterExperience.value&&
                      (!filterSource.value||row.dataset.source===filterSource.value)&&
                      scoreOk&&
                      ageOk&&
                      (!filterStage.value||row.dataset.stage===filterStage.value);
        row.hidden=!visible;
        if(visible)shown++;
    });

    filterRows.sort((a,b)=>{
        if(filterSort.value.startsWith('created_')){
            return (!a.dataset.created-!b.dataset.created)||(filterSort.value==='created_asc'?a.dataset.created.localeCompare(b.dataset.created):b.dataset.created.localeCompare(a.dataset.created));
        }
        return (+b.dataset.analyzed-+a.dataset.analyzed)||(filterSort.value==='experience_desc'?+b.dataset.months-+a.dataset.months:filterSort.value==='score_asc'?+a.dataset.score-+b.dataset.score:filterSort.value==='name_asc'?a.dataset.name.localeCompare(b.dataset.name):+b.dataset.score-+a.dataset.score);
    }).forEach(row=>tableBody.appendChild(row));

    filterCount.textContent=shown+' candidates';
}

[filterSearch,filterSkill,filterExperience,filterSource,filterScore,filterAge,filterStage,filterSort].forEach(control=>{ if(control) control.addEventListener('input',filterCandidates); });

const skillSelect=document.getElementById('filterSkill'),
skillField=skillSelect?.closest('.filter-field');
if(skillSelect&&skillField){
    skillField.classList.add('skill-edit');
    const editable=document.createElement('input');
    editable.className='control editable-skill';
    editable.setAttribute('list','editableSkillOptions');
    editable.placeholder='Type or select a skill';
    const list=document.createElement('datalist');
    list.id='editableSkillOptions';
    [...skillSelect.options].slice(1).forEach(option=>{
        const item=document.createElement('option');
        item.value=option.textContent.trim();
        list.appendChild(item);
    });
    skillField.append(editable,list);
    editable.addEventListener('input',()=>{
        const value=editable.value.trim().toLowerCase(),
        option=[...skillSelect.options].find(item=>item.value.toLowerCase()===value||item.textContent.trim().toLowerCase()===value);
        if(value&&!option){
            option=new Option(editable.value,value);
            skillSelect.add(option);
        }
        skillSelect.value=value?(option?.value||value):'';
        filterCandidates();
    });
}

filterCandidates();

const boxes=[...document.querySelectorAll('.select-box')],
selectedCountElement=document.getElementById('selectedCount'),
updateSelected=()=>{if(selectedCountElement)selectedCountElement.textContent=boxes.filter(b=>b.checked).length};
boxes.forEach(b=>b.addEventListener('change',updateSelected));
updateSelected();

if(drawerFavourite){
    drawerFavourite.onclick=()=>{
        let b=document.querySelector('input[name="candidate_ids[]"][value="'+CSS.escape(drawer.dataset.id)+'"]');
        if(b&&!b.disabled){
            b.checked=!b.checked;
            updateSelected();
        }
    };
}

const saveBar=document.querySelector('.save-bar'),
inviteLocks=<?=json_encode(array_keys($inviteLocks))?>;
if(saveBar){
    const invite=document.createElement('button');
    invite.type='submit';
    invite.name='action';
    invite.value='invite';
    invite.className='btn';
    invite.innerHTML='<i class="fa-solid fa-calendar-check"></i> Invite for interview';
    saveBar.appendChild(invite);
}
inviteLocks.forEach(id=>{
    const box=document.querySelector('.select-box[value="'+CSS.escape(id)+'"]');
    if(box){
        box.checked=false;
        box.disabled=true;
        box.closest('tr')?.classList.add('invite-locked');
        box.closest('td')?.insertAdjacentHTML('beforeend','<small style="display:block;color:#087a52;font-size:8px;font-weight:700">Invited</small>');
    }
});
</script>
<?php require __DIR__.'/views/partials/footer.php'; ?>