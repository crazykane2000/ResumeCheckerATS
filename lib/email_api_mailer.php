<?php
function emailApiSendHtml(array $config,array $recipients,string $subject,string $html): void {
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL is not available.');
    $url=trim((string)($config['url']??''));
    if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https')throw new RuntimeException('Email API configuration is invalid.');
    $host=strtolower((string)parse_url($url,PHP_URL_HOST));
    if($host!=='hrms-api.nonceblox.com')throw new RuntimeException('Email API host is not approved.');
    $emails=array_values(array_unique(array_filter(array_map('trim',$recipients),fn($email)=>filter_var($email,FILTER_VALIDATE_EMAIL))));
    if(count($emails)!==count($recipients)||!$emails)throw new RuntimeException('Email API recipients are invalid.');
    $headers=['Content-Type: application/json','Accept: application/json'];$token=integrationSecret($config,'api_key');if($token!=='')$headers[]='Authorization: Bearer '.$token;
    $curl=curl_init();curl_setopt_array($curl,[CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_ENCODING=>'',CURLOPT_MAXREDIRS=>3,CURLOPT_TIMEOUT=>30,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_POSTFIELDS=>json_encode(['to'=>$emails,'subject'=>$subject,'body'=>$html],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>$headers]);
    $response=curl_exec($curl);$error=curl_error($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    if($response===false||$error!==''||$status<200||$status>=300)throw new RuntimeException('Email API delivery failed.');
    $decoded=json_decode((string)$response,true);if(is_array($decoded)&&array_key_exists('success',$decoded)&&!$decoded['success'])throw new RuntimeException('Email API rejected the message.');
}
