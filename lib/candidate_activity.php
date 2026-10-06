<?php

function candidateIsOldApplication(array $candidate, ?DateTimeImmutable $now=null): bool {
    $created=trim((string)($candidate['created_at']??''));
    if($created==='')return false;
    try{$date=new DateTimeImmutable($created);$now??=new DateTimeImmutable('now');return $date<$now->modify('-6 months');}catch(Throwable $e){return false;}
}

function candidateApplicationAge(array $candidate, ?DateTimeImmutable $now=null): string {
    $created=trim((string)($candidate['created_at']??''));
    if($created==='')return 'Unknown age';
    try{$date=new DateTimeImmutable($created);$now??=new DateTimeImmutable('now');$diff=$date->diff($now);if($diff->invert)return 'Future date';if($diff->y>0)return $diff->y.'y '.$diff->m.'m ago';if($diff->m>0)return $diff->m.' month'.($diff->m===1?'':'s').' ago';return max(0,$diff->days).' day'.($diff->days===1?'':'s').' ago';}catch(Throwable $e){return 'Unknown age';}
}

function candidateActivityTimeline(PDO $pdo,array $candidate): array {
    $id=(string)($candidate['id']??'');$events=[];
    $add=static function(string $at,string $type,string $title,string $detail='',string $state='neutral')use(&$events):void{if(trim($at)==='')return;$events[]=['at'=>$at,'type'=>$type,'title'=>$title,'detail'=>$detail,'state'=>$state];};
    $add((string)($candidate['created_at']??''),'application','Application received','Source: '.((string)($candidate['source']??$candidate['source_name']??'Unknown')),'info');

    $s=$pdo->prepare('SELECT wi.disposition,wi.created_at,wi.updated_at,wp.name profile_name FROM wishlist_items wi JOIN wishlist_profiles wp ON wp.id=wi.profile_id WHERE wi.candidate_id=? ORDER BY wi.updated_at');$s->execute([$id]);
    foreach($s->fetchAll() as $row){$status=ucfirst((string)$row['disposition']);$add((string)($row['created_at']??''),'wishlist','Added to shortlist',(string)$row['profile_name'],'info');if(($row['updated_at']??'')!==($row['created_at']??''))$add((string)$row['updated_at'],'wishlist','Shortlist status: '.$status,(string)$row['profile_name'],in_array($row['disposition'],['selected'],true)?'success':($row['disposition']==='blacklisted'?'danger':'info'));}

    $s=$pdo->prepare('SELECT er.status,er.sent_at,er.error_text,eb.subject,eb.created_at batch_created,eb.interview_at FROM email_recipients er JOIN email_batches eb ON eb.id=er.batch_id WHERE er.candidate_id=? ORDER BY COALESCE(er.sent_at,eb.created_at)');$s->execute([$id]);
    foreach($s->fetchAll() as $row){$status=(string)$row['status'];$at=(string)($row['sent_at']?:$row['batch_created']);$detail='Subject: '.(string)$row['subject'];if(!empty($row['interview_at']))$detail.=' · Interview: '.$row['interview_at'];if(!empty($row['error_text']))$detail.=' · '.$row['error_text'];$add($at,'email','Email '.str_replace('_',' ',$status),$detail,$status==='sent'?'success':($status==='failed'?'danger':'info'));}

    $s=$pdo->prepare('SELECT ii.status,ii.sent_at,ii.created_at,ii.expires_at,ii.outcome,ii.outcome_at,isl.starts_at FROM interview_invitations ii LEFT JOIN interview_slots isl ON isl.id=ii.confirmed_slot_id WHERE ii.candidate_id=? ORDER BY ii.created_at');$s->execute([$id]);
    foreach($s->fetchAll() as $row){$add((string)($row['sent_at']?:$row['created_at']),'interview','Interview invitation '.str_replace('_',' ',(string)$row['status']),!empty($row['starts_at'])?'Confirmed slot: '.$row['starts_at']:'Expires: '.$row['expires_at'],$row['status']==='confirmed'?'success':'info');if(!empty($row['outcome']))$add((string)($row['outcome_at']?:$row['created_at']),'outcome','Interview outcome: '.str_replace('_',' ',(string)$row['outcome']),'Recruiter-recorded outcome',in_array($row['outcome'],['selected','offer_accepted','hired'],true)?'success':(in_array($row['outcome'],['rejected','no_show','offer_declined'],true)?'danger':'info'));}

    $s=$pdo->prepare("SELECT action,metadata_json,created_at FROM audit_events WHERE entity_type='candidate' AND entity_id=? ORDER BY created_at");$s->execute([$id]);
    $skip=['interview.invitation_sent','interview.invitation_failed','interview.outcome_recorded'];
    foreach($s->fetchAll() as $row){if(in_array($row['action'],$skip,true))continue;$meta=json_decode($row['metadata_json']??'{}',true)?:[];$detail='';if(isset($meta['outcome']))$detail='Outcome: '.str_replace('_',' ',(string)$meta['outcome']);elseif(isset($meta['reason']))$detail='Reason: '.str_replace('_',' ',(string)$meta['reason']);$title=ucwords(str_replace(['.','_'],' ',(string)$row['action']));$state=str_contains((string)$row['action'],'failed')?'danger':(str_contains((string)$row['action'],'analyzed')?'success':'neutral');$add((string)$row['created_at'],'audit',$title,$detail,$state);}

    usort($events,static fn($a,$b)=>strcmp($b['at'],$a['at']));return $events;
}