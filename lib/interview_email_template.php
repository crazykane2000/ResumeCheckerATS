<?php
function noncebloxInterviewEmailHtml(string $candidate,string $job,string $date,string $time,string $timezone): string {
    static $template=null;
    if($template===null){$template=@file_get_contents(__DIR__.'/../assets/interview_email_template.html');if($template===false||trim($template)==='')throw new RuntimeException('Interview email template is unavailable.');}
    $safe=fn(string $value)=>htmlspecialchars($value,ENT_QUOTES|ENT_HTML5,'UTF-8');
    return str_replace(['{{candidate_name}}','{{job_title}}','{{interview_date}}','{{interview_time}}','{{timezone}}'],[$safe($candidate),$safe($job),$safe($date),$safe($time),$safe($timezone)],$template);
}
