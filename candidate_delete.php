<?php
require_once __DIR__.'/lib/auth.php';
requireAuth();
if($_SERVER['REQUEST_METHOD']!=='POST'||!verifyCsrf($_POST['csrf']??'')){http_response_code(403);exit('Invalid request.');}
$pdo=db();$user=currentUser();$ownerId=(int)$pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
if((int)($user['id']??0)!==$ownerId){http_response_code(403);exit('Only the workspace owner can delete candidates.');}
$id=trim((string)($_POST['candidate_id']??''));$profile=max(0,(int)($_POST['profile_id']??0));$returnTo=($_POST['return_to']??'')==='candidates'?'candidates.php':'wishlist.php?profile='.$profile;
$statement=$pdo->prepare('SELECT id,job_id,source_name,stored_file FROM candidates WHERE id=?');$statement->execute([$id]);$candidate=$statement->fetch();
if(!$candidate){header('Location: '.$returnTo.(str_contains($returnTo,'?')?'&':'?').'deleted=missing');exit;}
$pdo->beginTransaction();
try{
    $pdo->prepare('DELETE FROM email_recipients WHERE candidate_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM wishlist_items WHERE candidate_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM candidates WHERE id=?')->execute([$id]);
    $pdo->prepare('UPDATE email_batches eb SET recipient_count=(SELECT COUNT(*) FROM email_recipients er WHERE er.batch_id=eb.id)')->execute();
    $pdo->prepare('INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,?,?,?,?)')->execute([$user['id'],'candidate_deleted','candidate',$id,json_encode(['job_id'=>$candidate['job_id'],'source'=>$candidate['source_name'],'related_records_removed'=>true])]);
    $pdo->commit();
} catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code(500);exit('Candidate deletion failed.');}
$stored=trim((string)$candidate['stored_file']);
if($stored!==''&&!preg_match('#^https?://#i',$stored)){$uploads=realpath(__DIR__.'/uploads');$file=realpath(__DIR__.'/uploads/'.str_replace(['\\','/'],DIRECTORY_SEPARATOR,$stored));if($uploads&&$file&&is_file($file)&&str_starts_with(strtolower($file),strtolower($uploads.DIRECTORY_SEPARATOR)))unlink($file);}
header('Location: '.$returnTo.(str_contains($returnTo,'?')?'&':'?').'deleted=1');