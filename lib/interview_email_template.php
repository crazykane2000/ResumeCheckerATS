<?php
require_once __DIR__.'/branding.php';
function noncebloxInterviewEmailHtml(string $candidate,string $job,string $date,string $time,string $timezone,string $bookingUrl='',string $customMessage=''): string {
    static $template=null;
    if($template===null){$template=@file_get_contents(__DIR__.'/../assets/interview_email_template.html');if($template===false||trim($template)==='')throw new RuntimeException('Interview email template is unavailable.');}
    $brand=organizationBrand();$brandName=trim((string)($brand['name']??''))?:'NonceBlox';$safe=fn(string $value)=>htmlspecialchars($value,ENT_QUOTES|ENT_HTML5,'UTF-8');
    $source=$bookingUrl!==''?str_replace(['mailto:?subject=Interview Confirmation - {{job_title}}','Confirm by Replying'],['{{booking_url}}','Choose interview slot'],$template):$template;
    $source=str_replace(['NONCEBLOX','NonceBlox','ResumeIQ'],[strtoupper($brandName),$safe($brandName),$safe($brandName)],$source);
    if(trim($customMessage)!==''){
        $source=preg_replace('/<div class="nbx-body-text"[^>]*>.*?<\/div>/s','<div class="nbx-body-text" style="max-width:615px;padding-top:14px;font-size:16px;line-height:27px;color:#605c68;">'.nl2br($safe($customMessage)).'</div>',$source);
    }
    $rendered=str_replace(['{{candidate_name}}','{{job_title}}','{{interview_date}}','{{interview_time}}','{{timezone}}','{{booking_url}}'],[$safe($candidate),$safe($job),$safe($date),$safe($time),$safe($timezone),$safe($bookingUrl)],$source);
    return str_replace(['Confirm by Replying &nbsp;â†’','Confirm by Replying &nbsp;→','â†’','â€¢','Â©','Â·'],['Choose interview slot','Choose interview slot','','&bull;','&copy;','&bull;'],$rendered);
}
