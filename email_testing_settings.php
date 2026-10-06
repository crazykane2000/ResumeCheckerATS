<?php
require_once __DIR__.'/lib/auth.php';
requireAuth();
require_once __DIR__.'/lib/integrations.php';
if($_SERVER['REQUEST_METHOD']!=='POST'||!verifyCsrf($_POST['csrf']??'')){http_response_code(403);exit('Invalid request.');}
$pdo=db();$user=currentUser();$ownerId=(int)$pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
if((int)$user['id']!==$ownerId){http_response_code(403);exit('Only the workspace owner can change email testing settings.');}
$action=$_POST['action']??'';
if($action==='save'){
    $email=strtolower(trim((string)($_POST['test_copy_email']??'')));$enabled=isset($_POST['test_copy_enabled']);
    if($enabled&&!filter_var($email,FILTER_VALIDATE_EMAIL)){http_response_code(422);exit('Enter a valid test-copy email.');}
    saveIntegration('email_testing','configured',['enabled'=>$enabled,'email'=>$email]);
    $pdo->prepare("INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,'settings.email_testing_updated','workspace','1',?)")->execute([$user['id'],json_encode(['enabled'=>$enabled,'email'=>$email,'outcome'=>'success'])]);
    header('Location: settings.php?email_testing=saved');exit;
}
if($action==='reset_invites'){
    $count=(int)$pdo->query('SELECT COUNT(*) FROM interview_invite_locks WHERE active=1')->fetchColumn();
    $pdo->prepare('UPDATE interview_invite_locks SET active=0,reset_at=NOW(),reset_by=? WHERE active=1')->execute([$user['id']]);
    $pdo->prepare("INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,'interview.all_locks_reset','workspace','1',?)")->execute([$user['id'],json_encode(['reset_count'=>$count,'outcome'=>'success'])]);
    header('Location: settings.php?invite_reset='.$count);exit;
}
http_response_code(422);echo 'Invalid action.';
