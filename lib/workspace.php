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
    $monthsMap = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
    
    // Isolate WORK EXPERIENCE section if present to avoid capturing education/projects/achievements dates
    $targetText = $text;
    if (preg_match('/(?:WORK EXPERIENCE|EMPLOYMENT HISTORY|PROFESSIONAL EXPERIENCE|EXPERIENCE)\b/i', $text, $secMatch, PREG_OFFSET_CAPTURE)) {
        $startPos = $secMatch[0][1];
        $subText = substr($text, $startPos);
        if (preg_match('/\n\s*(?:EDUCATION|SKILLS|PERSONAL PROJECTS|PROJECTS|ACHIEVEMENTS|LANGUAGES|CERTIFICATIONS|INTERESTS|DECLARATION)\b/i', $subText, $endMatch, PREG_OFFSET_CAPTURE)) {
            $targetText = substr($subText, 0, $endMatch[0][1]);
        } else {
            $targetText = $subText;
        }
    }

    $pattern = '/\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?|0?[1-9]|1[0-2])\s*[\/.\- ]\s*(20\d{2}|19\d{2})\s*(?:-|–|—|to)\s*(?:(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?|0?[1-9]|1[0-2])\s*[\/.\- ]\s*(20\d{2}|19\d{2})|Present|Current|Ongoing|Till Date)/i';
    
    preg_match_all($pattern, $targetText, $matches, PREG_OFFSET_CAPTURE);
    $jobs = [];
    $now = new DateTimeImmutable('first day of this month');

    foreach ($matches[0] as $i => $full) {
        $m1Str = $matches[1][$i][0];
        $sy = (int)$matches[2][$i][0];
        $sm = is_numeric($m1Str) ? (int)$m1Str : ($monthsMap[strtolower(substr($m1Str,0,3))] ?? 1);
        $start = (new DateTimeImmutable())->setDate($sy, max(1, min(12, $sm)), 1)->setTime(0,0);

        if (!empty($matches[4][$i][0])) {
            $m2Str = $matches[3][$i][0];
            $ey = (int)$matches[4][$i][0];
            $em = is_numeric($m2Str) ? (int)$m2Str : ($monthsMap[strtolower(substr($m2Str,0,3))] ?? 1);
            $end = (new DateTimeImmutable())->setDate($ey, max(1, min(12, $em)), 1)->modify('last day of this month')->setTime(0,0);
        } else {
            $end = $now;
        }

        if ($end < $start) continue;

        // Clean label extraction from preceding lines
        $beforeMatchText = substr($targetText, 0, $full[1]);
        $precedingLines = array_values(array_filter(array_map('trim', preg_split('/\R/', $beforeMatchText))));
        $label = '';
        if (count($precedingLines) >= 2) {
            $l1 = $precedingLines[count($precedingLines)-2];
            $l2 = $precedingLines[count($precedingLines)-1];
            if (!preg_match('/WORK EXPERIENCE|EMPLOYMENT|EDUCATION|SKILLS|PROJECTS|ACHIEVEMENTS/i', $l1) && mb_strlen($l1) < 80) {
                $label = $l1;
            }
            if (!preg_match('/WORK EXPERIENCE|EMPLOYMENT|EDUCATION|SKILLS|PROJECTS|ACHIEVEMENTS/i', $l2) && mb_strlen($l2) < 80) {
                $label = $label ? ($label . ' — ' . $l2) : $l2;
            }
        } elseif (count($precedingLines) === 1) {
            $l0 = $precedingLines[0];
            if (!preg_match('/WORK EXPERIENCE|EMPLOYMENT|EDUCATION|SKILLS|PROJECTS|ACHIEVEMENTS/i', $l0) && mb_strlen($l0) < 80) {
                $label = $l0;
            }
        }

        if (empty($label)) {
            $label = 'Work Experience Role (' . $start->format('Y-m') . ')';
        }

        $next = $matches[0][$i+1][1] ?? min(strlen($targetText), $full[1]+600);
        $block = substr($targetText, $full[1], max(0, $next - $full[1]));
        $duration = max(1, ($end->format('Y') - $start->format('Y')) * 12 + (int)$end->format('n') - (int)$start->format('n') + 1);

        $used = [];
        foreach ($skills as $skill) {
            if (trim($skill) !== '' && stripos($block, $skill) !== false) {
                $used[] = $skill;
            }
        }

        $jobs[] = [
            'label' => $label,
            'start' => $start->format('Y-m'),
            'end'   => $end === $now ? 'Present' : $end->format('Y-m'),
            'start_ts' => $start->getTimestamp(),
            'end_ts'   => $end->getTimestamp(),
            'months'   => $duration,
            'skills'   => array_values(array_unique($used))
        ];
    }

    usort($jobs, fn($a, $b) => $a['start_ts'] <=> $b['start_ts']);
    $gaps = [];
    $merged = [];
    foreach ($jobs as $job) {
        if (!$merged || $job['start_ts'] > $merged[count($merged)-1][1] + 2678400) {
            $merged[] = [$job['start_ts'], $job['end_ts']];
        } else {
            $merged[count($merged)-1][1] = max($merged[count($merged)-1][1], $job['end_ts']);
        }
    }

    for ($i = 1; $i < count($merged); $i++) {
        $monthsGap = (int)round(($merged[$i][0] - $merged[$i-1][1]) / 2629800) - 1;
        if ($monthsGap >= 2) {
            $gaps[] = ['months' => $monthsGap, 'after' => date('Y-m', $merged[$i-1][1]), 'before' => date('Y-m', $merged[$i][0])];
        }
    }

    $totalMonths = 0;
    foreach ($merged as $range) {
        $totalMonths += (int)round(($range[1] - $range[0]) / 2629800) + 1;
    }

    $skillRanges = [];
    foreach ($jobs as $job) {
        foreach ($job['skills'] as $skill) {
            $skillRanges[$skill][] = [$job['start_ts'], $job['end_ts']];
        }
    }

    $skillMonths = [];
    foreach ($skillRanges as $skill => $ranges) {
        $skillMonths[$skill] = 0;
        usort($ranges, fn($a, $b) => $a[0] <=> $b[0]);
        $combined = [];
        foreach ($ranges as $range) {
            if (!$combined || $range[0] > $combined[count($combined)-1][1] + 2678400) {
                $combined[] = $range;
            } else {
                $combined[count($combined)-1][1] = max($combined[count($combined)-1][1], $range[1]);
            }
        }
        foreach ($combined as $range) {
            $skillMonths[$skill] += (int)round(($range[1] - $range[0]) / 2629800) + 1;
        }
    }
    arsort($skillMonths);

    return [
        'jobs' => $jobs,
        'gaps' => $gaps,
        'total_months' => $totalMonths,
        'skill_months' => $skillMonths,
        'confidence' => count($jobs) ? 'estimated_from_dated_roles' : 'insufficient_dated_roles'
    ];
}
function saveCandidateRecord(array $result, ?int $jobId = null): void {
    $items=workspaceData('candidates.json',[]);
    $name = !empty($result['name']) && $result['name'] !== 'Unknown candidate' ? $result['name'] : 'Unknown candidate';
    if ($name === 'Unknown candidate') {
        foreach(preg_split('/\R/',trim($result['extracted_text'])) as $line){
            $line=trim($line);
            if(strlen($line)>2 && !preg_match('/@|http|\+?\d{8,}/',$line)){
                $name=mb_substr($line,0,60);
                break;
            }
        }
    }
    $role = !empty($result['role']) ? $result['role'] : 'Candidate';
    $cCode = !empty($result['country_code']) ? strtoupper(trim($result['country_code'])) : '';
    $cName = !empty($result['country_name']) ? trim($result['country_name']) : '';
    $email = trim((string)($result['email'] ?? ''));

    // Auto-match best open workspace job if no specific job_id was selected at upload time
    if (!$jobId) {
        try {
            $openJobs = db()->query("SELECT j.id,j.title,j.required_skills_json,j.preferred_skills_json,j.min_experience FROM jobs j WHERE j.status IN ('open','draft') AND j.required_skills_json IS NOT NULL AND j.required_skills_json != '' AND j.required_skills_json != '[]'")->fetchAll();
            $bestScore = -1;
            $bestJobId = null;
            $candTemp = ['skills' => $result['detected_skills'], 'experience' => $result['experience'], 'role' => $role];
            foreach ($openJobs as $j) {
                $m = applicationEvidenceMatch($candTemp, $j);
                if (($m['score'] ?? 0) > $bestScore) {
                    $bestScore = (int)$m['score'];
                    $bestJobId = (int)$j['id'];
                }
            }
            if ($bestJobId) {
                $jobId = $bestJobId;
            }
        } catch (Throwable $e) {}
    }

    // Check for existing candidate application by email to avoid duplicate rows
    $existingId = null;
    if ($email !== '') {
        try {
            $checkStmt = db()->prepare("SELECT id FROM candidates WHERE email = ? AND (job_id = ? OR (job_id IS NULL AND ? IS NULL)) ORDER BY created_at DESC LIMIT 1");
            $checkStmt->execute([$email, $jobId, $jobId]);
            $existingId = $checkStmt->fetchColumn();
        } catch (Throwable $e) {}
    }

    $id = $existingId ?: ('CAN-'.substr(md5($result['stored_filename']), 0, 8));

    $record=['id'=>$id,'job_id'=>$jobId,'name'=>$name,'role'=>$role,'email'=>$result['email'],'phone'=>$result['phone'],'country_code'=>$cCode,'country_name'=>$cName,'score'=>$result['match_score'],'skills'=>$result['detected_skills'],'stage'=>'Applied','source'=>'Direct upload','created_at'=>$result['created_at'],'file'=>$result['stored_filename'],'experience'=>$result['experience'],'analysis'=>['matched_keywords'=>$result['matched_keywords']??[],'missing_keywords'=>$result['missing_keywords']??[],'jd_keywords'=>$result['jd_keywords']??[]]];
    $items=array_values(array_filter($items,fn($item)=>($item['id']??'')!==$record['id'])); $items[]=$record; saveWorkspaceData('candidates.json',$items);
    
    $sql='INSERT INTO candidates(id,job_id,name,role_title,email,phone,country_code,country_name,score,stage,source_name,skills_json,experience_json,analysis_json,stored_file,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE job_id=VALUES(job_id),name=VALUES(name),role_title=VALUES(role_title),email=VALUES(email),phone=VALUES(phone),country_code=VALUES(country_code),country_name=VALUES(country_name),score=VALUES(score),skills_json=VALUES(skills_json),experience_json=VALUES(experience_json),analysis_json=VALUES(analysis_json),stored_file=VALUES(stored_file)';
    db()->prepare($sql)->execute([$record['id'],$record['job_id'],$record['name'],$record['role'],$record['email'],$record['phone'],$record['country_code'],$record['country_name'],$record['score'],$record['stage'],$record['source'],json_encode($record['skills']),json_encode($record['experience']),json_encode($record['analysis']),$record['file'],$record['created_at']]);
}
function countryCodeFromName(?string $code, ?string $name): string {
    if(trim((string)$code)!=='')return strtoupper(trim((string)$code));
    $map=['india'=>'IN','kenya'=>'KE','ireland'=>'IE','germany'=>'DE','united states'=>'US','usa'=>'US','united kingdom'=>'GB','uk'=>'GB','canada'=>'CA','australia'=>'AU','united arab emirates'=>'AE','uae'=>'AE','philippines'=>'PH','honduras'=>'HN','spain'=>'ES','egypt'=>'EG','indonesia'=>'ID'];
    return $map[mb_strtolower(trim((string)$name))]??'';
}
function normalizeCandidateSource(?string $source): string {
    $s = trim((string)$source);
    if ($s === '' || strcasecmp($s, 'direct_upload') === 0 || strcasecmp($s, 'Direct upload') === 0) {
        return 'Direct upload';
    }
    return $s;
}
function loadCandidateRecords(): array {
    try{
        $rows=db()->query('SELECT c.*,j.title job_title,j.description job_description,j.min_experience job_min_experience,j.max_experience job_max_experience,j.required_skills_json job_required_skills_json,j.preferred_skills_json job_preferred_skills_json FROM candidates c LEFT JOIN jobs j ON j.id=c.job_id ORDER BY c.created_at DESC')->fetchAll();
        $openJobsCache=null;
        if($rows)return array_map(function($r) use (&$openJobsCache){
            $candidate=['id'=>$r['id'],'job_id'=>$r['job_id']??null,'name'=>$r['name'],'role'=>$r['role_title'],'email'=>$r['email'],'phone'=>$r['phone'],'country_code'=>countryCodeFromName($r['country_code'],$r['country_name']),'country_name'=>$r['country_name'],'legacy_score'=>(int)$r['score'],'stage'=>$r['stage'],'source'=>normalizeCandidateSource($r['source_name']??''),'skills'=>json_decode($r['skills_json']??'[]',true)?:[],'experience'=>json_decode($r['experience_json']??'{}',true)?:[],'analysis'=>json_decode($r['analysis_json']??'{}',true)?:[],'file'=>$r['stored_file'],'source_url'=>$r['source_url']??null,'created_at'=>$r['created_at']];
            $job=$r['job_id']?['id'=>(int)$r['job_id'],'title'=>$r['job_title'],'description'=>$r['job_description'],'min_experience'=>$r['job_min_experience'],'max_experience'=>$r['job_max_experience'],'required_skills_json'=>$r['job_required_skills_json'],'preferred_skills_json'=>$r['job_preferred_skills_json']]:null;
            if(!$job && !empty($candidate['skills'])){
                if($openJobsCache===null){
                    $openJobsCache=db()->query("SELECT j.id,j.title,j.description,j.min_experience,j.max_experience,j.required_skills_json,j.preferred_skills_json FROM jobs j WHERE j.status IN ('open','draft') AND j.required_skills_json IS NOT NULL AND j.required_skills_json != '' AND j.required_skills_json != '[]'")->fetchAll();
                }
                $bestScore=-1;$bestJob=null;
                foreach($openJobsCache as $j){
                    $m=applicationEvidenceMatch($candidate,$j);
                    if(($m['score']??0)>$bestScore){$bestScore=(int)$m['score'];$bestJob=$j;}
                }
                if($bestJob)$job=$bestJob;
            }
            $candidate['job']=$job;$candidate['job_title']=trim((string)($job['title']??$candidate['role']));$candidate['job_match']=applicationEvidenceMatch($candidate,$job??[]);$candidate['score']=$candidate['job_match']['score'];
            return $candidate;
        },$rows);
    }catch(Throwable $e){}
    return array_map(function(array $candidate): array {
        $candidate['job_id']=$candidate['job_id']??null;
        $candidate['legacy_score']=(int)($candidate['score']??0);
        $candidate['job']=$candidate['job']??null;
        $candidate['source']=normalizeCandidateSource($candidate['source']??'');
        $candidate['job_title']=trim((string)($candidate['job_title']??$candidate['role']??''));
        $candidate['job_match']=applicationEvidenceMatch($candidate,$candidate['job']??[]);
        $candidate['score']=$candidate['job_match']['score'];
        return $candidate;
    },workspaceData('candidates.json',[]));
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

function normaliseSkill(string $skill): string {
    return mb_strtolower(trim(preg_replace('/\s+/u',' ',strip_tags($skill))));
}
require_once __DIR__.'/application_match.php';
function profileSkillMatch(array $candidate, array $requiredSkills): array {
    $job=$candidate['job']??['title'=>$candidate['job_title']??$candidate['role']??'','min_experience'=>0,'preferred_skills_json'=>'[]'];
    $job['required_skills_json']=json_encode(array_values($requiredSkills));
    $match=applicationEvidenceMatch($candidate,$job);
    $match['matched']=array_column(array_filter($match['mapping'],fn($item)=>$item['status']==='experience_backed'),'requirement');
    $match['review']=array_column(array_filter($match['mapping'],fn($item)=>in_array($item['status'],['skills_only','related_review'],true)),'requirement');
    $match['missing']=array_column(array_filter($match['mapping'],fn($item)=>$item['status']==='not_found'),'requirement');
    $match['benefits']=$match['preferred_matched'];
    return $match;
}
