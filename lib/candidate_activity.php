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

// $raw (optional) receives the rows already fetched here so callers such as candidateJourney()
// can reuse them without extra database round trips.
function candidateActivityTimeline(PDO $pdo,array $candidate,?array &$raw=null): array {
    $id=(string)($candidate['id']??'');$events=[];$raw=['wishlist'=>[],'invitations'=>[],'audit'=>[]];
    $add=static function(string $at,string $type,string $title,string $detail='',string $state='neutral')use(&$events):void{if(trim($at)==='')return;$events[]=['at'=>$at,'type'=>$type,'title'=>$title,'detail'=>$detail,'state'=>$state];};
    $add((string)($candidate['created_at']??''),'application','Application received','Source: '.((string)($candidate['source']??$candidate['source_name']??'Unknown')),'info');

    $s=$pdo->prepare('SELECT wi.disposition,wi.created_at,wi.updated_at,wp.name profile_name FROM wishlist_items wi JOIN wishlist_profiles wp ON wp.id=wi.profile_id WHERE wi.candidate_id=? ORDER BY wi.updated_at');$s->execute([$id]);$raw['wishlist']=$s->fetchAll();
    foreach($raw['wishlist'] as $row){$status=ucfirst((string)$row['disposition']);$add((string)($row['created_at']??''),'wishlist','Added to shortlist',(string)$row['profile_name'],'info');if(($row['updated_at']??'')!==($row['created_at']??''))$add((string)$row['updated_at'],'wishlist','Shortlist status: '.$status,(string)$row['profile_name'],in_array($row['disposition'],['selected'],true)?'success':($row['disposition']==='blacklisted'?'danger':'info'));}

    $s=$pdo->prepare('SELECT er.status,er.sent_at,er.error_text,eb.subject,eb.created_at batch_created,eb.interview_at FROM email_recipients er JOIN email_batches eb ON eb.id=er.batch_id WHERE er.candidate_id=? ORDER BY COALESCE(er.sent_at,eb.created_at)');$s->execute([$id]);
    foreach($s->fetchAll() as $row){$status=(string)$row['status'];$at=(string)($row['sent_at']?:$row['batch_created']);$detail='Subject: '.(string)$row['subject'];if(!empty($row['interview_at']))$detail.=' · Interview: '.$row['interview_at'];if(!empty($row['error_text']))$detail.=' · '.$row['error_text'];$add($at,'email','Email '.str_replace('_',' ',$status),$detail,$status==='sent'?'success':($status==='failed'?'danger':'info'));}

    try {
        $s=$pdo->prepare('SELECT cr.status,cr.sent_at,cr.created_at,cr.error_message,c.name campaign_name,c.subject FROM email_campaign_recipients cr JOIN email_campaigns c ON c.id=cr.campaign_id WHERE cr.candidate_id=? ORDER BY COALESCE(cr.sent_at,cr.created_at)');$s->execute([$id]);
        foreach($s->fetchAll() as $row){$status=(string)$row['status'];$at=(string)($row['sent_at']?:$row['created_at']);$detail='Campaign: '.(string)$row['campaign_name'].' · Subject: '.(string)$row['subject'];if(!empty($row['error_message']))$detail.=' · '.$row['error_message'];$add($at,'email','Outreach Campaign '.str_replace('_',' ',$status),$detail,$status==='sent'?'success':($status==='failed'?'danger':'info'));}
    } catch(Throwable $e) {}

    $s=$pdo->prepare('SELECT ii.id,ii.status,ii.sent_at,ii.created_at,ii.expires_at,ii.outcome,ii.outcome_at,ii.notification_status,ii.notification_sent_at,isl.starts_at FROM interview_invitations ii LEFT JOIN interview_slots isl ON isl.id=ii.confirmed_slot_id WHERE ii.candidate_id=? ORDER BY ii.created_at');$s->execute([$id]);$raw['invitations']=$s->fetchAll();
    foreach($raw['invitations'] as $row){
        $add((string)($row['sent_at']?:$row['created_at']),'interview','Interview invitation '.str_replace('_',' ',(string)$row['status']),!empty($row['starts_at'])?'Confirmed slot: '.$row['starts_at']:'Expires: '.$row['expires_at'],$row['status']==='confirmed'?'success':'info');
        if(!empty($row['notification_status']) && $row['notification_status']==='sent'){
            $add((string)($row['notification_sent_at']?:$row['created_at']),'email','Interview Confirmation Email Sent',!empty($row['starts_at'])?'Slot: '.$row['starts_at']:'Confirmation email delivered','success');
        }
        if(!empty($row['outcome']))$add((string)($row['outcome_at']?:$row['created_at']),'outcome','Interview outcome: '.str_replace('_',' ',(string)$row['outcome']),'Recruiter-recorded outcome',in_array($row['outcome'],['selected','offer_accepted','hired'],true)?'success':(in_array($row['outcome'],['rejected','no_show','offer_declined'],true)?'danger':'info'));
    }

    $s=$pdo->prepare("SELECT action,metadata_json,created_at FROM audit_events WHERE entity_type='candidate' AND entity_id=? ORDER BY created_at");$s->execute([$id]);$raw['audit']=$s->fetchAll();
    $skip=['interview.invitation_sent','interview.invitation_failed','interview.outcome_recorded'];
    foreach($raw['audit'] as $row){if(in_array($row['action'],$skip,true))continue;$meta=json_decode($row['metadata_json']??'{}',true)?:[];$detail='';if(isset($meta['outcome']))$detail='Outcome: '.str_replace('_',' ',(string)$meta['outcome']);elseif(isset($meta['reason']))$detail='Reason: '.str_replace('_',' ',(string)$meta['reason']);$title=ucwords(str_replace(['.','_'],' ',(string)$row['action']));$state=str_contains((string)$row['action'],'failed')?'danger':(str_contains((string)$row['action'],'analyzed')?'success':'neutral');$add((string)$row['created_at'],'audit',$title,$detail,$state);}

    usort($events,static fn($a,$b)=>strcmp($b['at'],$a['at']));return $events;
}

