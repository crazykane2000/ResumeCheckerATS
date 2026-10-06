<?php
require_once __DIR__.'/secrets.php';
require_once __DIR__.'/branding.php';

function interviewCalendarSignature(int $invitationId): string{
    return hash_hmac('sha256','interview-calendar:'.$invitationId,appEncryptionKey());
}
function interviewCalendarDownloadUrl(int $invitationId): string{
    $base=rtrim((string)(getenv('RESUMEIQ_APP_URL')?:'https://resume.nonceblox.com'),'/');
    return $base.'/interview_calendar_file.php?'.http_build_query(['id'=>$invitationId,'signature'=>interviewCalendarSignature($invitationId)]);
}
function interviewCalendarDates(array $invitation,array $slot): array{
    $timezone=new DateTimeZone((string)($invitation['timezone']??'Asia/Kolkata'));
    $start=new DateTimeImmutable((string)$slot['starts_at'],$timezone);
    $end=!empty($slot['ends_at'])?new DateTimeImmutable((string)$slot['ends_at'],$timezone):$start->modify('+30 minutes');
    return [$start,$end];
}
function interviewGoogleCalendarUrl(array $invitation,array $slot): string{
    [$start,$end]=interviewCalendarDates($invitation,$slot);$brand=organizationBrand();$brandName=(string)$brand['name'];$title='Interview - '.($invitation['job_title']??$brandName);
    return 'https://calendar.google.com/calendar/render?'.http_build_query(['action'=>'TEMPLATE','text'=>$title,'dates'=>$start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z').'/'.$end->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),'details'=>'Interview with '.$brandName.' for '.($invitation['job_title']??'the selected role').'.','location'=>'Online','ctz'=>$invitation['timezone']??'Asia/Kolkata'],'','&',PHP_QUERY_RFC3986);
}
function interviewIcsEscape(string $value): string{return str_replace(["\\",";",",","\r\n","\n"],["\\\\","\\;","\\,","\\n","\\n"],$value);}
function interviewCalendarIcs(array $invitation,array $slot): string{
    [$start,$end]=interviewCalendarDates($invitation,$slot);$brand=organizationBrand();$brandName=(string)$brand['name'];$uid='interview-'.$invitation['id'].'@resume.nonceblox.com';$candidate=(string)($invitation['candidate_name']??'Candidate');$email=(string)($invitation['recipient_email']??'');$title='Interview - '.($invitation['job_title']??$brandName);
    $lines=['BEGIN:VCALENDAR','PRODID:-//'.$brandName.'//Interview//EN','VERSION:2.0','CALSCALE:GREGORIAN','METHOD:REQUEST','BEGIN:VEVENT','UID:'.$uid,'DTSTAMP:'.gmdate('Ymd\THis\Z'),'DTSTART:'.$start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),'DTEND:'.$end->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),'SUMMARY:'.interviewIcsEscape($title),'DESCRIPTION:'.interviewIcsEscape('Confirmed '.$brandName.' interview for '.$candidate.'.'),'LOCATION:Online','ORGANIZER;CN='.$brandName.':mailto:hr@nonceblox.com'];
    if(filter_var($email,FILTER_VALIDATE_EMAIL))$lines[]='ATTENDEE;CN='.interviewIcsEscape($candidate).';RSVP=TRUE:mailto:'.$email;
    return implode("\r\n",array_merge($lines,['STATUS:CONFIRMED','SEQUENCE:0','END:VEVENT','END:VCALENDAR','']));
}
