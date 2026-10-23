<?php
require_once __DIR__.'/lib/auth.php';
requireAuth();
require_once __DIR__.'/lib/workspace.php';
if($_SERVER['REQUEST_METHOD']!=='POST'||!verifyCsrf($_POST['csrf']??'')){http_response_code(403);exit('Invalid request.');}
$pdo=db();$user=currentUser();$ownerId=(int)$pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
if((int)($user['id']??0)!==$ownerId){http_response_code(403);exit('Only the workspace owner can delete candidates.');}
$ids=$_POST['candidate_ids']??[];if(!is_array($ids))$ids=[];$single=trim((string)($_POST['candidate_id']??''));if($single!=='')$ids[]=$single;
$ids=array_values(array_unique(array_filter(array_map(fn($value)=>preg_replace('/[^A-Za-z0-9_-]/','',(string)$value),$ids))));
if(!$ids){http_response_code(422);exit('Select at least one candidate to delete.');}
$profile=max(0,(int)($_POST['profile_id']??0));$returnChoice=$_POST['return_to']??'';$returnTo=$returnChoice==='scan'?'index.php':($returnChoice==='candidates'?'candidates.php':'wishlist.php?profile='.$profile);
$placeholders=implode(',',array_fill(0,count($ids),'?'));$statement=$pdo->prepare("SELECT id,job_id,source_name,stored_file FROM candidates WHERE id IN ($placeholders)");$statement->execute($ids);$candidates=$statement->fetchAll();
if(!$candidates){header('Location: '.$returnTo.(str_contains($returnTo,'?')?'&':'?').'deleted=missing');exit;}
$pdo->beginTransaction();
try{
    foreach($candidates as $candidate){
        $id=$candidate['id'];
        $inviteStmt=$pdo->prepare('SELECT id FROM interview_invitations WHERE candidate_id=?');$inviteStmt->execute([$id]);$inviteIds=array_map('intval',$inviteStmt->fetchAll(PDO::FETCH_COLUMN));
        if($inviteIds){
            $inviteMarks=implode(',',array_fill(0,count($inviteIds),'?'));
            $pdo->prepare("DELETE FROM interview_outcome_events WHERE invitation_id IN ($inviteMarks)")->execute($inviteIds);
            $pdo->prepare("UPDATE interview_invitations SET confirmed_slot_id=NULL WHERE id IN ($inviteMarks)")->execute($inviteIds);
            $pdo->prepare("UPDATE interview_slots SET status='available',invitation_id=NULL,reserved_at=NULL WHERE invitation_id IN ($inviteMarks)")->execute($inviteIds);
            $pdo->prepare("DELETE FROM interview_invitations WHERE id IN ($inviteMarks)")->execute($inviteIds);
        }
        $pdo->prepare('DELETE FROM interview_invite_locks WHERE candidate_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM candidate_notes WHERE candidate_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM email_recipients WHERE candidate_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM wishlist_items WHERE candidate_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM candidates WHERE id=?')->execute([$id]);
        $pdo->prepare('INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,?,?,?,?)')->execute([$user['id'],'candidate_deleted','candidate',$id,json_encode(['job_id'=>$candidate['job_id'],'source'=>$candidate['source_name'],'related_records_removed'=>true,'channel'=>'upload_batch'])]);
    }
    $pdo->prepare('UPDATE email_batches eb SET recipient_count=(SELECT COUNT(*) FROM email_recipients er WHERE er.batch_id=eb.id)')->execute();
    $pdo->commit();
} catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code(500);exit('Candidate deletion failed.');}
$uploads=realpath(__DIR__.'/uploads');
foreach($candidates as $deletedCandidate){
    $storedPath=trim((string)$deletedCandidate['stored_file']);
    if($storedPath!==''&&!preg_match('#^https?://#i',$storedPath)){
        $file=realpath(__DIR__.'/uploads/'.str_replace('/',DIRECTORY_SEPARATOR,$storedPath));
        if($uploads&&$file&&is_file($file)&&str_starts_with(strtolower($file),strtolower($uploads.DIRECTORY_SEPARATOR)))unlink($file);
    }
}
$items=workspaceData('candidates.json',[]);$deletedIds=array_column($candidates,'id');
$items=array_values(array_filter($items,fn($item)=>!in_array($item['id']??'',$deletedIds,true)));saveWorkspaceData('candidates.json',$items);
header('Location: '.$returnTo.(str_contains($returnTo,'?')?'&':'?').'deleted='.count($candidates));
