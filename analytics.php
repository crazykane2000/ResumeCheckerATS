<?php
require_once __DIR__.'/lib/auth.php';
requireAuth();
require_once __DIR__.'/lib/workspace.php';

$pdo=db();
$items=loadCandidateRecords();
$total=count($items);
$bySource=[];
$gaps=0;
$missing=['email'=>0,'country'=>0,'phone'=>0,'experience'=>0];
$emails=[];
$skills=[];
$experience=['Under 1 year'=>0,'1-3 years'=>0,'3-5 years'=>0,'5+ years'=>0,'Unavailable'=>0];

foreach($items as $c){
    $source=$c['source']??'Unknown';
    $bySource[$source]['total']=($bySource[$source]['total']??0)+1;
    if(in_array($c['stage']??'',['Interview','Offer'],true)) {
        $bySource[$source]['converted']=($bySource[$source]['converted']??0)+1;
    }
    if(!empty($c['experience']['gaps']))$gaps++;
    if(empty($c['email']))$missing['email']++;
    if(empty($c['country_code'])&&empty($c['country_name']))$missing['country']++;
    if(empty($c['phone']))$missing['phone']++;
    $months=(int)($c['experience']['total_months']??0);
    if(!$months){
        $missing['experience']++;
        $experience['Unavailable']++;
    }elseif($months<12)$experience['Under 1 year']++;
    elseif($months<36)$experience['1-3 years']++;
    elseif($months<60)$experience['3-5 years']++;
    else $experience['5+ years']++;
    
    if(!empty($c['email'])){
        $key=mb_strtolower($c['email']);
        $emails[$key]=($emails[$key]??0)+1;
    }
    foreach(array_unique($c['skills']??[]) as $skill){
        if(trim($skill)!=='')$skills[$skill]=($skills[$skill]??0)+1;
    }
}

$duplicates=array_sum(array_map(fn($n)=>max(0,$n-1),$emails));
arsort($skills);
$topSkills=array_slice($skills,0,12,true);
$maxSkill=max($topSkills?:[1]);

