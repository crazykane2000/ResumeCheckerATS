<?php
require_once __DIR__.'/config/database.php';require_once __DIR__.'/lib/interview_calendar_links.php';
$id=(int)($_GET['id']??0);$signature=trim((string)($_GET['signature']??''));
if(!$id||!preg_match('/^[a-f0-9]{64}$/',$signature)||!hash_equals(interviewCalendarSignature($id),$signature)){http_response_code(404);exit('Calendar invitation not found.');}
$stmt=db()->prepare("SELECT ii.id,ii.status,ii.recipient_email,c.name candidate_name,j.title job_title,ib.timezone,s.starts_at,s.ends_at FROM interview_invitations ii JOIN interview_batches ib ON ib.id=ii.batch_id JOIN candidates c ON c.id=ii.candidate_id JOIN jobs j ON j.id=ib.job_id JOIN interview_slots s ON s.id=ii.confirmed_slot_id WHERE ii.id=? AND ii.status='confirmed' LIMIT 1");$stmt->execute([$id]);$invitation=$stmt->fetch();
if(!$invitation){http_response_code(404);exit('Calendar invitation not found.');}
$ics=interviewCalendarIcs($invitation,$invitation);header('Content-Type: text/calendar; charset=UTF-8; method=REQUEST');header('Content-Disposition: attachment; filename="nonceblox-interview.ics"');header('Content-Length: '.strlen($ics));header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');echo $ics;
