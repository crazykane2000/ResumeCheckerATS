<?php
define('RESUMEIQ_FUNCTIONS_ONLY',true);
$_SERVER['REQUEST_METHOD']='GET';
require_once __DIR__.'/../index.php';
require_once __DIR__.'/../lib/resume_storage.php';

$pdo=db();
$actorId=(int)($pdo->query('SELECT MIN(id) FROM users')->fetchColumn()?:0)?:null;
$jobs=[];
foreach($pdo->query('SELECT id,description FROM jobs') as $job)$jobs[(int)$job['id']]=$job['description'];
$all=in_array('--all',$argv??[],true);
$sql="SELECT * FROM candidates WHERE source_name='NonceBlox website'".($all?'':" AND COALESCE(skills_json,'[]')='[]'").' ORDER BY id';
$rows=$pdo->query($sql)->fetchAll();
$update=$pdo->prepare('UPDATE candidates SET score=?,skills_json=?,experience_json=?,analysis_json=? WHERE id=?');
$audit=$pdo->prepare('INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,?,?,?,?)');
$processed=0;$failed=0;$ocr=0;$cached=0;

foreach($rows as $index=>$row){
    $before=trim((string)($row['stored_file']??''));
    $path=resolveCandidateResumePath($row,$actorId);
    if(!$path){$audit->execute([$actorId,'candidate.resume_analysis_failed','candidate',$row['id'],json_encode(['reason'=>'resume_unavailable','script'=>'analyze_downloaded_resumes','outcome'=>'failed'])]);$failed++;continue;}
    if(!str_starts_with($before,'nonceblox/'))$cached++;
    $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
    try{
        $extraction=extractResumeText($path,$ext);$text=$extraction['text']??'';
        if(($extraction['requires_ocr']??false))$ocr++;
        $skills=extractDetectedSkills($text);
        $experience=analyzeExperienceTimeline($text,$skills);
        $jd=$jobs[(int)($row['job_id']??0)]??'';
        [$score,$matched,$missing,$tokens]=basicMatch($jd,$text);
        $status=($extraction['requires_ocr']??false)?'requires_ocr':(trim($text)===''?'no_usable_text':'processed');
        $analysis=['matched_keywords'=>$matched,'missing_keywords'=>$missing,'jd_keywords'=>$tokens,'processing_status'=>$status,'text_length'=>mb_strlen($text),'raw_text'=>$text,'source_url'=>$row['source_url'],'parser_warning'=>$extraction['error']??null];
        $update->execute([$score,json_encode($skills),json_encode($experience),json_encode($analysis),$row['id']]);
        $audit->execute([$actorId,'candidate.resume_analyzed','candidate',$row['id'],json_encode(['skills'=>count($skills),'timeline_jobs'=>count($experience['jobs']??[]),'requires_ocr'=>(bool)($extraction['requires_ocr']??false),'processing_status'=>$status,'script'=>'analyze_downloaded_resumes','outcome'=>'success'])]);
        $processed++;
    }catch(Throwable $e){$audit->execute([$actorId,'candidate.resume_analysis_failed','candidate',$row['id'],json_encode(['reason'=>'parser_error','script'=>'analyze_downloaded_resumes','outcome'=>'failed'])]);$failed++;}
    if((($index+1)%10)===0)echo 'progress='.($index+1).'/'.count($rows).' processed='.$processed.' failed='.$failed.PHP_EOL;
}

echo "processed=$processed\nfailed=$failed\nocr_required=$ocr\ncached=$cached\ntotal=".count($rows)."\n";