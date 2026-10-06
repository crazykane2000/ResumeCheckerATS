<?php
require_once __DIR__.'/lib/auth.php';
requireAuth();
require_once __DIR__.'/lib/integrations.php';
header('Content-Type: application/json; charset=utf-8');

function failSync(string $message,int $status=422): never {
    http_response_code($status);
    echo json_encode(['ok'=>false,'message'=>$message]);
    exit;
}
function normalizedJobTitle(string $title): string {
    $title=mb_strtolower(trim(preg_replace('/\s+/u',' ',$title)));
    return preg_replace('/^sr\.?\s+/u','senior ',$title);
}
function remoteResumeUrl(?string $file): ?string {
    $file=trim((string)$file);
    if($file==='')return null;
    if(filter_var($file,FILTER_VALIDATE_URL))return in_array(parse_url($file,PHP_URL_SCHEME),['http','https'],true)?$file:null;
    $name=basename(str_replace('\\','/',$file));
    return $name===''?null:'https://nonceblox.com/uploads/'.rawurlencode($name);
}

try {
    if($_SERVER['REQUEST_METHOD']!=='POST')failSync('Method not allowed.',405);
    if(!verifyCsrf($_POST['csrf']??''))failSync('Your session expired. Refresh and try again.',403);
    $syncId=preg_replace('/[^a-zA-Z0-9-]/','',(string)($_POST['sync_id']??''));
    if($syncId==='')failSync('Invalid sync identifier.');
    $offset=max(0,(int)($_POST['offset']??0));$limit=50;
    $config=integrationConfig('nonceblox_mysql');
    if(($config['status']??'')!=='configured')failSync('Configure the NonceBlox database in Integrations first.');
    $remote=new PDO('mysql:host='.$config['host'].';port='.($config['port']?:3306).';dbname='.$config['database'].';charset=utf8mb4',$config['username'],integrationSecret($config,'password'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_TIMEOUT=>8]);
    $local=db();$total=(int)$remote->query('SELECT COUNT(*) FROM career_request')->fetchColumn();
    startAppSession();
    if($offset===0){
        $jobs=$remote->query('SELECT id,title,`desc`,location FROM career ORDER BY id DESC LIMIT 100')->fetchAll();
        $upsertJob=$local->prepare('INSERT INTO jobs(external_id,title,description,location) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),location=VALUES(location)');$findJob=$local->prepare('SELECT id FROM jobs WHERE external_id=? LIMIT 1');$jobMap=[];$titleMap=[];
        foreach($jobs as $job){$upsertJob->execute([(int)$job['id'],trim($job['title']),$job['desc'],$job['location']?:null]);$findJob->execute([(int)$job['id']]);$jobMap[(int)$job['id']]=(int)$findJob->fetchColumn();$titleMap[normalizedJobTitle($job['title'])]=$jobMap[(int)$job['id']];}
        $_SESSION['source_sync_jobs'][$syncId]=count($jobs);$_SESSION['source_sync_job_map'][$syncId]=$jobMap;$_SESSION['source_sync_title_map'][$syncId]=$titleMap;
    }
    $query=$remote->prepare('SELECT id,fname,lname,email,phone,job_position,`date`,file,country FROM career_request ORDER BY id ASC LIMIT :limit OFFSET :offset');
    $query->bindValue(':limit',$limit,PDO::PARAM_INT);$query->bindValue(':offset',$offset,PDO::PARAM_INT);$query->execute();$rows=$query->fetchAll();
    $jobMap=$_SESSION['source_sync_job_map'][$syncId]??[];$titleMap=$_SESSION['source_sync_title_map'][$syncId]??[];$upsert=$local->prepare('INSERT INTO candidates(id,job_id,name,role_title,email,phone,country_name,score,stage,source_name,skills_json,experience_json,analysis_json,source_url,stored_file,created_at) VALUES(?,?,?,?,?,?,?,0,\'Applied\',\'NonceBlox website\',\'[]\',\'{}\',\'{}\',?,?,?) ON DUPLICATE KEY UPDATE job_id=VALUES(job_id),name=VALUES(name),role_title=VALUES(role_title),email=VALUES(email),phone=VALUES(phone),country_name=VALUES(country_name),source_url=VALUES(source_url),stored_file=VALUES(stored_file)');
    foreach($rows as $row){$id='WEB-'.substr(hash('sha256','nonceblox-career-'.$row['id']),0,20);$name=trim($row['fname'].' '.$row['lname'])?:'Website applicant';$created=date('Y-m-d H:i:s',strtotime($row['date']?:'now'));$url=remoteResumeUrl($row['file']??null);$upsert->execute([$id,$jobMap[(int)($row['career_id']??0)]??$titleMap[normalizedJobTitle($row['job_position']??'')]??null,$name,$row['job_position']?:'Candidate',$row['email']?:null,$row['phone']?:null,$row['country']?:null,$url,$url,$created]);}
    $processed=min($total,$offset+count($rows));$complete=$processed>=$total;$jobCount=(int)($_SESSION['source_sync_jobs'][$syncId]??0);
    if($complete&&!($_SESSION['completed_source_syncs'][$syncId]??false)){$meta=['sync_id'=>$syncId,'jobs'=>$jobCount,'applicants'=>$total];$local->prepare('INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,?,?,?,?)')->execute([currentUser()['id']??null,'source_sync_completed','integration','nonceblox_mysql',json_encode($meta)]);$_SESSION['completed_source_syncs'][$syncId]=true;unset($_SESSION['source_sync_jobs'][$syncId],$_SESSION['source_sync_job_map'][$syncId],$_SESSION['source_sync_title_map'][$syncId]);}
    echo json_encode(['ok'=>true,'processed'=>$processed,'total'=>$total,'percent'=>$total?(int)round($processed/$total*100):100,'complete'=>$complete,'jobs'=>$jobCount]);
} catch(Throwable $e){failSync($e instanceof RuntimeException?$e->getMessage():'Source sync failed. Check the remote connection and try again.',500);}