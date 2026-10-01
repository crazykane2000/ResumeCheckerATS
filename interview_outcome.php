<?php
require_once __DIR__.'/lib/auth.php';
requireAuth();

if($_SERVER['REQUEST_METHOD']!=='POST'||!verifyCsrf($_POST['csrf']??'')){
    http_response_code(403);exit('Invalid request.');
}

$pdo=db();$user=currentUser();$invitationId=(int)($_POST['invitation_id']??0);
$outcome=trim((string)($_POST['outcome']??''));$notes=trim((string)($_POST['notes']??''));
$allowed=['completed','no_show','selected','rejected','offer_sent','offer_accepted','offer_declined','hired'];
if(!$invitationId||!in_array($outcome,$allowed,true)||mb_strlen($notes)>1000){
    http_response_code(422);exit('Invalid interview outcome.');
}

$find=$pdo->prepare("SELECT ii.id,ii.candidate_id,ii.outcome,ib.job_id FROM interview_invitations ii JOIN interview_batches ib ON ib.id=ii.batch_id WHERE ii.id=? AND ib.created_by=? LIMIT 1");
$find->execute([$invitationId,$user['id']]);$invitation=$find->fetch();
if(!$invitation){http_response_code(404);exit('Interview not found.');}

$stageMap=['completed'=>'Interview','no_show'=>'Rejected','selected'=>'Offer','rejected'=>'Rejected','offer_sent'=>'Offer','offer_accepted'=>'Offer','offer_declined'=>'Rejected','hired'=>'Offer'];
try{
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO interview_outcome_events(invitation_id,candidate_id,actor_id,outcome,notes,previous_outcome) VALUES(?,?,?,?,?,?)')->execute([$invitationId,$invitation['candidate_id'],$user['id'],$outcome,$notes?:null,$invitation['outcome']?:null]);
    $pdo->prepare('UPDATE interview_invitations SET outcome=?,outcome_at=NOW(),outcome_by=?,outcome_notes=? WHERE id=?')->execute([$outcome,$user['id'],$notes?:null,$invitationId]);
    $pdo->prepare('UPDATE candidates SET stage=? WHERE id=? AND job_id=?')->execute([$stageMap[$outcome],$invitation['candidate_id'],$invitation['job_id']]);
    $pdo->prepare("INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,'interview.outcome_recorded','interview_invitation',?,?)")->execute([$user['id'],(string)$invitationId,json_encode(['candidate_id'=>$invitation['candidate_id'],'job_id'=>$invitation['job_id'],'outcome'=>$outcome,'previous_outcome'=>$invitation['outcome']?:null])]);
    $pdo->commit();
    header('Location: interview_calendar.php?updated=1');
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code(500);exit('Could not save the interview outcome.');}