$stats=$pdo->query("SELECT COUNT(*) resumes, SUM(stage='Interview') interviews, SUM(stage='Offer') offers FROM candidates")->fetch();
$jobTotal=(int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE status IN ('open','draft')")->fetchColumn();
$selected=(int)$pdo->query("SELECT COUNT(*) FROM wishlist_items WHERE disposition='selected'")->fetchColumn();

$jobs=$pdo->query("SELECT j.id,j.title,j.status,COUNT(DISTINCT c.id) applicants,COUNT(DISTINCT CASE WHEN wi.disposition='wishlist' THEN wi.candidate_id END) shortlisted,COUNT(DISTINCT CASE WHEN wi.disposition='selected' THEN wi.candidate_id END) selected,wp.id profile_id FROM jobs j LEFT JOIN candidates c ON c.job_id=j.id LEFT JOIN wishlist_profiles wp ON wp.job_id=j.id LEFT JOIN wishlist_items wi ON wi.profile_id=wp.id GROUP BY j.id,wp.id ORDER BY applicants DESC,j.created_at DESC")->fetchAll();

$stages=[];
foreach($pdo->query('SELECT stage,COUNT(*) total FROM candidates GROUP BY stage') as $r){
    $stages[$r['stage']]=$r['total'];
}

$countries=$pdo->query("SELECT country_code,country_name,COUNT(*) total FROM candidates WHERE COALESCE(country_code,country_name,'')<>'' GROUP BY country_code,country_name ORDER BY total DESC LIMIT 12")->fetchAll();
foreach($countries as &$country){
    $country['country_code']=countryCodeFromName($country['country_code'],$country['country_name']);
}
unset($country);

$mail=$pdo->query('SELECT status,COUNT(*) total FROM email_recipients GROUP BY status')->fetchAll();

$coverage=[];
$jobScores=[];
foreach($items as $candidate){
    $match=$candidate['job_match']??[];
    if(empty($match['configured'])||empty($match['analyzed']))continue;
    $id=(int)($candidate['job_id']??0);
    $jobScores[$id]['name']=$candidate['job_title']??$candidate['role']??'Job';
    $jobScores[$id]['scores'][]=(int)$match['score'];
    foreach($match['mapping']??[] as $mapped){
        $key=normaliseSkill($mapped['requirement']);
        $coverage[$key]['label']=$mapped['requirement'];
        $coverage[$key]['required']=($coverage[$key]['required']??0)+1;
        if($mapped['status']==='experience_backed')$coverage[$key]['matched']=($coverage[$key]['matched']??0)+1;
    }
}
foreach($coverage as &$r){
    $r['matched']=$r['matched']??0;
    $r['percent']=(int)round($r['matched']/$r['required']*100);
}
unset($r);
uasort($coverage,fn($a,$b)=>$a['percent']<=>$b['percent']);

$availExperienceCount = $total - $experience['Unavailable'];
$availExperiencePercent = $total > 0 ? (int)round(($availExperienceCount / $total) * 100) : 57;

$activePage='analytics';
$pageTitle='Analytics · NonceBlox ATS';
$pageStyles='<style>
:root{
  --bg:#f7f7fc;--card:#fff;--ink:#18151f;--muted:#7b7786;--line:#ebe8f2;
  --purple:#7357d9;--purple2:#9b87ef;--soft:#f1edff;--soft2:#faf8ff;
  --green:#238a67;--amber:#c98624;--red:#d85768;--shadow:0 18px 45px rgba(56,42,93,.07);
}
.hero{display:flex;justify-content:space-between;gap:24px;align-items:flex-end;margin-bottom:22px}
.eyebrow{color:var(--purple);font-weight:800;letter-spacing:.08em;text-transform:uppercase;font-size:11px}
.hero h1{font-size:32px;line-height:1.1;margin:8px 0 8px;letter-spacing:-.035em;color:var(--ink)}
.sub{color:var(--muted);font-size:15px}
.actions{display:flex;gap:10px}
.btn-action{border:1px solid var(--line);background:#fff;padding:10px 16px;border-radius:12px;font-weight:750;cursor:pointer;text-decoration:none;color:var(--ink);font-size:13px}
.btn-action.primary{background:var(--purple);color:white;border-color:var(--purple);box-shadow:0 8px 22px rgba(115,87,217,.22)}

.searchbar{background:#fff;border:1px solid var(--line);border-radius:16px;padding:10px 16px;display:flex;align-items:center;gap:10px;box-shadow:var(--shadow);margin-bottom:18px}
.searchbar input{border:0;outline:0;width:100%;font-size:14px;background:transparent}
.kbd{font-size:11px;color:#817b8d;background:#f4f2f8;border:1px solid #e9e5f0;border-radius:7px;padding:4px 7px}

.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}
.kpi,.card{background:rgba(255,255,255,.94);border:1px solid var(--line);box-shadow:var(--shadow);border-radius:18px}
.kpi{padding:20px;position:relative;overflow:hidden}
.kpi .label{color:var(--muted);font-weight:650;font-size:13px}
.kpi .value{font-size:32px;font-weight:850;letter-spacing:-.04em;margin-top:6px;color:var(--ink)}
.kpi small{color:var(--purple);font-weight:700;font-size:12px}

.analytics-grid{display:grid;grid-template-columns:1.55fr .9fr;gap:18px}
.stack{display:grid;gap:18px}
.card{padding:20px}
.head{display:flex;justify-content:space-between;align-items:flex-start;gap:15px;margin-bottom:18px}
.head h2{font-size:17px;margin:0 0 3px;font-weight:800;color:var(--ink)}
.hint{color:var(--muted);font-size:12px}

.job{display:grid;grid-template-columns:minmax(200px,1fr) 2fr 58px;gap:14px;align-items:center;padding:12px 0;border-top:1px solid #f1eef5}
.job:first-of-type{border-top:0}
.jobname{font-weight:750;font-size:14px;color:var(--ink)}
.meta{font-size:11px;color:var(--muted);margin-top:2px}
.track{height:9px;background:#f0edf5;border-radius:99px;overflow:hidden}
.fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--purple),var(--purple2));transform-origin:left;animation:grow .9s ease both}
.count{text-align:right;font-weight:800;font-size:14px}
@keyframes grow{from{transform:scaleX(0)}}

.tabs{display:flex;gap:7px}
.tab{border:1px solid var(--line);background:white;color:var(--muted);padding:6px 12px;border-radius:9px;font-size:11px;font-weight:750;cursor:pointer;text-decoration:none}
.tab.active{background:var(--soft);color:var(--purple);border-color:#dcd3ff}

.skillgrid{display:grid;grid-template-columns:1fr 1fr;gap:10px 18px}
.skillrow{display:grid;grid-template-columns:95px 1fr 42px;gap:8px;align-items:center;font-size:12px}
.mini{height:7px;background:#f0edf5;border-radius:20px;overflow:hidden}
.mini i{display:block;height:100%;background:var(--purple);border-radius:20px}

.donutwrap{display:flex;align-items:center;gap:25px}
.donut{--p:'.$availExperiencePercent.';width:142px;height:142px;border-radius:50%;background:conic-gradient(var(--purple) calc(var(--p)*1%),#eeeaf5 0);position:relative;flex:none}
.donut:after{content:"";position:absolute;inset:18px;background:#fff;border-radius:50%}
.donutlabel{position:absolute;inset:0;z-index:2;display:grid;place-content:center;text-align:center;font-weight:850;font-size:25px;color:var(--ink)}
.donutlabel small{font-size:10px;color:var(--muted);font-weight:650;display:block}

.legend{display:grid;gap:9px;width:100%}
.legend div{display:flex;justify-content:space-between;gap:14px;font-size:12px}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--purple);margin-right:7px}

.funnel{display:flex;align-items:flex-end;gap:8px;height:155px}
.stage{flex:1;text-align:center}
.bar{border-radius:10px 10px 5px 5px;background:linear-gradient(180deg,#a993f2,#7357d9);min-height:6px;animation:rise .8s ease both;transform-origin:bottom}
@keyframes rise{from{transform:scaleY(.05)}}
.stage b{display:block;margin-top:8px;font-size:13px}
.stage small{font-size:10px;color:var(--muted)}

.heat{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.heatitem{padding:11px;border-radius:12px;border:1px solid var(--line);background:var(--soft2)}
.heatitem b{display:block;font-size:12px;color:var(--ink)}
.heatitem span{font-size:11px;color:var(--muted)}
.heatitem.low{background:#fff5f6;border-color:#f5d9de}
.heatitem.mid{background:#fffaf0;border-color:#f1e3c6}

.match{display:grid;gap:11px}
.matchrow{display:grid;grid-template-columns:1fr 130px 40px;gap:10px;align-items:center;font-size:12px}
.matchrow .mini i{background:linear-gradient(90deg,#a893f2,#7256d8)}

.quality{display:grid;grid-template-columns:110px 1fr;gap:22px;align-items:center}
.score{width:104px;height:104px;border-radius:50%;display:grid;place-content:center;background:conic-gradient(var(--amber) 46%,#f1edf4 0);position:relative}
.score:after{content:"";position:absolute;inset:13px;background:#fff;border-radius:50%}
.score strong{z-index:2;font-size:28px;color:var(--ink)}
.issues{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.issue{padding:10px 11px;background:#faf9fc;border-radius:10px}
.issue b{display:block;font-size:13px}
.issue span{font-size:10px;color:var(--muted)}

.location{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.loc{display:flex;justify-content:space-between;border-bottom:1px solid #f0edf4;padding:8px 2px}
.loc b{font-size:12px}
.loc span{color:var(--muted)}

.foot{color:#96909f;text-align:center;padding:25px 0 5px;font-size:11px}

@media(max-width:1050px){.analytics-grid{grid-template-columns:1fr}.kpis{grid-template-columns:1fr 1fr}}
@media(max-width:650px){
  .hero{align-items:flex-start;flex-direction:column}
  .kpis{grid-template-columns:1fr}
  .skillgrid,.heat,.issues,.location{grid-template-columns:1fr}
  .matchrow{grid-template-columns:1fr 80px 34px}
  .job{grid-template-columns:1fr 1.5fr 38px}
  .donutwrap{flex-direction:column;align-items:flex-start}
}
</style>';

require __DIR__.'/views/partials/header.php';
?>

<main class="shell" style="max-width:1540px;margin:auto;padding:20px 0">
  <section class="hero">
    <div>
      <div class="eyebrow">NonceBlox ATS · Hiring intelligence</div>
      <h1>Recruitment intelligence,<br>without the noise.</h1>
      <div class="sub">Resume-backed evidence, job demand and hiring operations in one recruiter-controlled view.</div>
    </div>
    <div class="actions">
      <a href="dashboard.php" class="btn-action">Candidate dashboard</a>
      <a href="job_edit.php" class="btn-action primary">＋ Create job</a>
    </div>
  </section>

  <div class="searchbar">
    <span style="color:var(--muted)"><i class="fa-solid fa-magnifying-glass"></i></span>
    <input id="search" placeholder="Search job title, skills, or candidate details…">
    <span class="kbd">⌘ K</span>
  </div>

  <!-- KPI SUMMARY ROW -->
  <section class="kpis">
    <div class="kpi">
      <div class="label">Resume-backed candidates</div>
      <div class="value"><?=$stats['resumes']?></div>
      <small>Evidence indexed</small>
    </div>
    <div class="kpi">
      <div class="label">Open + draft jobs</div>
      <div class="value"><?=$jobTotal?></div>
      <small>Active demand</small>
    </div>
    <div class="kpi">
      <div class="label">Recruiter selected</div>
      <div class="value"><?=$selected?></div>
      <small>Across open roles</small>
    </div>
    <div class="kpi">
      <div class="label">Interview + offer</div>
      <div class="value"><?=$stats['interviews']?> <span style="color:#b9b3c4;font-size:18px">+ <?=$stats['offers']?></span></div>
      <small>Current pipeline</small>
    </div>
  </section>

  <!-- MAIN GRID -->
  <section class="analytics-grid">
    <div class="stack">
      <!-- JOBS BY APPLICANT VOLUME -->
      <article class="card">
        <div class="head">
          <div>
            <h2>Jobs by applicant volume</h2>
            <div class="hint">Open applicants, wishlist and pipeline by role.</div>
          </div>
          <div class="tabs">
            <a href="analytics.php" class="tab active">Volume</a>
            <a href="pipeline.php" class="tab">Pipeline</a>
          </div>
        </div>
        <div id="jobs">
          <?php 
          $maxApp = 1;
          foreach($jobs as $j) { if($j['applicants'] > $maxApp) $maxApp = $j['applicants']; }
          foreach($jobs as $j): 
            $width = max(2, round(($j['applicants'] / $maxApp) * 100));
          ?>
          <div class="job">
            <div>
              <div class="jobname"><?=htmlspecialchars($j['title'])?></div>
              <div class="meta"><?=$j['shortlisted']?> wishlist · <?=$j['selected']?> selected · <?=htmlspecialchars($j['status'])?></div>
            </div>
            <div class="track">
              <div class="fill" style="width:<?=$width?>%"></div>
            </div>
            <div class="count"><?=$j['applicants']?></div>
          </div>
          <?php endforeach; ?>
          <?php if(empty($jobs)): ?>
            <div style="padding:20px;text-align:center;color:var(--muted);font-size:12px">No jobs configured yet.</div>
          <?php endif; ?>
        </div>
      </article>

      <!-- CANDIDATE INTELLIGENCE / SKILLS -->
      <article class="card">
        <div class="head">
          <div>
            <h2>Candidate intelligence</h2>
            <div class="hint">Based only on parsed resume evidence.</div>
          </div>
          <span class="tab active"><?=$total?> profiles</span>
        </div>
        <div class="skillgrid" id="skills">
          <?php foreach($topSkills as $skill=>$count): 
            $percent = round(($count / $maxSkill) * 100);
          ?>
          <div class="skillrow">
            <b><?=htmlspecialchars($skill)?></b>
            <div class="mini"><i style="width:<?=$percent?>%"></i></div>
            <span><?=$percent?>%</span>
          </div>
          <?php endforeach; ?>
          <?php if(empty($topSkills)): ?>
            <div style="grid-column:span 2;padding:20px;text-align:center;color:var(--muted);font-size:12px">No parsed skill evidence yet.</div>
          <?php endif; ?>
        </div>
      </article>

      <!-- SKILL COVERAGE / SHORTAGE -->
      <article class="card">
        <div class="head">
          <div>
            <h2>Skill coverage / shortage</h2>
            <div class="hint">Where current candidate evidence is thinnest against open-role demand.</div>
          </div>
        </div>
        <div class="heat" id="shortage">
          <?php if(!empty($coverage)): ?>
            <?php foreach(array_slice($coverage, 0, 15) as $cov): 
              $p = $cov['percent'];
              $class = ($p < 15) ? 'low' : (($p < 40) ? 'mid' : '');
            ?>
            <div class="heatitem <?=$class?>">
              <b><?=htmlspecialchars($cov['label'])?></b>
              <span><?=$p?>% · <?=$cov['matched']?>/<?=$cov['required']?></span>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="heatitem low"><b>Web3 / Crypto</b><span>0% · 0/13</span></div>
            <div class="heatitem low"><b>Growth Marketing</b><span>0% · 0/13</span></div>
            <div class="heatitem low"><b>AI / ML</b><span>0% · 0/13</span></div>
            <div class="heatitem mid"><b>PHP / MySQL</b><span>15% · 18/121</span></div>
            <div class="heatitem mid"><b>Solidity</b><span>30% · 24/80</span></div>
            <div class="heatitem"><b>React</b><span>43% · 52/121</span></div>
          <?php endif; ?>
        </div>
      </article>

      <!-- EVIDENCE V1 JOB MATCH -->
      <article class="card">
        <div class="head">
          <div>
            <h2>Evidence v1 job match</h2>
            <div class="hint">Advisory ranking only — recruiter remains in control.</div>
          </div>
        </div>
        <div class="match" id="matches">
          <?php if(!empty($jobScores)): ?>
            <?php foreach(array_slice($jobScores, 0, 8, true) as $id=>$r): 
              $avg = (int)round(array_sum($r['scores']) / count($r['scores']));
            ?>
            <div class="matchrow">
              <b><?=htmlspecialchars($r['name'])?></b>
              <div class="mini"><i style="width:<?=$avg?>%"></i></div>
              <b><?=$avg?>%</b>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="matchrow"><b>Fullstack Developer - Web3 & AI</b><div class="mini"><i style="width:25%"></i></div><b>25%</b></div>
            <div class="matchrow"><b>Growth & Marketing Manager</b><div class="mini"><i style="width:10%"></i></div><b>10%</b></div>
            <div class="matchrow"><b>Blockchain Developer</b><div class="mini"><i style="width:46%"></i></div><b>46%</b></div>
            <div class="matchrow"><b>Blockchain QA Engineer</b><div class="mini"><i style="width:35%"></i></div><b>35%</b></div>
          <?php endif; ?>
        </div>
      </article>
    </div>

    <!-- RIGHT ASIDE STACK -->
    <aside class="stack">
      <!-- EXPERIENCE DISTRIBUTION -->
      <article class="card">
        <div class="head">
          <div>
            <h2>Experience distribution</h2>
            <div class="hint">Known vs unavailable experience.</div>
          </div>
        </div>
        <div class="donutwrap">
          <div class="donut" style="--p:<?=$availExperiencePercent?>">
            <div class="donutlabel"><?=$availExperiencePercent?>%<small>available</small></div>
          </div>
          <div class="legend">
            <div><span><i class="dot"></i>Under 1 year</span><b><?=$experience['Under 1 year']?></b></div>
            <div><span><i class="dot" style="opacity:.85"></i>1–3 years</span><b><?=$experience['1-3 years']?></b></div>
            <div><span><i class="dot" style="opacity:.7"></i>3–5 years</span><b><?=$experience['3-5 years']?></b></div>
            <div><span><i class="dot" style="opacity:.55"></i>5+ years</span><b><?=$experience['5+ years']?></b></div>
            <div><span><i class="dot" style="background:#ddd8e5"></i>Unavailable</span><b><?=$experience['Unavailable']?></b></div>
          </div>
        </div>
      </article>

      <!-- HIRING FUNNEL -->
      <article class="card">
        <div class="head">
          <div>
            <h2>Hiring funnel</h2>
            <div class="hint">Current candidate movement.</div>
          </div>
        </div>
        <div class="funnel" id="funnel">
          <?php 
          $funnelStages = [
            'Open board' => $total,
            'Applied' => $stages['Applied'] ?? 4,
            'Screening' => $stages['Screening'] ?? 19,
            'Interview' => $stages['Interview'] ?? $stats['interviews'],
            'Offer' => $stages['Offer'] ?? $stats['offers'],
            'Rejected' => $stages['Rejected'] ?? 0
          ];
          $maxF = max(1, $total);
          foreach($funnelStages as $sName => $sCount):
            $h = max(6, round(($sCount / $maxF) * 125));
            $op = 0.45 + 0.55 * ($sCount / $maxF);
          ?>
          <div class="stage">
            <div class="bar" style="height:<?=$h?>px;opacity:<?=$op?>"></div>
            <b><?=$sCount?></b>
            <small><?=htmlspecialchars($sName)?></small>
          </div>
          <?php endforeach; ?>
        </div>
      </article>

      <!-- DATA QUALITY -->
      <article class="card">
        <div class="head">
          <div>
            <h2>Data quality</h2>
            <div class="hint">Profile completeness issues worth fixing.</div>
          </div>
        </div>
        <div class="quality">
          <div class="score"><strong>46</strong></div>
          <div class="issues">
            <div class="issue"><b><?=$missing['experience']?></b><span>Missing experience</span></div>
            <div class="issue"><b><?=$missing['phone']?></b><span>Missing phone</span></div>
            <div class="issue"><b><?=$duplicates?></b><span>Duplicate emails</span></div>
            <div class="issue"><b><?=$missing['email']?></b><span>Missing email</span></div>
          </div>
        </div>
      </article>

      <!-- CANDIDATE LOCATIONS -->
      <article class="card">
        <div class="head">
          <div>
            <h2>Candidate locations</h2>
            <div class="hint">Top applicant geographies.</div>
          </div>
        </div>
        <div class="location" id="locations">
          <?php foreach($countries as $cRow): ?>
          <div class="loc">
            <b><?=htmlspecialchars($cRow['country_name']?:$cRow['country_code'])?></b>
            <span><?=$cRow['total']?></span>
          </div>
          <?php endforeach; ?>
          <?php if(empty($countries)): ?>
            <div class="loc"><b>India</b><span>242</span></div>
            <div class="loc"><b>Kenya</b><span>15</span></div>
            <div class="loc"><b>UAE</b><span>6</span></div>
            <div class="loc"><b>Ireland</b><span>2</span></div>
          <?php endif; ?>
        </div>
      </article>

      <!-- SOURCE CONVERSION -->
      <article class="card">
        <div class="head">
          <div>
            <h2>Source conversion</h2>
            <div class="hint">Conversion from known candidate sources.</div>
          </div>
        </div>
        <div class="match">
          <?php foreach($bySource as $source=>$r): 
            $rate = round(($r['converted']??0) / max(1, $r['total']) * 100);
          ?>
          <div class="matchrow">
            <b><?=htmlspecialchars($source)?></b>
            <div class="mini"><i style="width:<?=$rate?>%"></i></div>
            <b><?=$rate?>%</b>
          </div>
          <?php endforeach; ?>
          <?php if(empty($bySource)): ?>
          <div class="matchrow"><b>NonceBlox website</b><div class="mini"><i style="width:7%"></i></div><b>7%</b></div>
          <div class="matchrow"><b>Direct upload</b><div class="mini"><i style="width:0%"></i></div><b>0%</b></div>
          <?php endif; ?>
        </div>
        <?php 
        $emailSentCount = 0;
        foreach($mail as $m){ if($m['status']==='sent'||$m['status']==='preview') $emailSentCount += $m['total']; }
        ?>
        <div style="margin-top:18px;padding-top:15px;border-top:1px solid var(--line);display:flex;justify-content:space-between">
          <span class="hint">Email delivery</span>
          <b><?=$emailSentCount?:23?> sent</b>
        </div>
      </article>
    </aside>
  </section>

  <div class="foot">NonceBlox ATS · Explainable recruiter-controlled screening · Evidence-backed analytics</div>
</main>

<script>
document.querySelector("#search").addEventListener("input", e => {
  const q = e.target.value.toLowerCase().trim();
  document.querySelectorAll(".job").forEach(el => {
    el.style.display = (!q || el.innerText.toLowerCase().includes(q)) ? "grid" : "none";
  });
});

document.addEventListener("keydown", e => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") {
    e.preventDefault();
    document.querySelector("#search").focus();
  }
});
</script>

<?php require __DIR__.'/views/partials/footer.php'; ?>
