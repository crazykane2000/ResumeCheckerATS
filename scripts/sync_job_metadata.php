<?php
require_once __DIR__.'/../lib/integrations.php';
$config=integrationConfig('nonceblox_mysql');if(($config['status']??'')!=='configured')throw new RuntimeException('NonceBlox source is not configured.');
$remote=new PDO('mysql:host='.$config['host'].';port='.($config['port']?:3306).';dbname='.$config['database'].';charset=utf8mb4',$config['username'],integrationSecret($config,'password'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_TIMEOUT=>8]);$local=db();
$update=$local->prepare('UPDATE jobs SET external_id=?,public_url=?,location=? WHERE LOWER(TRIM(title))=LOWER(TRIM(?))');$count=0;
foreach($remote->query('SELECT id,title,location FROM career') as $job){$slug=preg_replace('/[^A-Za-z0-9]+/','_',trim($job['title']));$url='https://nonceblox.com/career-inner.php?'.rawurlencode($slug).'='.rawurlencode(base64_encode((string)$job['id']));$update->execute([$job['id'],$url,$job['location']?:null,$job['title']]);$count+=$update->rowCount();}
echo "job_metadata_updated=$count\n";
