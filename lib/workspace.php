<?php
require_once __DIR__ . '/../config/database.php';
function workspaceData(string $file, array $default = []): array {
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) mkdir($dir, 0770, true);
    $path = $dir . '/' . $file;
    if (!is_file($path)) return $default;
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : $default;
}
function saveWorkspaceData(string $file, array $data): void {
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) mkdir($dir, 0770, true);
    file_put_contents($dir . '/' . $file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}
function analyzeExperienceTimeline(string $text, array $skills): array {
    $months = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
    $pattern = '/\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s*[\/.\- ]\s*(20\d{2}|19\d{2})\s*(?:-|–|—|to)\s*(?:(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s*[\/.\- ]\s*(20\d{2}|19\d{2})|Present|Current|Ongoing)/i';
    preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
    $jobs=[]; $now=new DateTimeImmutable('first day of this month');
    foreach ($matches[0] as $i=>$full) {
        $sm=$months[strtolower(substr($matches[1][$i][0],0,3))]??1; $sy=(int)$matches[2][$i][0];
        $start=(new DateTimeImmutable())->setDate($sy,$sm,1)->setTime(0,0);
        if (!empty($matches[4][$i][0])) { $em=$months[strtolower(substr($matches[3][$i][0],0,3))]??1; $ey=(int)$matches[4][$i][0]; $end=(new DateTimeImmutable())->setDate($ey,$em,1)->modify('last day of this month')->setTime(0,0); } else $end=$now;
        if ($end < $start) continue;
        $next=$matches[0][$i+1][1]??min(strlen($text),$full[1]+900); $block=substr($text,$full[1],max(0,$next-$full[1]));
        $duration=max(1,($end->format('Y')-$start->format('Y'))*12+(int)$end->format('n')-(int)$start->format('n')+1);
        $used=[]; foreach($skills as $skill) if(stripos($block,$skill)!==false) $used[]=$skill;
        $jobs[]=['label'=>trim(preg_replace('/\s+/',' ',substr($block,0,120))),'start'=>$start->format('Y-m'),'end'=>$end===$now?'Present':$end->format('Y-m'),'start_ts'=>$start->getTimestamp(),'end_ts'=>$end->getTimestamp(),'months'=>$duration,'skills'=>$used];
    }
    usort($jobs,fn($a,$b)=>$a['start_ts']<=>$b['start_ts']);
    $gaps=[]; $merged=[];
    foreach($jobs as $job){ if(!$merged||$job['start_ts']>$merged[count($merged)-1][1]+2678400){$merged[]=[$job['start_ts'],$job['end_ts']];}else{$merged[count($merged)-1][1]=max($merged[count($merged)-1][1],$job['end_ts']);} }
    for($i=1;$i<count($merged);$i++){ $monthsGap=(int)round(($merged[$i][0]-$merged[$i-1][1])/2629800)-1; if($monthsGap>=2)$gaps[]=['months'=>$monthsGap,'after'=>date('Y-m',$merged[$i-1][1]),'before'=>date('Y-m',$merged[$i][0])]; }
    $totalMonths=0; foreach($merged as $range)$totalMonths+=(int)round(($range[1]-$range[0])/2629800)+1;
    $skillRanges=[]; foreach($jobs as $job)foreach($job['skills'] as $skill)$skillRanges[$skill][]=[$job['start_ts'],$job['end_ts']];
    $skillMonths=[]; foreach($skillRanges as $skill=>$ranges){$skillMonths[$skill]=0;usort($ranges,fn($a,$b)=>$a[0]<=>$b[0]);$combined=[];foreach($ranges as $range){if(!$combined||$range[0]>$combined[count($combined)-1][1]+2678400)$combined[]=$range;else $combined[count($combined)-1][1]=max($combined[count($combined)-1][1],$range[1]);}foreach($combined as $range)$skillMonths[$skill]+=(int)round(($range[1]-$range[0])/2629800)+1;}
    arsort($skillMonths);
    return ['jobs'=>$jobs,'gaps'=>$gaps,'total_months'=>$totalMonths,'skill_months'=>$skillMonths,'confidence'=>count($jobs)?'estimated_from_dated_roles':'insufficient_dated_roles'];
}
function saveCandidateRecord(array $result): void {
    $items=workspaceData('candidates.json',[]);
    $name='Unknown candidate'; foreach(preg_split('/\R/',trim($result['extracted_text'])) as $line){$line=trim($line);if(strlen($line)>2){$name=mb_substr($line,0,80);break;}}
    $record=['id'=>'CAN-'.substr(md5($result['stored_filename']),0,8),'name'=>$name,'role'=>'Candidate','email'=>$result['email'],'phone'=>$result['phone'],'score'=>$result['match_score'],'skills'=>$result['detected_skills'],'stage'=>'Applied','source'=>'Direct upload','created_at'=>$result['created_at'],'file'=>$result['stored_filename'],'experience'=>$result['experience'],'analysis'=>['matched_keywords'=>$result['matched_keywords']??[],'missing_keywords'=>$result['missing_keywords']??[],'jd_keywords'=>$result['jd_keywords']??[]]];
    $items=array_values(array_filter($items,fn($item)=>($item['id']??'')!==$record['id'])); $items[]=$record; saveWorkspaceData('candidates.json',$items);
    $sql='INSERT INTO candidates(id,name,role_title,email,phone,score,stage,source_name,skills_json,experience_json,analysis_json,stored_file,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),role_title=VALUES(role_title),email=VALUES(email),phone=VALUES(phone),score=VALUES(score),skills_json=VALUES(skills_json),experience_json=VALUES(experience_json),analysis_json=VALUES(analysis_json),stored_file=VALUES(stored_file)';
    db()->prepare($sql)->execute([$record['id'],$record['name'],$record['role'],$record['email'],$record['phone'],$record['score'],$record['stage'],$record['source'],json_encode($record['skills']),json_encode($record['experience']),json_encode($record['analysis']),$record['file'],$record['created_at']]);
}
function loadCandidateRecords(): array {
    try{$rows=db()->query('SELECT * FROM candidates ORDER BY created_at DESC')->fetchAll();if($rows)return array_map(function($r){return ['id'=>$r['id'],'name'=>$r['name'],'role'=>$r['role_title'],'email'=>$r['email'],'phone'=>$r['phone'],'score'=>(int)$r['score'],'stage'=>$r['stage'],'source'=>$r['source_name'],'skills'=>json_decode($r['skills_json']??'[]',true)?:[],'experience'=>json_decode($r['experience_json']??'{}',true)?:[],'analysis'=>json_decode($r['analysis_json']??'{}',true)?:[],'file'=>$r['stored_file'],'created_at'=>$r['created_at']];},$rows);}catch(Throwable $e){}
    return workspaceData('candidates.json',[]);
}
function candidatePresentation(array $candidate): array {
    $raw=trim((string)($candidate['name']??'Unknown candidate'));
    $clean=preg_replace('/[^\p{L}\p{N}\s@.+\-|():]/u',' ',$raw)??$raw;
    $clean=trim(preg_replace('/\s+/u',' ',$clean));
    $name=$clean; $role=(string)($candidate['role']??'Candidate');
    if(str_contains($clean,'Email:'))$clean=trim(explode('Email:',$clean,2)[0]);
    if(preg_match('/^(.+?)\s+-\s+(.+)$/u',$clean,$m)){ $name=trim($m[1]); $role=trim($m[2]); }
    elseif(preg_match('/^([A-Z][A-Z ]{3,}?)(?=\s+(?:Senior|Junior|Lead|PHP|Frontend|Backend|Software|Full Stack|Web|UI|UX)\b)(.*)$/u',$clean,$m)){ $name=ucwords(strtolower(trim($m[1]))); $role=trim($m[2]); }
    else $name=$clean;
    $role=preg_replace('/\s+(?:support|contact|email|phone)@?.*$/iu','',$role)??$role;
    $name=mb_substr(trim($name),0,60); $role=mb_substr(trim($role),0,60);
    return ['name'=>$name?:'Unknown candidate','role'=>$role?:'Candidate'];
}
