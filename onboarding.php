<?php
require_once __DIR__.'/lib/auth.php';
requireAuth();
require_once __DIR__.'/lib/onboarding.php';
$pdo=db();
if ((int)(currentUser()['id']??0)!==(int)$pdo->query('SELECT MIN(id) FROM users')->fetchColumn()) { http_response_code(403); exit('Only the workspace owner can access onboarding.'); }
$ready=onboardingTablesReady($pdo);$records=[];$counts=array_fill_keys(['pending','submitted','verified','rejected'],0);
$filter=(string)($_GET['status']??'');if(!array_key_exists($filter,$counts))$filter='';
if($ready){
 $offers=$pdo->query("SELECT o.id,o.candidate_id,o.designation,o.joining_date,c.name FROM offer_letters o JOIN candidates c ON c.id=o.candidate_id WHERE o.letter_type='offer_letter' AND o.status='accepted' ORDER BY o.joining_date IS NULL,o.joining_date,o.id DESC")->fetchAll();
 $itemRows=$pdo->query('SELECT offer_id,item_key,status FROM onboarding_items')->fetchAll();$byOffer=[];
 foreach($itemRows as $r)$byOffer[$r['offer_id']][$r['item_key']]=$r['status'];
 foreach($offers as $offer){$stats=array_fill_keys(array_keys($counts),0);foreach(onboardingChecklist() as $key=>$def){$st=$byOffer[$offer['id']][$key]??'pending';if(!isset($stats[$st]))$st='pending';$stats[$st]++;}foreach($stats as $st=>$n)$counts[$st]+=$n;$records[]=$offer+['stats'=>$stats];}
}
$activePage='onboarding';$pageTitle='Onboarding';
$pageStyles='<style>.ob-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:12px 0}.ob-summary a{padding:14px;color:var(--text);text-decoration:none}.ob-summary strong{display:block;font-size:24px}.ob-table{overflow:auto;padding:14px}.ob-table table{width:100%;border-collapse:collapse;min-width:720px}.ob-table th,.ob-table td{text-align:left;padding:12px 8px;border-bottom:1px solid var(--line)}.ob-table th{color:var(--muted)}</style>';
require __DIR__.'/views/partials/header.php';
?>
<section class="panel" style="padding:18px"><h1>Onboarding</h1><p class="muted">Accepted offers, document progress and joining dates. Review each offer separately; hiring decisions remain recruiter controlled.</p><a href="onboarding.php">All accepted offers</a> | <a href="employees.php">Offers &amp; employees</a></section>
<div class="ob-summary"><?php foreach($counts as $st=>$n):?><a class="panel" href="?status=<?=htmlspecialchars($st)?>"><?=htmlspecialchars(ONBOARDING_STATUS[$st])?> items<strong><?=$n?></strong></a><?php endforeach?></div>
<section class="panel ob-table">
<?php if(!$ready):?><p>Onboarding is not installed. Apply the onboarding migration after taking a database backup.</p><?php else:?>
<p class="muted"><?= $filter ? 'Showing offers with '.htmlspecialchars(strtolower(ONBOARDING_STATUS[$filter])).' items.' : 'Counts include all six checklist items, including optional documents.' ?></p>
<table><thead><tr><th>Candidate / offer</th><th>Role</th><th>Joining date</th><th>Pending</th><th>Submitted</th><th>Verified</th><th>Re-upload</th><th>Review</th></tr></thead><tbody>
<?php $shown=0;foreach($records as $r):if($filter && !$r['stats'][$filter])continue;$shown++;?>
<tr><td><?=htmlspecialchars($r['name'])?><br><small>#<?=(int)$r['id']?></small></td><td><?=htmlspecialchars($r['designation'])?></td><td><?= $r['joining_date'] ? htmlspecialchars(date('d M Y',strtotime($r['joining_date']))) : 'Not set' ?></td><?php foreach($r['stats'] as $n):?><td><?=$n?></td><?php endforeach?><td><a href="offer_letter.php?candidate_id=<?=urlencode($r['candidate_id'])?>">Review checklist</a></td></tr>
<?php endforeach;if(!$shown):?><tr><td colspan="8">No matching accepted offers.</td></tr><?php endif?></tbody></table><?php endif?></section>
<?php require __DIR__.'/views/partials/footer.php'; ?>
