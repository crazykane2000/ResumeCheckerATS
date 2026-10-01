<?php
require_once __DIR__.'/lib/auth.php';
requireAuth();
if($_SERVER['REQUEST_METHOD']!=='POST'||!verifyCsrf($_POST['csrf']??'')){http_response_code(403);header('Content-Type: application/json');echo json_encode(['ok'=>false,'error'=>'Invalid request.']);exit;}
$pdo=db();$user=currentUser();$jobId=(int)($_POST['job_id']??0);$profileId=(int)($_POST['profile_id']??0);$candidateId=trim((string)($_POST['candidate_id']??''));$selected=($_POST['selected']??'0')==='1';
$stmt=$pdo->prepare('SELECT wp.id FROM wishlist_profiles wp JOIN candidates c ON c.job_id=wp.job_id WHERE wp.id=? AND wp.user_id=? AND wp.job_id=? AND c.id=? LIMIT 1');$stmt->execute([$profileId,$user['id'],$jobId,$candidateId]);$profileId=(int)$stmt->fetchColumn();
if(!$profileId){http_response_code(422);header('Content-Type: application/json');echo json_encode(['ok'=>false,'error'=>'Candidate does not belong to this job profile.']);exit;}
$lock=$pdo->prepare('SELECT 1 FROM interview_invite_locks WHERE profile_id=? AND candidate_id=? AND active=1');$lock->execute([$profileId,$candidateId]);if($lock->fetchColumn()){http_response_code(409);header('Content-Type: application/json');echo json_encode(['ok'=>false,'error'=>'Candidate was already invited.']);exit;}
if($selected){$pdo->prepare("INSERT INTO wishlist_items(profile_id,candidate_id,disposition) VALUES(?,?,'wishlist') ON DUPLICATE KEY UPDATE disposition=IF(disposition='blacklisted',disposition,'wishlist')")->execute([$profileId,$candidateId]);}else{$pdo->prepare("DELETE FROM wishlist_items WHERE profile_id=? AND candidate_id=? AND disposition='wishlist'")->execute([$profileId,$candidateId]);}
$pdo->prepare("INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,'interview.recipient_selection','candidate',?,?)")->execute([$user['id'],$candidateId,json_encode(['job_id'=>$jobId,'selected'=>$selected,'outcome'=>'success'])]);
header('Content-Type: application/json');echo json_encode(['ok'=>true,'selected'=>$selected]);
