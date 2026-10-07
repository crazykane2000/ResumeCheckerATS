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

// Handle Scorecard Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_scorecard' && verifyCsrf($_POST['csrf'] ?? '')) {
    $tech = max(1, min(5, (int)($_POST['technical_rating'] ?? 3)));
    $comm = max(1, min(5, (int)($_POST['communication_rating'] ?? 3)));
    $cult = max(1, min(5, (int)($_POST['cultural_rating'] ?? 3)));
    $prob = max(1, min(5, (int)($_POST['problem_solving_rating'] ?? 3)));
    $overall = round(($tech + $comm + $cult + $prob) / 4, 1);

    $strengths = array_values(array_filter(array_map('trim', (array)($_POST['strengths'] ?? []))));
    $weaknesses = array_values(array_filter(array_map('trim', (array)($_POST['weaknesses'] ?? []))));
    $recommendation = trim($_POST['recommendation'] ?? 'Hire');
    $notes = trim($_POST['notes'] ?? '');

    $insertStmt = $pdo->prepare("INSERT INTO interview_scorecards(candidate_id, job_id, interviewer_id, technical_rating, communication_rating, cultural_rating, problem_solving_rating, overall_score, strengths_json, weaknesses_json, recommendation, notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
    $insertStmt->execute([
        $candidate['id'],
        $candidate['job_id'] ?? null,
        currentUser()['id'],
        $tech,
        $comm,
        $cult,
        $prob,
        $overall,
        json_encode($strengths),
        json_encode($weaknesses),
        $recommendation,
        $notes
    ]);

    $pdo->prepare("INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,'interview.scorecard_submitted','candidate',?,?)")->execute([
        currentUser()['id'],
        $candidate['id'],
        json_encode(['overall_score' => $overall, 'recommendation' => $recommendation, 'outcome' => 'success'])
    ]);

    header('Location: candidate_detail.php?id=' . urlencode($candidate['id']) . '&scorecard_saved=1#scorecard');
    exit;
}

// Fetch candidate scorecards
$scorecardsStmt = $pdo->prepare("SELECT s.*, u.name AS interviewer_name FROM interview_scorecards s LEFT JOIN users u ON u.id = s.interviewer_id WHERE s.candidate_id = ? ORDER BY s.id DESC");
$scorecardsStmt->execute([$candidate['id']]);
$scorecards = $scorecardsStmt->fetchAll(PDO::FETCH_ASSOC);

$d=candidatePresentation($candidate);
$experience=$candidate['experience']??[];

$stmt=$pdo->prepare('SELECT id,name,job_id,required_skills_json FROM wishlist_profiles WHERE user_id=? AND job_id=? ORDER BY id LIMIT 1');
$stmt->execute([currentUser()['id'],(int)($candidate['job_id']??0)]);
$profile=$stmt->fetch()?:null;

$required=$profile?(json_decode($profile['required_skills_json']??'[]',true)?:[]):(json_decode($candidate['job']['required_skills_json']??'[]',true)?:[]);
$match=profileSkillMatch($candidate,$required);

$evidenceMatch = $candidate['job_match'] ?? applicationEvidenceMatch($candidate, $candidate['job']??[]);

$skillValues=array_values($experience['skill_months']??[]);
$maxSkill=$skillValues?max($skillValues):1;

$isOldCandidate=candidateIsOldApplication($candidate);
$candidateAgeStr=candidateApplicationAge($candidate);
$activityTimeline=candidateActivityTimeline($pdo, $candidate);

$scorePercent = $evidenceMatch['analyzed'] ? (int)$evidenceMatch['score'] : null;
$scoreBreakdown = $evidenceMatch['breakdown'] ?? [];
$scoreMax = $evidenceMatch['maximum'] ?? [];

