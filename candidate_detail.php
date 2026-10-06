<?php
require_once __DIR__.'/lib/auth.php';
requireAuth();
require_once __DIR__.'/lib/workspace.php';
require_once __DIR__.'/lib/candidate_activity.php';
require_once __DIR__.'/lib/application_match.php';

$id=trim($_GET['id']??'');
$pdo=db();
$ownerId=(int)$pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
$isOwner=(int)(currentUser()['id']??0)===$ownerId;

$candidate=null;
foreach(loadCandidateRecords() as $row){
    if(($row['id']??'')===$id){
        $candidate=$row;
        break;
    }
}

if(!$candidate){
    http_response_code(404);
    exit('Candidate not found.');
}

$d=candidatePresentation($candidate);
$experience=$candidate['experience']??[];

$stmt=$pdo->prepare('SELECT id,name,job_id,required_skills_json FROM wishlist_profiles WHERE user_id=? AND job_id=? ORDER BY id LIMIT 1');
$stmt->execute([currentUser()['id'],(int)($candidate['job_id']??0)]);
$profile=$stmt->fetch()?:null;

$required=$profile?(json_decode($profile['required_skills_json']??'[]',true)?:[]):(json_decode($candidate['job']['required_skills_json']??'[]',true)?:[]);
$match=profileSkillMatch($candidate,$required);

$skillValues=array_values($experience['skill_months']??[]);
$maxSkill=$skillValues?max($skillValues):1;

$isOldCandidate=candidateIsOldApplication($candidate);
$candidateAgeStr=candidateApplicationAge($candidate);
$activityTimeline=candidateActivityTimeline($pdo, $candidate);

