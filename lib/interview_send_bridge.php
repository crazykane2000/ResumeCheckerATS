<?php
function persistInterviewScheduleForSend(PDO $pdo,array $window,array $slots,array $recipients,int $jobId,array $profile,int $batchId,array $user,array $job,string $windowLabel,string $bookingBase,string $subject): array
{
    return persistInterviewSchedule($pdo,['window'=>$window,'slots'=>$slots,'recipients'=>$recipients,'job_id'=>$jobId,'profile_id'=>$profile['id'],'email_batch_id'=>$batchId,'user_id'=>$user['id'],'name'=>trim($job['title']).' - '.$windowLabel,'booking_base'=>$bookingBase,'job_title'=>$job['title'],'window_label'=>$windowLabel,'subject'=>$subject]);
}
