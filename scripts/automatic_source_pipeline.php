<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only.');}
require_once __DIR__.'/../lib/integrations.php';

$lockPath=__DIR__.'/../tmp/automatic-source-pipeline.lock';
if(!is_dir(dirname($lockPath)))mkdir(dirname($lockPath),0770,true);
$lock=fopen($lockPath,'c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){echo "status=already_running\n";exit;}

function autoSyncTitle(string $title): string{
    $title=mb_strtolower(trim(preg_replace('/\s+/u',' ',$title)));
    return preg_replace('/^sr\.?\s+/u','senior ',$title);
}
function autoSyncResumeUrl(?string $file): ?string{
    $file=trim((string)$file);if($file==='')return null;
    if(filter_var($file,FILTER_VALIDATE_URL))return strtolower((string)parse_url($file,PHP_URL_SCHEME))==='https'?$file:null;
    $name=basename(str_replace('\\','/',$file));return $name===''?null:'https://nonceblox.com/uploads/'.rawurlencode($name);
}

try{
    $config=integrationConfig('nonceblox_mysql');
    if(($config['status']??'')!=='configured')throw new RuntimeException('NonceBlox source is not configured.');
    $remote=new PDO('mysql:host='.$config['host'].';port='.($config['port']?:3306).';dbname='.$config['database'].';charset=utf8mb4',$config['username'],integrationSecret($config,'password'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_TIMEOUT=>8]);
    $local=db();$local->beginTransaction();
    $jobs=$remote->query('SELECT id,title,`desc`,location FROM career ORDER BY id')->fetchAll();
    $upsertJob=$local->prepare('INSERT INTO jobs(external_id,title,description,location) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),location=VALUES(location)');
    $findJob=$local->prepare('SELECT id FROM jobs WHERE external_id=? LIMIT 1');$jobMap=[];$titleMap=[];
    foreach($jobs as $job){$upsertJob->execute([(int)$job['id'],trim((string)$job['title']),(string)$job['desc'],$job['location']?:null]);$findJob->execute([(int)$job['id']]);$localId=(int)$findJob->fetchColumn();$jobMap[(int)$job['id']]=$localId;$titleMap[autoSyncTitle((string)$job['title'])]=$localId;}
    $hasCareerId=(bool)$remote->query("SHOW COLUMNS FROM career_request LIKE 'career_id'")->fetch();
    $fields=$hasCareerId?'id,career_id,fname,lname,email,phone,job_position,`date`,file,country':'id,fname,lname,email,phone,job_position,`date`,file,country';
    $applicants=$remote->query("SELECT $fields FROM career_request ORDER BY id")->fetchAll();
    $save=$local->prepare("INSERT INTO candidates(id,job_id,name,role_title,email,phone,country_name,score,stage,source_name,skills_json,experience_json,analysis_json,source_url,stored_file,created_at) VALUES(?,?,?,?,?,?,?,0,'Applied','NonceBlox website','[]','{}','{}',?,?,?) ON DUPLICATE KEY UPDATE job_id=COALESCE(VALUES(job_id),job_id),name=VALUES(name),role_title=VALUES(role_title),email=VALUES(email),phone=VALUES(phone),country_name=VALUES(country_name),score=IF(COALESCE(source_url,'')<>COALESCE(VALUES(source_url),''),0,score),skills_json=IF(COALESCE(source_url,'')<>COALESCE(VALUES(source_url),''),JSON_ARRAY(),skills_json),experience_json=IF(COALESCE(source_url,'')<>COALESCE(VALUES(source_url),''),JSON_OBJECT(),experience_json),analysis_json=IF(COALESCE(source_url,'')<>COALESCE(VALUES(source_url),''),JSON_OBJECT(),analysis_json),stored_file=IF(COALESCE(source_url,'')<>COALESCE(VALUES(source_url),''),VALUES(stored_file),stored_file),source_url=VALUES(source_url)");
    foreach($applicants as $row){$id='WEB-'.substr(hash('sha256','nonceblox-career-'.$row['id']),0,20);$title=(string)($row['job_position']??'');$jobId=($hasCareerId&&!empty($row['career_id'])?($jobMap[(int)$row['career_id']]??null):null)??($titleMap[autoSyncTitle($title)]??null);$url=autoSyncResumeUrl($row['file']??null);$created=date('Y-m-d H:i:s',strtotime($row['date']?:'now'));$save->execute([$id,$jobId,trim(($row['fname']??'').' '.($row['lname']??''))?:'Website applicant',$title?:'Candidate',$row['email']?:null,$row['phone']?:null,$row['country']?:null,$url,$url,$created]);}
    $actorId=$local->query('SELECT MIN(id) FROM users')->fetchColumn()?:null;$local->prepare('INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,?,?,?,?)')->execute([$actorId,'source_sync_automatic_completed','integration','nonceblox_mysql',json_encode(['jobs'=>count($jobs),'applicants'=>count($applicants),'outcome'=>'success'])]);$local->commit();
    echo 'jobs_synced='.count($jobs).PHP_EOL.'applicants_synced='.count($applicants).PHP_EOL;
    require __DIR__.'/analyze_downloaded_resumes.php';
}catch(Throwable $e){if(isset($local)&&$local instanceof PDO&&$local->inTransaction())$local->rollBack();fwrite(STDERR,"status=failed\nmessage=".$e->getMessage()."\n");exit(1);}finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
