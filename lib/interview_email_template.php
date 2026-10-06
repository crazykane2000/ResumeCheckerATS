<?php
require_once __DIR__.'/branding.php';
function noncebloxInterviewEmailHtml(string $candidate,string $job,string $date,string $time,string $timezone,string $bookingUrl=''): string {
    static $template=null;
    if($template===null){$template=@file_get_contents(__DIR__.'/../assets/interview_email_template.html');if($template===false||trim($template)==='')throw new RuntimeException('Interview email template is unavailable.');}
    $brand=organizationBrand();$brandName=trim((string)($brand['name']??''))?:'NonceBlox ATS';$safe=fn(string $value)=>htmlspecialchars($value,ENT_QUOTES|ENT_HTML5,'UTF-8');
    $source=$bookingUrl!==''?str_replace('mailto:?subject=Interview Confirmation - {{job_title}}','{{booking_url}}',$template):$template;
    $source=str_replace(['NONCEBLOX','NonceBlox','ResumeIQ'],[strtoupper($brandName),$safe($brandName),$safe($brandName)],$source);
    $rendered=str_replace(['{{candidate_name}}','{{job_title}}','{{interview_date}}','{{interview_time}}','{{timezone}}','{{booking_url}}'],[$safe($candidate),$safe($job),$safe($date),$safe($time),$safe($timezone),$safe($bookingUrl)],$source);
    return str_replace(['Confirm by Replying &nbsp;â†’','Confirm by Replying &nbsp;→'],'Choose interview slot',$rendered);
}