/**
 * Hiring journey stepper for one candidate, derived only from recorded data
 * (candidate stage, shortlist rows, interview invitations/outcomes, audit events).
 * Nothing is shown as "done" without a record. Rows come from
 * candidateActivityTimeline(..., $raw) so no extra queries are needed.
 *
 * Step state: done | current | pending | failed | skipped
 *
 * @return array{steps: array<int, array{key:string,label:string,state:string,at:?string,detail:string}>, next: array{text:string,url:?string,tone:string}}
 */
function candidateJourney(array $candidate, array $raw, int $noteCount = 0): array
{
    $stageLc = strtolower((string)($candidate['stage'] ?? 'Applied'));
    $fmt = static fn(?string $at): ?string => ($at && strtotime($at)) ? date('d M Y', strtotime($at)) : null;
    $fmtTime = static fn(?string $at): string => ($at && strtotime($at)) ? date('d M Y, h:i A', strtotime($at)) : '';

    // Stage history from audit (first time each stage was reached) and stage emails actually sent.
    $stageAt = [];
    $stageMail = [];
    foreach ($raw['audit'] ?? [] as $row) {
        $meta = json_decode($row['metadata_json'] ?? '{}', true) ?: [];
        if ($row['action'] === 'candidate.stage_changed' && !empty($meta['new_stage'])) {
            $stageAt[strtolower((string)$meta['new_stage'])] ??= (string)$row['created_at'];
        }
        if ($row['action'] === 'candidate.stage_email_sent' && ($meta['outcome'] ?? '') === 'sent' && !empty($meta['stage'])) {
            $stageMail[strtolower((string)$meta['stage'])] ??= (string)$row['created_at'];
        }
    }

    $wishlist = $raw['wishlist'] ?? [];
    $blacklisted = (bool)array_filter($wishlist, fn($w) => ($w['disposition'] ?? '') === 'blacklisted');
    $shortlistAt = null;
    foreach ($wishlist as $w) {
        if (in_array($w['disposition'] ?? '', ['wishlist', 'selected'], true) && ($shortlistAt === null || $w['created_at'] < $shortlistAt)) {
            $shortlistAt = (string)$w['created_at'];
        }
    }

    // The latest non-cancelled invitation drives the interview steps.
    $invites = array_values(array_filter($raw['invitations'] ?? [], fn($i) => !in_array($i['status'] ?? '', ['cancelled', 'draft'], true)));
    $invite = $invites ? $invites[count($invites) - 1] : null;
    $outcome = $invite['outcome'] ?? null;
    $inviteAt = $invite ? (string)($invite['sent_at'] ?: $invite['created_at']) : ($stageMail['interview'] ?? $stageMail['round 1'] ?? null);

    $isRejected = $stageLc === 'rejected' || $outcome === 'rejected' || $blacklisted;
    $rejectedAt = $stageAt['rejected'] ?? ($invite['outcome_at'] ?? null);
    $interviewedOutcomes = ['completed', 'selected', 'rejected', 'offer_sent', 'offer_accepted', 'offer_declined', 'hired'];
    $selectedOutcomes = ['selected', 'offer_sent', 'offer_accepted', 'offer_declined', 'hired'];
    $laterStages = ['round 2', 'offer'];

    $steps = [];
    $push = static function (string $key, string $label, string $state, ?string $at, string $detail) use (&$steps): void {
        $steps[] = ['key' => $key, 'label' => $label, 'state' => $state, 'at' => $at, 'detail' => $detail];
    };

    $push('applied', 'Applied', 'done', $fmt($candidate['created_at'] ?? null), 'Source: ' . (string)($candidate['source'] ?? $candidate['source_name'] ?? 'Unknown'));

    $shortlisted = $shortlistAt !== null || $stageLc !== 'applied' || $invite !== null;
    $push('shortlisted', 'Shortlisted', $shortlisted ? 'done' : 'pending', $fmt($shortlistAt ?? $stageAt['screening'] ?? null),
        $shortlistAt ? 'Added to job wishlist' : ($shortlisted ? 'Moved forward in pipeline' : ''));

    $invited = $inviteAt !== null;
    $push('invited', 'Interview invite sent', $invited ? 'done' : 'pending', $fmt($inviteAt), $invited ? 'Booking link emailed' : '');

    $bookedState = 'pending';
    $bookedDetail = '';
    $bookedAt = null;
    if ($invite && ($invite['status'] ?? '') === 'confirmed') {
        $bookedState = 'done';
        $bookedAt = $fmt($invite['starts_at'] ?? null);
        $bookedDetail = 'Candidate picked ' . $fmtTime($invite['starts_at'] ?? null);
    } elseif ($invite && ($invite['status'] ?? '') === 'awaiting_confirmation') {
        $expired = !empty($invite['expires_at']) && strtotime($invite['expires_at']) < time();
        $bookedState = $expired ? 'failed' : 'pending';
        $bookedDetail = $expired ? 'Booking link expired ' . $fmt($invite['expires_at']) : 'Waiting for candidate · link valid until ' . $fmt($invite['expires_at']);
    } elseif ($invited && (in_array($stageLc, $laterStages, true) || $outcome)) {
        $bookedState = 'done';
        $bookedDetail = 'Interview arranged';
    }
    $push('booked', 'Slot booked', $bookedState, $bookedAt, $bookedDetail);

    $intState = 'pending';
    $intDetail = '';
    if ($outcome === 'no_show') {
        $intState = 'failed';
        $intDetail = 'Candidate did not attend';
    } elseif (in_array($outcome, $interviewedOutcomes, true) || in_array($stageLc, $laterStages, true)) {
        $intState = 'done';
        $intDetail = $noteCount ? $noteCount . ' evaluation note' . ($noteCount === 1 ? '' : 's') : 'Outcome recorded';
    }
    $push('interviewed', 'Interviewed', $intState, $fmt($invite['outcome_at'] ?? $stageAt['round 2'] ?? null), $intDetail);

    $decState = 'pending';
    $decDetail = '';
    $decAt = null;
    if ($isRejected) {
        $decState = 'failed';
        $decDetail = $blacklisted ? 'Blacklisted' : 'Rejected';
        $decAt = $fmt($rejectedAt);
    } elseif (in_array($outcome, $selectedOutcomes, true) || $stageLc === 'offer') {
        $decState = 'done';
        $decDetail = 'Selected';
        $decAt = $fmt($stageAt['offer'] ?? $invite['outcome_at'] ?? null);
    } elseif ($stageLc === 'round 2') {
        $decDetail = 'Round 2 in progress';
    }
    $push('decision', 'Decision', $decState, $decAt, $decDetail);

    $offerSentAt = $stageMail['offer'] ?? null;
    $offerState = 'pending';
    $offerDetail = '';
    if ($outcome === 'offer_declined') {
        $offerState = 'failed';
        $offerDetail = 'Offer declined';
    } elseif ($outcome === 'offer_accepted' || $outcome === 'hired') {
        $offerState = 'done';
        $offerDetail = 'Offer accepted';
    } elseif ($offerSentAt || $outcome === 'offer_sent') {
        $offerState = 'done';
        $offerDetail = 'Offer email sent · awaiting acceptance';
    }
    $push('offer', 'Offer', $offerState, $fmt($offerSentAt), $offerDetail);

    $push('joined', 'Joined', $outcome === 'hired' ? 'done' : 'pending', null, $outcome === 'hired' ? 'Marked hired' : '');

    // After a failure, later steps can no longer happen.
    $closed = false;
    foreach ($steps as &$step) {
        if ($closed && $step['state'] === 'pending') $step['state'] = 'skipped';
        if ($step['state'] === 'failed') $closed = true;
    }
    unset($step);
    // While the journey is open, the first pending step is the current one.
    $currentKey = null;
    if (!$closed) {
        foreach ($steps as &$step) {
            if ($step['state'] === 'pending') {
                $step['state'] = 'current';
                $currentKey = $step['key'];
                break;
            }
        }
        unset($step);
    }
    // A final rejection/blacklist outranks earlier failures (e.g. a no-show that was then rejected).
    $failed = null;
    foreach ($steps as $step) {
        if ($step['state'] !== 'failed') continue;
        if ($step['key'] === 'decision') { $failed = $step; break; }
        $failed ??= $step;
    }

    $next = match (true) {
        ($failed['key'] ?? '') === 'decision' => ['text' => 'Closed — ' . strtolower($failed['detail']) . ($failed['at'] ? ' on ' . $failed['at'] : '') . '.', 'url' => null, 'tone' => 'muted'],
        ($failed['key'] ?? '') === 'booked' => ['text' => 'The booking link expired without a slot. Send a new interview invitation with a fresh window.', 'url' => 'interview_invite.php', 'tone' => 'warn'],
        ($failed['key'] ?? '') === 'interviewed' => ['text' => 'Candidate missed the interview. Re-invite, or move to Rejected.', 'url' => 'interview_invite.php', 'tone' => 'warn'],
        ($failed['key'] ?? '') === 'offer' => ['text' => 'Offer was declined. Consider the next shortlisted candidate.', 'url' => 'wishlist.php', 'tone' => 'muted'],
        $currentKey === 'shortlisted' => ['text' => 'Review the profile and shortlist the candidate for this job (Candidates → Save favourites).', 'url' => 'candidates.php', 'tone' => 'info'],
        $currentKey === 'invited' => ['text' => 'Send an interview invitation so the candidate can pick a slot.', 'url' => 'interview_invite.php', 'tone' => 'info'],
        $currentKey === 'booked' => ['text' => 'Waiting for the candidate to book a slot. ' . $bookedDetail . '.', 'url' => 'interview_calendar.php', 'tone' => 'info'],
        $currentKey === 'interviewed' => ['text' => 'After the interview, record the outcome and add evaluation notes.', 'url' => 'interview_calendar.php', 'tone' => 'info'],
        $currentKey === 'decision' => ['text' => 'Decide: move to Offer to select, or to Rejected. A stage email preview opens before anything is sent.', 'url' => null, 'tone' => 'info'],
        $currentKey === 'offer' => ['text' => 'Move the candidate to Offer to preview and send the offer email.', 'url' => null, 'tone' => 'info'],
        $currentKey === 'joined' => ['text' => 'Offer is out. Confirm joining once the candidate accepts.', 'url' => null, 'tone' => 'info'],
        default => ['text' => 'Hiring journey complete.', 'url' => null, 'tone' => 'success'],
    };

    // Lets the drawer record an interview outcome in place (handled by interview_outcome.php).
    $interview = $invite ? [
        'invitation_id' => (int)($invite['id'] ?? 0),
        'status' => (string)($invite['status'] ?? ''),
        'outcome' => $outcome,
        'starts_at' => $invite['starts_at'] ?? null,
    ] : null;

    return ['steps' => $steps, 'next' => $next, 'interview' => $interview];
}