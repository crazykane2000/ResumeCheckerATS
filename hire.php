<?php
require_once __DIR__.'/lib/auth.php';requireAuth();require_once __DIR__.'/lib/branding.php';$brand=organizationBrand();
$jobRows=db()->query("SELECT j.id,j.title,COUNT(c.id) applicants FROM jobs j LEFT JOIN candidates c ON c.job_id=j.id WHERE j.status IN ('open','draft') GROUP BY j.id,j.title,j.status ORDER BY applicants DESC,FIELD(j.status,'open','draft'),j.id")->fetchAll();
$jobs=[];$seenRoles=[];
foreach($jobRows as $job){$roleKey=preg_replace('/[^\pL\pN]+/u','',mb_strtolower(trim((string)$job['title'])));if($roleKey===''||isset($seenRoles[$roleKey]))continue;$seenRoles[$roleKey]=true;$jobs[]=$job;}
$activePage='hire';
$pageTitle='Hire Talent';
$pageStyles='<style>.hire-search-panel{max-width:760px;margin:40px auto;padding:24px}.hire-search-form{display:grid;grid-template-columns:220px minmax(0,1fr) auto;gap:10px;align-items:end;margin-top:20px}.hire-search-form label{display:block;color:var(--muted);margin-bottom:6px}.hire-search-form .control{width:100%}@media(max-width:760px){.hire-search-form{grid-template-columns:1fr}.hire-search-panel{margin:12px auto;padding:18px}}</style>';
require __DIR__.'/views/partials/header.php';
?>
<section class="panel hire-search-panel">
<h1>Who do you want to hire?</h1>
<p class="muted">Choose a role and search candidates by name or skills.</p>
<form class="hire-search-form" action="dashboard.php" method="get">
<div><label for="hireRole">Role</label><select class="control" id="hireRole" name="job"><option value="">Select role</option><?php foreach($jobs as $job):?><option value="<?=(int)$job['id']?>"><?=htmlspecialchars(trim($job['title']))?></option><?php endforeach?></select></div>
<div><label for="candidateSearch">Candidate or skills</label><input class="control" type="search" id="candidateSearch" name="q" placeholder="Search skills or candidate..." autocomplete="off"></div>
<button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
</form></section>
<?php require __DIR__.'/views/partials/footer.php'; ?>
