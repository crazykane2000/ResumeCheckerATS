<?php
function resolveCandidateResumePath(array $candidate,?int $actorId=null): ?string {
    $uploads=realpath(__DIR__.'/../uploads');
    if(!$uploads)return null;
    $stored=trim((string)($candidate['stored_file']??$candidate['file']??''));
    if($stored!==''&&!preg_match('#^https?://#i',$stored)){
        $local=realpath($uploads.DIRECTORY_SEPARATOR.str_replace(['\\','/'],DIRECTORY_SEPARATOR,$stored));
        if($local&&is_file($local)&&str_starts_with(strtolower($local),strtolower($uploads.DIRECTORY_SEPARATOR)))return $local;
    }
    $url=trim((string)($candidate['source_url']??''));
    if($url===''&&preg_match('#^https?://#i',$stored))$url=$stored;
    if(!filter_var($url,FILTER_VALIDATE_URL))return null;
    $parts=parse_url($url);
    if(strtolower((string)($parts['scheme']??''))!=='https'||strtolower((string)($parts['host']??''))!=='nonceblox.com'||!str_starts_with((string)($parts['path']??''),'/uploads/'))return null;
    $ext=strtolower(pathinfo((string)$parts['path'],PATHINFO_EXTENSION));
    if(!in_array($ext,['pdf','doc','docx'],true))return null;
    $id=preg_replace('/[^A-Za-z0-9_-]/','',(string)($candidate['id']??''));
    if($id==='')return null;
    $relative='nonceblox/'.$id.'.'.$ext;$dir=$uploads.DIRECTORY_SEPARATOR.'nonceblox';
    if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))return null;
    $target=$uploads.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
    if(is_file($target)&&filesize($target)>0)return $target;
    $temporary=$target.'.part-'.bin2hex(random_bytes(6));$handle=fopen($temporary,'wb');
    if(!$handle)return null;
    $bytes=0;$limit=8*1024*1024;$curl=curl_init($url);
    curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>45,CURLOPT_USERAGENT=>'ResumeIQ/1.0',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>function($curl,string $chunk)use($handle,&$bytes,$limit){$length=strlen($chunk);$bytes+=$length;if($bytes>$limit)return 0;return fwrite($handle,$chunk);}]);
    $success=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);fclose($handle);
    $allowedMime=['application/pdf','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/msword','application/octet-stream'];
    $mime=is_file($temporary)?((new finfo(FILEINFO_MIME_TYPE))->file($temporary)?:''):'';
    if(!$success||$status<200||$status>=300||$bytes<100||$bytes>$limit||!in_array($mime,$allowedMime,true)){if(is_file($temporary))unlink($temporary);return null;}
    if(!rename($temporary,$target)){if(is_file($temporary))unlink($temporary);return null;}
    $pdo=db();$pdo->prepare('UPDATE candidates SET source_url=?,stored_file=? WHERE id=?')->execute([$url,$relative,$candidate['id']]);
    $pdo->prepare('INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,?,?,?,?)')->execute([$actorId,'remote_resume_cached','candidate',$candidate['id'],json_encode(['mime'=>$mime,'bytes'=>$bytes])]);
    return $target;
}