$activePage='candidates';
$pageTitle=$d['name'].' · Candidate Profile';
$pageStyles=<<<'CSS'
<style>
:root{
  --cd-bg-card:#ffffff;
  --cd-purple-grad:linear-gradient(135deg,#6f45ff 0%,#8d63ff 100%);
  --cd-mint-soft:#eefbf5;
  --cd-mint-border:#c3f0db;
  --cd-mint-text:#087a55;
  --cd-red-soft:#fff0f2;
  --cd-red-border:#ffccd5;
  --cd-red-text:#b4233c;
}

.cd2-hero{
  position:relative;
  padding:28px;
  background:radial-gradient(circle at 90% 10%,#ede8ff 0,transparent 40%),linear-gradient(125deg,#ffffff 40%,#f6f3ff 75%,#edfaf5);
  border-radius:12px;
  box-shadow:0 12px 34px rgba(35,25,80,.055);
  display:grid;
  grid-template-columns:1fr auto;
  gap:24px;
  align-items:center;
  overflow:hidden;
}
.cd2-hero:after{
  content:"";
  position:absolute;
  right:-30px;
  bottom:-40px;
  width:160px;
  height:160px;
  border:28px solid #ffffff60;
  border-radius:50%;
  pointer-events:none;
}
.cd2-avatar-wrap{
  display:flex;
  gap:18px;
  align-items:center;
}
.cd2-avatar{
  width:64px;
  height:64px;
  border-radius:16px;
  background:var(--cd-purple-grad);
  color:#fff;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:24px;
  font-weight:700;
  box-shadow:0 8px 24px rgba(111,69,255,.28);
  flex-shrink:0;
}
.cd2-meta-title{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
}
.cd2-meta-title h1{
  margin:0;
  font-size:26px;
  font-weight:700;
}
.cd2-sub{
  margin:6px 0 0;
  font-size:12px;
  color:var(--muted);
  display:flex;
  gap:14px;
  flex-wrap:wrap;
  align-items:center;
}
.cd2-sub span{
  display:inline-flex;
  align-items:center;
  gap:5px;
}

/* Score Donut Ring */
.cd2-score-box{
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  text-align:center;
}
.cd2-donut-chart{
  position:relative;
  width:110px;
  height:110px;
}
.cd2-donut-chart svg{
  width:100%;
  height:100%;
  transform:rotate(-90deg);
}
.cd2-donut-bg{
  fill:none;
  stroke:#e6e1f7;
  stroke-width:8;
}
.cd2-donut-val{
  fill:none;
  stroke:url(#scoreGrad);
  stroke-width:8;
  stroke-dasharray:283;
  stroke-linecap:round;
  transition:stroke-dashoffset 1s ease;
}
.cd2-donut-text{
  position:absolute;
  inset:0;
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
}
.cd2-donut-text strong{
  font-size:24px;
  font-weight:700;
  color:var(--primary);
  line-height:1;
}
.cd2-donut-text span{
  font-size:9px;
  color:var(--muted);
  text-transform:uppercase;
  margin-top:2px;
}

/* Stale Banner */
.cd2-banner{
  margin-top:14px;
  padding:16px 20px;
  border-radius:10px;
  display:flex;
  gap:14px;
  align-items:flex-start;
  box-shadow:0 6px 20px rgba(0,0,0,.02);
}
.cd2-banner.old{
  background:var(--cd-red-soft);
  border:1px solid var(--cd-red-border);
  color:var(--cd-red-text);
}
.cd2-banner.fresh{
  background:var(--cd-mint-soft);
  border:1px solid var(--cd-mint-border);
  color:var(--cd-mint-text);
}

/* Actions Toolbar */
.cd2-toolbar{
  margin-top:14px;
  padding:14px 20px;
  background:#fff;
  border-radius:10px;
  border:1px solid var(--line);
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  align-items:center;
  justify-content:space-between;
}
.cd2-toolbar-group{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}

/* Grid & Cards */
.cd2-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:14px;
  margin-top:14px;
}
.cd2-card{
  padding:20px;
  background:#fff;
  border-radius:10px;
  border:1px solid var(--line);
  box-shadow:0 8px 24px rgba(35,25,80,.03);
}
.cd2-card h3{
  margin:0 0 14px;
  font-size:14px;
  font-weight:700;
  display:flex;
  align-items:center;
  justify-content:space-between;
}

/* Composition Progress Bars */
.comp-bar-group{
  display:grid;
  gap:10px;
}
.comp-bar-row{
  display:grid;
  grid-template-columns:130px 1fr 45px;
  gap:10px;
  align-items:center;
  font-size:11px;
}
.comp-bar-track{
  height:9px;
  background:#eeeaf7;
  border-radius:6px;
  overflow:hidden;
}
.comp-bar-fill{
  height:100%;
  border-radius:6px;
  background:linear-gradient(90deg,#6f45ff,#9f7fff);
  transition:width 0.8s ease;
}

/* Skill Chips Matrix */
.chip-group{
  display:flex;
  flex-wrap:wrap;
  gap:7px;
}
.chip-item{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:6px 10px;
  border-radius:6px;
  font-size:10px;
  font-weight:600;
}
.chip-item.matched{
  background:#eafaf4;
  color:#087a55;
  border:1px solid #bfe9d8;
}
.chip-item.missing{
  background:#fff0f2;
  color:#b4233c;
  border:1px solid #ffccd5;
}
.chip-item.benefit{
  background:#eef4ff;
  color:#3565bc;
  border:1px solid #d4e3ff;
}

/* Interactive Career Timeline */
.timeline-track{
  position:relative;
  margin-left:8px;
  padding-left:22px;
  border-left:2px dashed #d9d0ff;
}
.timeline-node{
  position:relative;
  padding-bottom:18px;
}
.timeline-node:before{
  content:"";
  position:absolute;
  left:-28px;
  top:2px;
  width:12px;
  height:12px;
  border-radius:50%;
  background:#6f45ff;
  box-shadow:0 0 0 4px #eee9ff;
}
.timeline-node strong{
  font-size:12px;
  display:block;
}
.timeline-node p{
  margin:3px 0 0;
  font-size:10px;
  color:var(--muted);
  line-height:1.5;
}

/* Activity Audit Feed */
.audit-feed{
  position:relative;
  margin-left:10px;
  padding-left:24px;
  border-left:2px solid #e0dafc;
}
.audit-item{
  position:relative;
  padding-bottom:18px;
}
.audit-icon-badge{
  position:absolute;
  left:-35px;
  top:0;
  width:24px;
  height:24px;
  border-radius:50%;
  background:#fff;
  border:2px solid var(--primary);
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:10px;
  color:var(--primary);
}
.audit-item.state-success .audit-icon-badge{
  border-color:#087a55;
  color:#087a55;
  background:#edfaf5;
}
.audit-item.state-danger .audit-icon-badge{
  border-color:#b4233c;
  color:#b4233c;
  background:#fff0f2;
}

@media(max-width:900px){
  .cd2-hero{
    grid-template-columns:1fr;
  }
  .cd2-grid{
    grid-template-columns:1fr;
  }
}
</style>
CSS;

require __DIR__.'/views/partials/header.php';
?>

<!-- Hero Header Card -->
<section class="cd2-hero">
    <div class="cd2-avatar-wrap">
        <div class="cd2-avatar">
            <?=htmlspecialchars(strtoupper(substr($d['name'],0,2)))?>
        </div>
        <div>
            <div style="margin-bottom:4px">
                <a class="muted" href="candidates.php" style="font-size:10px"><i class="fa-solid fa-arrow-left"></i> Back to Candidate Intelligence</a>
            </div>
            <div class="cd2-meta-title">
                <h1><?=htmlspecialchars($d['name'])?></h1>
                <span class="tag" style="background:#f0ebff;color:var(--primary);font-weight:700"><?=htmlspecialchars($candidate['stage']??'Applied')?></span>
            </div>
            <div class="cd2-sub">
                <span><i class="fa-solid fa-briefcase"></i> <?=htmlspecialchars($d['role'])?></span>
                <span><i class="fa-solid fa-layer-group"></i> Target Job: <?=htmlspecialchars($candidate['job_title']??'General')?></span>
                <span><i class="fa-solid fa-globe"></i> <?=htmlspecialchars($candidate['country_name']??'Location unavailable')?></span>
                <span><i class="fa-solid fa-link"></i> Source: <?=htmlspecialchars($candidate['source']??'Website')?></span>
            </div>
        </div>
    </div>

    <div class="cd2-score-box">
        <div class="cd2-donut-chart">
            <svg viewBox="0 0 100 100">
                <defs>
                    <linearGradient id="scoreGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#6f45ff" />
                        <stop offset="100%" stop-color="#24bd87" />
                    </linearGradient>
                </defs>
                <circle class="cd2-donut-bg" cx="50" cy="50" r="45"></circle>
                <?php
                    $scoreVal = $scorePercent ?? 0;
                    $dashOffset = 283 - (283 * $scoreVal / 100);
                ?>
                <circle class="cd2-donut-val" cx="50" cy="50" r="45" style="stroke-dashoffset: <?=$dashOffset?>;"></circle>
            </svg>
            <div class="cd2-donut-text">
                <strong><?=$scorePercent!==null?$scorePercent.'%':'N/A'?></strong>
                <span>Match Score</span>
            </div>
        </div>
    </div>
</section>

<!-- 6-Month Application Banner -->
<?php if($isOldCandidate):?>
    <div class="cd2-banner old">
        <i class="fa-solid fa-triangle-exclamation" style="font-size:22px;margin-top:2px"></i>
        <div>
            <strong style="font-size:13px">🚩 STALE CANDIDATE WARNING (Registered <?=htmlspecialchars($candidateAgeStr)?> ago)</strong>
            <p style="margin:4px 0 0;font-size:11px;line-height:1.5">
                Candidate registered/applied on <strong><?=htmlspecialchars(date('d M Y, h:i A', strtotime($candidate['created_at'])))?></strong> (older than 6 months / 180 days). 
                Details such as active availability, current employer, technical skills, and compensation expectations may have changed since initial career page registration. Re-verification is strongly recommended.
            </p>
        </div>
    </div>
<?php else:?>
    <div class="cd2-banner fresh">
        <i class="fa-solid fa-circle-check" style="font-size:18px"></i>
        <div>
            <strong style="font-size:12px">VERIFIED ACTIVE APPLICATION (Registered <?=htmlspecialchars($candidateAgeStr)?>)</strong>
            <span style="font-size:11px;display:block;margin-top:2px">Application received via <?=htmlspecialchars($candidate['source']??'Website')?> on <?=htmlspecialchars(date('d M Y, h:i A', strtotime($candidate['created_at'])))?>.</span>
        </div>
    </div>
<?php endif?>

<!-- Action Toolbar -->
<section class="cd2-toolbar">
    <div class="cd2-toolbar-group">
        <a class="btn btn-primary" target="_blank" href="resume_file.php?id=<?=urlencode($candidate['id'])?>"><i class="fa-solid fa-eye"></i> View Resume</a>
        <a class="btn" href="resume_file.php?id=<?=urlencode($candidate['id'])?>&download=1"><i class="fa-solid fa-download"></i> Download PDF</a>
        <?php if(!empty($candidate['source_url'])):?>
            <a class="btn" target="_blank" rel="noopener noreferrer" href="<?=htmlspecialchars($candidate['source_url'])?>"><i class="fa-solid fa-arrow-up-right-from-square"></i> Original Source</a>
        <?php endif?>
    </div>
    <div class="cd2-toolbar-group">
        <?php if($profile):?>
            <form method="post" action="candidate_wishlist.php" style="display:inline">
                <input type="hidden" name="csrf" value="<?=csrfToken()?>">
                <input type="hidden" name="candidate_id" value="<?=htmlspecialchars($candidate['id'])?>">
                <input type="hidden" name="profile_id" value="<?=$profile['id']?>">
                <button class="btn"><i class="fa-solid fa-heart" style="color:var(--primary)"></i> Toggle Wishlist</button>
            </form>
        <?php endif?>
        <?php if($isOwner):?>
            <form method="post" action="candidate_delete.php" style="display:inline" onsubmit="return confirm('Permanently delete candidate profile and activity records?')">
                <input type="hidden" name="csrf" value="<?=csrfToken()?>">
                <input type="hidden" name="candidate_id" value="<?=htmlspecialchars($candidate['id'])?>">
                <input type="hidden" name="return_to" value="candidates">
                <button class="btn" style="color:var(--danger);border-color:#ffd8df"><i class="fa-solid fa-trash"></i> Delete Candidate</button>
            </form>
        <?php endif?>
    </div>
</section>

<!-- Analytics & Data Visualizations Grid -->
<section class="cd2-grid">
    <!-- Score Breakdown Donut / Progress Bar Chart -->
    <article class="cd2-card">
        <h3>
            <span><i class="fa-solid fa-chart-pie" style="color:var(--primary)"></i> Explainable Match Composition</span>
            <small class="muted" style="font-weight:normal">Evidence v1</small>
        </h3>
        <div class="comp-bar-group">
            <?php
            $factors = [
                'required_skills' => ['Required Skills', 40],
                'relevant_experience' => ['Relevant Experience', 25],
                'preferred_skills' => ['Preferred Skills', 10],
                'role_similarity' => ['Role Similarity', 10],
                'evidence_strength' => ['Evidence Strength', 15],
            ];
            foreach($factors as $key=>[$title, $maxPoints]):
                $val = (float)($scoreBreakdown[$key] ?? 0);
                $pct = round($val / $maxPoints * 100);
            ?>
                <div class="comp-bar-row">
                    <span><?=htmlspecialchars($title)?></span>
                    <div class="comp-bar-track">
                        <div class="comp-bar-fill" style="width: <?=$pct?>%;"></div>
                    </div>
                    <b><?=$val?> / <?=$maxPoints?>pt</b>
                </div>
            <?php endforeach?>
        </div>
    </article>

    <!-- Skills Analysis Matrix Card -->
    <article class="cd2-card">
        <h3>
            <span><i class="fa-solid fa-list-check" style="color:var(--primary)"></i> Requirements & Skill Matrix</span>
        </h3>
        <div style="margin-bottom:12px">
            <small class="muted" style="display:block;margin-bottom:5px">Experience-Backed Skills</small>
            <div class="chip-group">
                <?php foreach(($evidenceMatch['mapping']??[]) as $item):
                    if(($item['status']??'')==='experience_backed'):
                ?>
                    <span class="chip-item matched"><i class="fa-solid fa-circle-check"></i> <?=htmlspecialchars($item['requirement'])?></span>
                <?php endif; endforeach?>
                <?php if(empty(array_filter(($evidenceMatch['mapping']??[]),fn($x)=>($x['status']??'')==='experience_backed'))):?>
                    <span class="muted" style="font-size:10px">None verified</span>
                <?php endif?>
            </div>
        </div>

        <div style="margin-bottom:12px">
            <small class="muted" style="display:block;margin-bottom:5px">Missing Requirements</small>
            <div class="chip-group">
                <?php foreach(($evidenceMatch['mapping']??[]) as $item):
                    if(($item['status']??'')==='not_found'):
                ?>
                    <span class="chip-item missing"><i class="fa-solid fa-triangle-exclamation"></i> <?=htmlspecialchars($item['requirement'])?></span>
                <?php endif; endforeach?>
                <?php if(empty(array_filter(($evidenceMatch['mapping']??[]),fn($x)=>($x['status']??'')==='not_found'))):?>
                    <span class="muted" style="font-size:10px">No missing required skills</span>
                <?php endif?>
            </div>
        </div>

        <div>
            <small class="muted" style="display:block;margin-bottom:5px">Additional Preferred Skills</small>
            <div class="chip-group">
                <?php foreach(array_slice($evidenceMatch['preferred_matched']??[],0,10) as $skill):?>
                    <span class="chip-item benefit"><i class="fa-solid fa-star"></i> <?=htmlspecialchars($skill)?></span>
                <?php endforeach?>
                <?php if(empty($evidenceMatch['preferred_matched'])):?>
                    <span class="muted" style="font-size:10px">None detected</span>
                <?php endif?>
            </div>
        </div>
    </article>

    <!-- Skill Tenure Graphs -->
    <article class="cd2-card">
        <h3>
            <span><i class="fa-solid fa-brain" style="color:var(--primary)"></i> Skill Tenure & Experience Graph</span>
        </h3>
        <?php if(!empty($experience['skill_months'])):?>
            <div class="comp-bar-group">
                <?php foreach($experience['skill_months'] as $skill=>$months):
                    $yrs = round($months/12, 1);
                    $pct = round($months/$maxSkill*100);
                ?>
                    <div class="comp-bar-row">
                        <span><?=htmlspecialchars($skill)?></span>
                        <div class="comp-bar-track">
                            <div class="comp-bar-fill" style="width: <?=$pct?>%; background:linear-gradient(90deg,#6f45ff,#24bd87)"></div>
                        </div>
                        <b><?=$yrs?> yrs</b>
                    </div>
                <?php endforeach?>
            </div>
        <?php else:?>
            <p class="muted">No structured skill tenure extracted.</p>
        <?php endif?>
    </article>

    <!-- Employment Gaps & Stability Card -->
    <article class="cd2-card">
        <h3>
            <span><i class="fa-solid fa-shield-halved" style="color:var(--primary)"></i> Employment Gaps & Stability</span>
        </h3>
        <?php if(!empty($experience['gaps'])):?>
            <div style="display:grid;gap:8px">
                <?php foreach($experience['gaps'] as $gap):?>
                    <div class="cd2-banner old" style="margin:0;padding:10px 14px">
                        <i class="fa-solid fa-triangle-exclamation" style="font-size:16px"></i>
                        <div>
                            <strong style="font-size:11px"><?=$gap['months']?> Month Employment Gap</strong>
                            <span style="display:block;font-size:10px"><?=htmlspecialchars($gap['after'].' to '.$gap['before'])?></span>
                        </div>
                    </div>
                <?php endforeach?>
            </div>
        <?php else:?>
            <div class="cd2-banner fresh" style="margin:0;padding:12px 14px">
                <i class="fa-solid fa-circle-check" style="font-size:16px"></i>
                <div>
                    <strong style="font-size:11px">No Employment Gap (2+ Months) Detected</strong>
                    <span style="display:block;font-size:10px">Continuous employment across reliably parsed dated roles.</span>
                </div>
            </div>
        <?php endif?>
    </article>
</section>

<!-- Dual Side-by-Side Timelines Grid -->
<section class="cd2-grid" style="margin-top:14px">
    <!-- Left Column: Dated Career & Employment Timeline -->
    <article class="cd2-card">
        <h3>
            <span><i class="fa-solid fa-timeline" style="color:var(--primary)"></i> Employment & Career Timeline</span>
            <small class="muted" style="font-weight:normal"><?=count($experience['jobs']??[])?> dated roles</small>
        </h3>
        <div class="timeline-track">
            <?php foreach($experience['jobs']??[] as $job):?>
                <div class="timeline-node">
                    <strong><?=htmlspecialchars(($job['start']??'Unknown').' — '.($job['end']??'Unknown'))?></strong>
                    <p>
                        <b><?=htmlspecialchars($job['label']??'Employment Record')?></b><br>
                        <span><?=round(($job['months']??0)/12, 1)?> years tenure</span>
                        <?php if(!empty($job['skills'])):?>
                            <br><span style="color:var(--primary);font-weight:600">Skills: <?=htmlspecialchars(implode(', ', $job['skills']))?></span>
                        <?php endif?>
                    </p>
                </div>
            <?php endforeach?>
            <?php if(empty($experience['jobs'])):?>
                <p class="muted">No reliable dated roles extracted from resume text.</p>
            <?php endif?>
        </div>
    </article>

    <!-- Right Column: Candidate History & Activity Timeline -->
    <article class="cd2-card">
        <h3>
            <span><i class="fa-solid fa-clock-rotate-left" style="color:var(--primary)"></i> Application & Activity History</span>
            <small class="muted" style="font-weight:normal"><?=count($activityTimeline)?> recorded events</small>
        </h3>
        <div class="audit-feed">
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
                <div class="audit-item <?=$stateClass?>">
                    <div class="audit-icon-badge">
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
                <p class="muted">No historical audit activity recorded yet.</p>
            <?php endif?>
        </div>
    </article>
</section>

<!-- Structured Interview Scorecard & Rating Section -->
<section class="cd2-grid" id="scorecard" style="margin-top:14px">
    <!-- Submit Scorecard Form -->
    <article class="cd2-card">
        <h3>
            <span><i class="fa-solid fa-clipboard-check" style="color:var(--primary)"></i> Submit Structured Interview Scorecard</span>
        </h3>
        
        <?php if(isset($_GET['scorecard_saved'])):?>
            <div class="cd2-banner fresh" style="margin-bottom:14px;padding:10px 14px">
                <i class="fa-solid fa-circle-check"></i>
                <span style="font-size:12px;font-weight:600">Interview Scorecard submitted and recorded in candidate history!</span>
            </div>
        <?php endif?>

        <form method="post" action="candidate_detail.php?id=<?=urlencode($candidate['id'])?>#scorecard">
            <input type="hidden" name="csrf" value="<?=csrfToken()?>">
            <input type="hidden" name="action" value="save_scorecard">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
                <div>
                    <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase">Technical Skills Rating (1–5)</label>
                    <select name="technical_rating" style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;margin-top:4px;font-size:13px">
                        <option value="5">⭐⭐⭐⭐⭐ 5/5 - Exceptional</option>
                        <option value="4" selected>⭐⭐⭐⭐ 4/5 - Strong</option>
                        <option value="3">⭐⭐⭐ 3/5 - Average</option>
                        <option value="2">⭐⭐ 2/5 - Below Average</option>
                        <option value="1">⭐ 1/5 - Unsatisfactory</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase">Communication & Soft Skills</label>
                    <select name="communication_rating" style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;margin-top:4px;font-size:13px">
                        <option value="5">⭐⭐⭐⭐⭐ 5/5 - Excellent</option>
                        <option value="4" selected>⭐⭐⭐⭐ 4/5 - Good</option>
                        <option value="3">⭐⭐⭐ 3/5 - Acceptable</option>
                        <option value="2">⭐⭐ 2/5 - Poor</option>
                        <option value="1">⭐ 1/5 - Very Poor</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase">Cultural Fit & Alignment</label>
                    <select name="cultural_rating" style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;margin-top:4px;font-size:13px">
                        <option value="5">⭐⭐⭐⭐⭐ 5/5 - High Alignment</option>
                        <option value="4" selected>⭐⭐⭐⭐ 4/5 - Fits Well</option>
                        <option value="3">⭐⭐⭐ 3/5 - Neutral</option>
                        <option value="2">⭐⭐ 2/5 - Low Alignment</option>
                        <option value="1">⭐ 1/5 - Mismatch</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase">Problem Solving & Aptitude</label>
                    <select name="problem_solving_rating" style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;margin-top:4px;font-size:13px">
                        <option value="5">⭐⭐⭐⭐⭐ 5/5 - Outstanding</option>
                        <option value="4" selected>⭐⭐⭐⭐ 4/5 - Solid Reasoning</option>
                        <option value="3">⭐⭐⭐ 3/5 - Adequate</option>
                        <option value="2">⭐⭐ 2/5 - Struggles</option>
                        <option value="1">⭐ 1/5 - Poor Reasoning</option>
                    </select>
                </div>
            </div>

            <!-- Strengths Checklist -->
            <div style="margin-bottom:12px">
                <label style="font-size:11px;font-weight:700;color:#15803d;text-transform:uppercase;display:block;margin-bottom:6px">
                    <i class="fa-solid fa-circle-check"></i> Key Strengths Checklist
                </label>
                <div style="display:flex;gap:10px;flex-wrap:wrap;font-size:12px;color:#1e293b">
                    <label><input type="checkbox" name="strengths[]" value="Strong Technical Knowledge"> Strong Technical Knowledge</label>
                    <label><input type="checkbox" name="strengths[]" value="Clear Communication"> Clear Communication</label>
                    <label><input type="checkbox" name="strengths[]" value="Fast Learner"> Fast Learner</label>
                    <label><input type="checkbox" name="strengths[]" value="System Design Depth"> System Design Depth</label>
                    <label><input type="checkbox" name="strengths[]" value="Great Culture Fit"> Great Culture Fit</label>
                </div>
            </div>

            <!-- Weaknesses Checklist -->
            <div style="margin-bottom:12px">
                <label style="font-size:11px;font-weight:700;color:#b91c1c;text-transform:uppercase;display:block;margin-bottom:6px">
                    <i class="fa-solid fa-triangle-exclamation"></i> Areas of Improvement / Concerns
                </label>
                <div style="display:flex;gap:10px;flex-wrap:wrap;font-size:12px;color:#1e293b">
                    <label><input type="checkbox" name="weaknesses[]" value="Higher CTC Expectation"> Higher CTC Expectation</label>
                    <label><input type="checkbox" name="weaknesses[]" value="Notice Period Gap"> Notice Period Gap</label>
                    <label><input type="checkbox" name="weaknesses[]" value="Needs Mentorship"> Needs Mentorship</label>
                    <label><input type="checkbox" name="weaknesses[]" value="Shallow Hands-on Depth"> Shallow Hands-on Depth</label>
                    <label><input type="checkbox" name="weaknesses[]" value="Limited Architecture Exp"> Limited Architecture Exp</label>
                </div>
            </div>

            <!-- Recommendation & Notes -->
            <div style="margin-bottom:12px">
                <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase">Overall Hiring Recommendation</label>
                <select name="recommendation" style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;margin-top:4px;font-size:13px;font-weight:600">
                    <option value="Strong Hire">⭐ Strong Hire</option>
                    <option value="Hire" selected>✅ Hire</option>
                    <option value="Hold">⚠️ Hold / Need Second Opinion</option>
                    <option value="Reject">❌ Reject</option>
                </select>
            </div>

            <div style="margin-bottom:14px">
                <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase">Interviewer Evaluation Notes</label>
                <textarea name="notes" rows="3" placeholder="Provide detailed feedback on candidate performance, technical answers, and overall impression..." style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:12px;margin-top:4px"></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center"><i class="fa-solid fa-floppy-disk"></i> Save Scorecard Evaluation</button>
        </form>
    </article>

    <!-- Display Submitted Scorecards -->
    <article class="cd2-card">
        <h3>
            <span><i class="fa-solid fa-star" style="color:#eab308"></i> Submitted Interview Scorecards (<?=count($scorecards)?>)</span>
        </h3>
        <?php if(empty($scorecards)):?>
            <p class="muted" style="font-size:12px;text-align:center;padding:20px">No interview scorecards submitted yet for this candidate.</p>
        <?php else:?>
            <?php foreach($scorecards as $sc): 
                $st = json_decode($sc['strengths_json'] ?? '[]', true) ?: [];
                $wk = json_decode($sc['weaknesses_json'] ?? '[]', true) ?: [];
            ?>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:14px">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                        <div>
                            <strong style="font-size:14px;color:#0f172a"><?=htmlspecialchars($sc['interviewer_name'] ?: 'Interviewer')?></strong>
                            <small class="muted" style="display:block;font-size:10px"><?=date('d M Y, h:i A', strtotime($sc['created_at']))?></small>
                        </div>
                        <div style="text-align:right">
                            <span style="font-size:16px;font-weight:800;color:#7c3aed">⭐ <?=number_format($sc['overall_score'], 1)?> / 5.0</span>
                            <span class="chip-item <?=match($sc['recommendation']){'Strong Hire','Hire'=>'matched','Hold'=>'benefit',default=>'missing'}?>" style="display:block;margin-top:2px">
                                <?=htmlspecialchars($sc['recommendation'])?>
                            </span>
                        </div>
                    </div>

                    <!-- Category Rating Grid -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:11px;margin-bottom:10px;background:#ffffff;padding:10px;border-radius:6px;border:1px solid #e2e8f0">
                        <div>Technical: <strong><?=str_repeat('⭐', $sc['technical_rating'])?></strong> (<?=$sc['technical_rating']?>/5)</div>
                        <div>Communication: <strong><?=str_repeat('⭐', $sc['communication_rating'])?></strong> (<?=$sc['communication_rating']?>/5)</div>
                        <div>Culture Fit: <strong><?=str_repeat('⭐', $sc['cultural_rating'])?></strong> (<?=$sc['cultural_rating']?>/5)</div>
                        <div>Problem Solving: <strong><?=str_repeat('⭐', $sc['problem_solving_rating'])?></strong> (<?=$sc['problem_solving_rating']?>/5)</div>
                    </div>

                    <?php if(!empty($st)):?>
                        <div style="margin-bottom:6px;font-size:11px">
                            <strong style="color:#15803d">Strengths:</strong>
                            <?php foreach($st as $sItem):?>
                                <span class="chip-item matched" style="font-size:10px;padding:2px 6px;margin-left:4px"><?=htmlspecialchars($sItem)?></span>
                            <?php endforeach?>
                        </div>
                    <?php endif?>

                    <?php if(!empty($wk)):?>
                        <div style="margin-bottom:6px;font-size:11px">
                            <strong style="color:#b91c1c">Concerns:</strong>
                            <?php foreach($wk as $wItem):?>
                                <span class="chip-item missing" style="font-size:10px;padding:2px 6px;margin-left:4px"><?=htmlspecialchars($wItem)?></span>
                            <?php endforeach?>
                        </div>
                    <?php endif?>

                    <?php if(!empty($sc['notes'])):?>
                        <div style="font-size:11px;color:#334155;margin-top:8px;padding-top:8px;border-top:1px solid #e2e8f0;line-height:1.4">
                            <strong>Notes:</strong> <?=nl2br(htmlspecialchars($sc['notes']))?>
                        </div>
                    <?php endif?>
                </div>
            <?php endforeach?>
        <?php endif?>
    </article>
</section>

<?php require __DIR__.'/views/partials/footer.php'; ?>
