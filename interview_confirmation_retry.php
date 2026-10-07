<?php
require_once __DIR__.'/lib/auth.php';
requireAuth();
require_once __DIR__.'/lib/integrations.php';
require_once __DIR__.'/lib/email_api_mailer.php';
require_once __DIR__.'/lib/smtp_mailer.php';
require_once __DIR__.'/lib/interview_confirmation.php';

if($_SERVER['REQUEST_METHOD']!=='POST'||!verifyCsrf($_POST['csrf']??'')){http_response_code(403);exit('Invalid request.');}
$pdo=db();$user=currentUser();$id=(int)($_POST['invitation_id']??0);
$stmt=$pdo->prepare("SELECT ii.*,ib.timezone,c.name candidate_name,j.title job_title,s.starts_at,s.ends_at FROM interview_invitations ii JOIN interview_batches ib ON ib.id=ii.batch_id JOIN candidates c ON c.id=ii.candidate_id JOIN jobs j ON j.id=ib.job_id JOIN interview_slots s ON s.id=ii.confirmed_slot_id WHERE ii.id=? AND ib.created_by=? AND ii.status='confirmed' LIMIT 1");
$stmt->execute([$id,$user['id']]);$invitation=$stmt->fetch();
if(!$invitation){http_response_code(404);exit('Confirmed interview not found.');}
try{
    sendInterviewConfirmation($invitation,$invitation);
    $pdo->prepare("UPDATE interview_invitations SET notification_status='sent',notification_sent_at=NOW(),notification_error=NULL WHERE id=?")->execute([$id]);
    $outcome='success';
}catch(Throwable $e){
    $pdo->prepare("UPDATE interview_invitations SET notification_status='failed',notification_error='Delivery failed.' WHERE id=?")->execute([$id]);
    $outcome='failed';
}
$pdo->prepare("INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,'interview.confirmation_email_resent','candidate',?,?)")->execute([$user['id'],(string)$invitation['candidate_id'],json_encode(['invitation_id'=>$id,'outcome'=>$outcome,'recipient_email'=>$invitation['recipient_email']])]);
header('Location: interview_calendar.php?notification='.$outcome);