$activePage='candidates';
$pageTitle=$d['name'].' · Candidate Profile';
$pageStyles=<<<'CSS'
<style>
.candidate-head{padding:22px;display:flex;align-items:center;gap:14px;background:radial-gradient(circle at 85% 15%,#e9e1ff 0,transparent 30%),linear-gradient(120deg,#fff 48%,#f4f1ff 78%,#edfff8)}.candidate-head h1{font-size:24px;margin:5px 0}.candidate-head p,.muted{font-size:11px;color:var(--muted)}.score{margin-left:auto;font-size:26px;color:var(--primary);font-weight:700}.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}.detail-panel{padding:18px}.detail-panel h2{font-size:14px;margin-top:0;margin-bottom:12px}.pills{display:flex;gap:6px;flex-wrap:wrap}.pill{padding:5px 8px;border-radius:6px;background:var(--green-soft);color:var(--green);font-size:10px;font-weight:600}.missing{background:#fff1f3;color:var(--danger)}.benefit{background:#eef4ff;color:#3568b8}.timeline{border-left:2px solid #e5dfff;padding-left:16px}.event{padding-bottom:14px}.event strong{display:block;font-size:11px}.skill-row{display:grid;grid-template-columns:110px 1fr 45px;gap:8px;margin:9px 0;font-size:10px}.bar{height:7px;background:#eee;border-radius:4px;overflow:hidden}.bar i{display:block;height:100%;background:var(--primary)}.activity-timeline-list{position:relative;margin-left:10px;padding-left:24px;border-left:2px solid #d9d0ff}.activity-event{position:relative;padding-bottom:18px}.activity-event .activity-icon{position:absolute;left:-35px;top:0;width:22px;height:22px;border-radius:50%;background:#fff;border:2px solid var(--primary);display:flex;align-items:center;justify-content:center;font-size:10px;color:var(--primary)}.activity-event.state-success .activity-icon{border-color:#087a55;color:#087a55;background:#edfaf5}.activity-event.state-danger .activity-icon{border-color:#b4233c;color:#b4233c;background:#fff0f2}.activity-event.state-info .activity-icon{border-color:#6f45ff;color:#6f45ff;background:#f0ebff}@media(max-width:760px){.detail-grid{grid-template-columns:1fr}}
</style>
CSS;

require __DIR__.'/views/partials/header.php';
?>
<section class="panel candidate-head">
    <div>
        <a class="muted" href="candidates.php"><i class="fa-solid fa-arrow-left"></i> Back to candidates</a>
        <h1><?=htmlspecialchars($d['name'])?></h1>
        <p><?=htmlspecialchars($d['role'])?> · Job: <?=htmlspecialchars($candidate['job_title']??'General')?> · Source: <?=htmlspecialchars($candidate['source']??'Unknown')?> · <?=htmlspecialchars($candidate['country_name']??'Country unavailable')?></p>
    </div>
    <b class="score"><?=$match['analyzed']?$match['score'].'%':($match['configured']?'Needs analysis':'?')?></b>
</section>

<?php if($isOldCandidate):?>
    <div class="panel" style="margin-top:12px;padding:16px;background:#fff0f2;border:1px solid #ffccd5;border-radius:10px;display:flex;gap:14px;align-items:flex-start">
        <i class="fa-solid fa-triangle-exclamation" style="color:#b4233c;font-size:22px;margin-top:2px"></i>
        <div>
            <h3 style="margin:0 0 4px;font-size:14px;color:#b4233c">🚩 OLD APPLICATION WARNING (Applied/Registered <?=htmlspecialchars($candidateAgeStr)?> ago)</h3>
            <p style="margin:0;font-size:11px;color:#801b2a;line-height:1.5">
                This candidate applied/registered on <strong><?=htmlspecialchars(date('d M Y, h:i A', strtotime($candidate['created_at'])))?></strong> (over 6 months ago). 
                Applications older than 6 months (180 days) are flagged as stale because candidate availability, current employment status, skills, or compensation expectations may have changed since initial registration on the website/career page. Re-verification is recommended before shortlisting or scheduling.
            </p>
        </div>
    </div>
<?php else:?>
    <div class="panel" style="margin-top:12px;padding:14px;background:#edfaf5;border:1px solid #bfe9d8;border-radius:10px;display:flex;gap:14px;align-items:center">
        <i class="fa-solid fa-circle-check" style="color:#087a55;font-size:18px"></i>
        <div>
            <strong style="font-size:12px;color:#087a55">ACTIVE RECENT APPLICATION (Registered <?=htmlspecialchars($candidateAgeStr)?>)</strong>
            <span style="font-size:11px;color:#05593e;display:block">Registered on <?=htmlspecialchars(date('d M Y, h:i A', strtotime($candidate['created_at'])))?> via <?=htmlspecialchars($candidate['source']??'Website')?>.</span>
        </div>
    </div>
<?php endif?>

<section class="panel detail-panel" style="margin-top:12px">
    <h2>Job-specific actions</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn btn-primary" target="_blank" href="resume_file.php?id=<?=urlencode($candidate['id'])?>"><i class="fa-solid fa-eye"></i> View resume</a>
        <a class="btn" href="resume_file.php?id=<?=urlencode($candidate['id'])?>&download=1"><i class="fa-solid fa-download"></i> Download</a>
        <?php if(!empty($candidate['source_url'])):?>
            <a class="btn" target="_blank" rel="noopener noreferrer" href="<?=htmlspecialchars($candidate['source_url'])?>"><i class="fa-solid fa-arrow-up-right-from-square"></i> Original source</a>
        <?php endif?>
        <?php if($profile):?>
            <form method="post" action="candidate_wishlist.php" style="display:flex;gap:8px">
                <input type="hidden" name="csrf" value="<?=csrfToken()?>">
                <input type="hidden" name="candidate_id" value="<?=htmlspecialchars($candidate['id'])?>">
                <input type="hidden" name="profile_id" value="<?=$profile['id']?>">
                <button class="btn"><i class="fa-solid fa-heart"></i> Add to wishlist</button>
                <a class="btn" href="job_profile.php?id=<?=$profile['id']?>">Profile & top 5</a>
            </form>
        <?php endif?>
        <?php if($isOwner):?>
            <form method="post" action="candidate_delete.php" onsubmit="return confirm('Permanently delete this candidate and related wishlist/email records? This cannot be undone.')">
                <input type="hidden" name="csrf" value="<?=csrfToken()?>">
                <input type="hidden" name="candidate_id" value="<?=htmlspecialchars($candidate['id'])?>">
                <input type="hidden" name="return_to" value="candidates">
                <button class="btn" style="color:var(--danger);border-color:#ffd8df"><i class="fa-solid fa-trash"></i> Delete candidate</button>
            </form>
        <?php endif?>
    </div>
    <?php if(!$profile):?>
        <div class="empty" style="margin-top:10px">No matching job profile exists for this applicant.</div>
    <?php endif?>
</section>

<section class="panel detail-panel" style="margin-top:12px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
        <h2 style="margin:0"><i class="fa-solid fa-clock-rotate-left"></i> Candidate History & Activity Timeline</h2>
        <span class="muted" style="font-size:11px"><?=count($activityTimeline)?> recorded events</span>
    </div>
    <div class="activity-timeline-list">
        <?php foreach($activityTimeline as $event):
            $stateClass = match($event['state']??'neutral') {
                'success' => 'state-success',
                'danger' => 'state-danger',
                'info' => 'state-info',
                default => 'state-neutral'
            };
            $iconClass = match($event['type']??'') {
                'application' => 'fa-file-circle-check',
                'wishlist' => 'fa-heart',
                'email' => 'fa-envelope',
                'interview' => 'fa-calendar-check',
                'outcome' => 'fa-user-check',
                default => 'fa-list-check'
            };
            $timeFormatted = !empty($event['at']) ? date('d M Y, h:i:s A', strtotime($event['at'])) : 'Unknown time';
        ?>
            <div class="activity-event <?=$stateClass?>">
                <div class="activity-icon">
                    <i class="fa-solid <?=$iconClass?>"></i>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:baseline">
                    <strong style="font-size:12px"><?=htmlspecialchars($event['title'])?></strong>
                    <small class="muted" style="font-size:10px"><?=htmlspecialchars($timeFormatted)?></small>
                </div>
                <?php if(!empty($event['detail'])):?>
                    <p style="margin:4px 0 0;font-size:11px;color:var(--muted);line-height:1.5"><?=htmlspecialchars($event['detail'])?></p>
                <?php endif?>
            </div>
        <?php endforeach?>
        <?php if(empty($activityTimeline)):?>
            <p class="muted">No historical activity recorded yet.</p>
        <?php endif?>
    </div>
</section>

<section class="detail-grid">
    <article class="panel detail-panel">
        <h2>Matched required skills</h2>
        <?php if(!$match['configured']):?>
            <p class="muted">Configure required skills in this job profile first.</p>
        <?php endif?>
        <div class="pills">
            <?php foreach($match['matched'] as $skill):?>
                <span class="pill"><?=htmlspecialchars($skill)?></span>
            <?php endforeach?>
            <?php if(empty($match['matched'])):?>
                <span class="muted" style="font-size:11px">None matched</span>
            <?php endif?>
        </div>
    </article>
    <article class="panel detail-panel">
        <h2>Missing required skills</h2>
        <div class="pills">
            <?php foreach($match['missing'] as $skill):?>
                <span class="pill missing"><?=htmlspecialchars($skill)?></span>
            <?php endforeach?>
            <?php if(empty($match['missing'])):?>
                <span class="muted" style="font-size:11px">No missing required skills</span>
            <?php endif?>
        </div>
    </article>
    <article class="panel detail-panel">
        <h2>Key benefits (not scored)</h2>
        <p class="muted">Additional detected skills are useful context but never increase the match score.</p>
        <div class="pills">
            <?php foreach(array_slice($match['benefits'],0,16) as $skill):?>
                <span class="pill benefit"><?=htmlspecialchars($skill)?></span>
            <?php endforeach?>
            <?php if(empty($match['benefits'])):?>
                <span class="muted" style="font-size:11px">No additional benefits recorded</span>
            <?php endif?>
        </div>
    </article>
    <article class="panel detail-panel">
        <h2>Experience timeline</h2>
        <div class="timeline">
            <?php foreach($experience['jobs']??[] as $job):?>
                <div class="event">
                    <strong><?=htmlspecialchars(($job['start']??'Unknown').' — '.($job['end']??'Unknown'))?></strong>
                    <span class="muted"><?=round(($job['months']??0)/12,1)?> years · <?=htmlspecialchars(implode(', ',$job['skills']??[]))?></span>
                </div>
            <?php endforeach?>
            <?php if(empty($experience['jobs'])):?>
                <p class="muted">No reliable dated roles detected.</p>
            <?php endif?>
        </div>
    </article>
    <article class="panel detail-panel">
        <h2>Skill-wise experience</h2>
        <?php foreach($experience['skill_months']??[] as $skill=>$months):?>
            <div class="skill-row">
                <span><?=htmlspecialchars($skill)?></span>
                <div class="bar"><i style="width:<?=round($months/$maxSkill*100)?>%"></i></div>
                <b><?=round($months/12,1)?>y</b>
            </div>
        <?php endforeach?>
        <?php if(empty($experience['skill_months'])):?>
            <p class="muted">No skill tenure extracted.</p>
        <?php endif?>
    </article>
    <article class="panel detail-panel">
        <h2>Employment gaps</h2>
        <?php foreach($experience['gaps']??[] as $gap):?>
            <div class="event">
                <strong style="color:#b4233c"><?=$gap['months']?> months gap</strong>
                <span class="muted"><?=htmlspecialchars($gap['after'].' to '.$gap['before'])?></span>
            </div>
        <?php endforeach?>
        <?php if(empty($experience['gaps'])):?>
            <p class="muted">No gap of 2+ months detected.</p>
        <?php endif?>
    </article>
</section>

<?php require __DIR__.'/views/partials/footer.php'; ?>

