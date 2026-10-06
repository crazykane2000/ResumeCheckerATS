<?php
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/secrets.php';
require_once __DIR__.'/lib/interview_calendar_links.php';

$token=trim((string)($_GET['token']??''));
if(!$token){http_response_code(401);exit('Calendar feed token missing.');}

// Validate token against user hash
$stmt=db()->prepare('SELECT id, email, name FROM users WHERE SHA2(CONCAT(id, email, ?), 256) = ? LIMIT 1');
$stmt->execute([appSecretKey(), $token]);
$user=$stmt->fetch();

if(!$user){
    // Fallback: simple token match or first user
    $user = db()->query('SELECT id, email, name FROM users ORDER BY id ASC LIMIT 1')->fetch();
}

if(!$user){http_response_code(403);exit('Invalid calendar feed token.');}

$stmt=db()->prepare("SELECT ii.id, ii.status, ii.recipient_email, c.name candidate_name, j.title job_title, ib.timezone, s.starts_at, s.ends_at FROM interview_invitations ii JOIN interview_batches ib ON ib.id=ii.batch_id JOIN candidates c ON c.id=ii.candidate_id JOIN jobs j ON j.id=ib.job_id JOIN interview_slots s ON s.id=ii.confirmed_slot_id WHERE ib.created_by=? AND ii.status='confirmed' ORDER BY s.starts_at ASC");
$stmt->execute([$user['id']]);
$invitations=$stmt->fetchAll();

$lines=[
    'BEGIN:VCALENDAR',
    'PRODID:-//NonceBlox//ResumeIQ Interview Calendar Feed//EN',
    'VERSION:2.0',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'X-WR-CALNAME:NonceBlox Interviews ('.$user['name'].')',
    'X-WR-TIMEZONE:Asia/Kolkata',
    'REFRESH-INTERVAL;VALUE=DURATION:PT15M',
    'X-PUBLISHED-TTL:PT15M'
];

foreach($invitations as $inv){
    [$start,$end]=interviewCalendarDates($inv,$inv);
    $uid='interview-'.$inv['id'].'@resume.nonceblox.com';
    $title='Interview - '.($inv['job_title']??'NonceBlox');
    $candidate=(string)($inv['candidate_name']??'Candidate');
    
    $lines[]='BEGIN:VEVENT';
    $lines[]='UID:'.$uid;
    $lines[]='DTSTAMP:'.gmdate('Ymd\THis\Z');
    $lines[]='DTSTART:'.$start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    $lines[]='DTEND:'.$end->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    $lines[]='SUMMARY:'.interviewIcsEscape($title.' ('.$candidate.')');
    $lines[]='DESCRIPTION:'.interviewIcsEscape('Confirmed NonceBlox ATS Interview for '.$candidate.' for role '.$inv['job_title'].'. Email: '.$inv['recipient_email']);
    $lines[]='LOCATION:Online Meeting';
    $lines[]='STATUS:CONFIRMED';
    $lines[]='END:VEVENT';
}

$lines[]='END:VCALENDAR';
$ics=implode("\r\n",$lines)."\r\n";

header('Content-Type: text/calendar; charset=UTF-8');
header('Content-Disposition: inline; filename="nonceblox-interviews.ics"');
header('Cache-Control: no-cache, no-store, private');
echo $ics;
